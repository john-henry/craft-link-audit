<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use johnhenry\linkaudit\LinkAudit;
use Psr\Http\Message\RequestInterface;

// ---------------------------------------------------------------------------
// The crawler used to ask Guzzle to stream the response, which reads as the
// careful thing to do and quietly costs the connect timeout: a streamed request
// goes through Guzzle's stream handler, and that handler has no connect timeout
// at all. The Connect Timeout setting was dropped on every page fetched, so a
// host accepting nothing cost the whole request timeout instead of the couple
// of seconds it was set to.
//
// A sink keeps the body out of memory without that: php://temp holds up to the
// cap and spills the rest to a file. The sink is left sitting at the end of
// what was written, so the crawler winds it back before reading; left alone it
// reads empty and every page comes back with no links on it.
// ---------------------------------------------------------------------------

/** The options the crawler actually sends. */
function crawlerOptions(): array
{
    $crawler = LinkAudit::getInstance()->getPageCrawler();

    return (new ReflectionMethod($crawler, '_requestOptions'))->invoke($crawler);
}

it('does not ask Guzzle to stream, which would drop the connect timeout', function() {
    $options = crawlerOptions();

    expect($options)->not->toHaveKey(RequestOptions::STREAM)
        ->and($options)->toHaveKey(RequestOptions::SINK)
        ->and($options[RequestOptions::CONNECT_TIMEOUT])
        ->toBe(LinkAudit::getInstance()->getSettings()->connectTimeout);
});

it('hands every request a sink of its own', function() {
    // One shared sink would have the second page appended to the first, and
    // both read back as one long document.
    $first = crawlerOptions()[RequestOptions::SINK];
    $second = crawlerOptions()[RequestOptions::SINK];

    expect($first)->not->toBe($second);
});

it('reads the body back after the sink has been written to', function() {
    // The regression this guards is silent: without the rewind the crawler
    // returns nothing for every page, and a scan simply finds no links.
    $html = '<html><body><a href="https://example.com/a">A</a></body></html>';

    $stack = HandlerStack::create(function (RequestInterface $request, array $options) use ($html) {
        $sink = $options[RequestOptions::SINK] ?? null;
        expect($sink)->not->toBeNull();
        $sink->write($html);

        return \GuzzleHttp\Promise\Create::promiseFor(
            new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $sink),
        );
    });

    $crawler = LinkAudit::getInstance()->getPageCrawler();
    $crawler->setClient(new Client(['handler' => $stack]));

    expect($crawler->fetch('https://example.com/page'))->toContain('https://example.com/a');
});
