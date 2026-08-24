<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\models\Verdict;
use johnhenry\linkaudit\services\HttpChecker;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * A checker wired to a mocked handler stack. Nothing in this file, or anywhere
 * else in the suite, is allowed to make a real request.
 *
 * @param array<int, mixed> $queue The responses and exceptions to hand back, in
 *                                 order.
 * @param array<int, array<string, mixed>>|null $history Filled with the requests
 *                                                       that were sent.
 */
function linkAuditChecker(array $queue, ?array &$history = null): HttpChecker
{
    $stack = HandlerStack::create(new MockHandler($queue));

    if (func_num_args() > 1) {
        $stack->push(Middleware::history($history));
    }

    return new HttpChecker(['client' => new Client(['handler' => $stack])]);
}

/** A request to hang a transport exception off. */
function linkAuditRequest(string $url = 'https://example.com/'): Request
{
    return new Request('HEAD', $url);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('calls a link that answers ok', function() {
    $history = [];
    $verdict = linkAuditChecker([new Response(200)], $history)->check('https://example.com/');

    expect($verdict->status)->toBe(UrlStatus::Ok)
        ->and($verdict->httpStatus)->toBe(200)
        ->and($verdict->method)->toBe('head')
        ->and($verdict->redirectCount)->toBe(0)
        ->and($verdict->finalUrl)->toBeNull()
        ->and($verdict->redirectStatus)->toBeNull()
        ->and($verdict->isDeferred())->toBeFalse()
        ->and($verdict->responseTimeMs)->toBeGreaterThanOrEqual(0)
        ->and($history)->toHaveCount(1)
        ->and($history[0]['request']->getMethod())->toBe('HEAD');
});

it('reports a permanent redirect as the content job it is', function() {
    $verdict = linkAuditChecker([
        new Response(301, ['Location' => 'https://example.com/new-address']),
        new Response(200),
    ])->check('https://example.com/old-address');

    expect($verdict->status)->toBe(UrlStatus::Redirect)
        ->and($verdict->httpStatus)->toBe(200)
        ->and($verdict->redirectCount)->toBe(1)
        ->and($verdict->finalUrl)->toBe('https://example.com/new-address')
        ->and($verdict->redirectPermanent)->toBeTrue()
        // The status on the verdict is the 200 the chain landed on, so without
        // the first hop's own code there is nothing on the row saying the link
        // moved at all.
        ->and($verdict->redirectStatus)->toBe(301);
});

it('does not ask an author to chase a temporary redirect', function() {
    $verdict = linkAuditChecker([
        new Response(302, ['Location' => 'https://example.com/for-now']),
        new Response(200),
    ])->check('https://example.com/moved');

    expect($verdict->status)->toBe(UrlStatus::Redirect)
        ->and($verdict->redirectCount)->toBe(1)
        ->and($verdict->redirectPermanent)->toBeFalse()
        ->and($verdict->redirectStatus)->toBe(302);
});

it('keeps the first hop of a longer chain, not the last', function() {
    $verdict = linkAuditChecker([
        new Response(301, ['Location' => 'https://example.com/second']),
        new Response(302, ['Location' => 'https://example.com/third']),
        new Response(200),
    ])->check('https://example.com/first');

    expect($verdict->status)->toBe(UrlStatus::Redirect)
        ->and($verdict->redirectCount)->toBe(2)
        // The first hop is the answer the address in the content got, which is
        // the one an author is being asked to do something about.
        ->and($verdict->redirectStatus)->toBe(301);
});

it('calls a 404 broken', function() {
    $verdict = linkAuditChecker([new Response(404)])->check('https://example.com/gone');

    expect($verdict->status)->toBe(UrlStatus::Broken)
        ->and($verdict->httpStatus)->toBe(404)
        ->and($verdict->reason)->toBe(Verdict::REASON_HTTP);
});

it('calls a 410 broken', function() {
    $verdict = linkAuditChecker([new Response(410)])->check('https://example.com/deleted');

    expect($verdict->status)->toBe(UrlStatus::Broken)
        ->and($verdict->httpStatus)->toBe(410)
        ->and($verdict->reason)->toBe(Verdict::REASON_HTTP);
});

it('tries a GET when the server will not answer a HEAD', function() {
    $history = [];
    $verdict = linkAuditChecker([
        new Response(405),
        new Response(200),
    ], $history)->check('https://example.com/head-hostile');

    expect($verdict->status)->toBe(UrlStatus::Ok)
        ->and($verdict->method)->toBe('get')
        ->and($history)->toHaveCount(2)
        ->and($history[0]['request']->getMethod())->toBe('HEAD')
        ->and($history[1]['request']->getMethod())->toBe('GET')
        ->and($history[1]['request']->getHeaderLine('Range'))->toBe('bytes=0-2047');
});

it('stops at two requests, whatever the second one says', function() {
    $history = [];
    $verdict = linkAuditChecker([
        new Response(501),
        new Response(404),
    ], $history)->check('https://example.com/awkward');

    expect($verdict->status)->toBe(UrlStatus::Broken)
        ->and($verdict->method)->toBe('get')
        ->and($history)->toHaveCount(2);
});

it('holds a 500 as unreachable rather than broken', function() {
    $verdict = linkAuditChecker([new Response(500)])->check('https://example.com/wobbly');

    expect($verdict->status)->toBe(UrlStatus::Unreachable)
        ->and($verdict->httpStatus)->toBe(500)
        ->and($verdict->reason)->toBe(Verdict::REASON_HTTP);
});

it('reads a connection timeout off the transport error', function() {
    $verdict = linkAuditChecker([
        new ConnectException(
            'cURL error 28: Operation timed out after 20001 milliseconds',
            linkAuditRequest(),
        ),
    ])->check('https://example.com/slow');

    expect($verdict->status)->toBe(UrlStatus::Unreachable)
        ->and($verdict->reason)->toBe(Verdict::REASON_TIMEOUT)
        ->and($verdict->httpStatus)->toBeNull()
        ->and($verdict->message)->toContain('timed out');
});

it('tells a certificate problem apart from a refused connection', function() {
    $verdict = linkAuditChecker([
        new ConnectException(
            'cURL error 60: SSL certificate problem: certificate has expired',
            linkAuditRequest(),
        ),
    ])->check('https://example.com/expired-cert');

    expect($verdict->status)->toBe(UrlStatus::Unreachable)
        ->and($verdict->reason)->toBe(Verdict::REASON_SSL);
});

it('calls a refused connection what it is', function() {
    $verdict = linkAuditChecker([
        new ConnectException('cURL error 7: Failed to connect to example.com port 443', linkAuditRequest()),
    ])->check('https://example.com/nothing-listening');

    expect($verdict->status)->toBe(UrlStatus::Unreachable)
        ->and($verdict->reason)->toBe(Verdict::REASON_CONNECT);
});

it('treats a 429 as a deferral, not a verdict', function() {
    $verdict = linkAuditChecker([
        new Response(429, ['Retry-After' => '60']),
    ])->check('https://example.com/too-eager');

    expect($verdict->isDeferred())->toBeTrue()
        ->and($verdict->status)->toBe(UrlStatus::Pending)
        ->and($verdict->httpStatus)->toBe(429)
        ->and($verdict->reason)->toBe(Verdict::REASON_RATE_LIMITED)
        ->and($verdict->retryAfterSeconds)->toBe(60);
});

it('reads a Retry-After given as a date', function() {
    $verdict = linkAuditChecker([
        new Response(429, ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 120)]),
    ])->check('https://example.com/too-eager-again');

    expect($verdict->isDeferred())->toBeTrue()
        ->and($verdict->retryAfterSeconds)->toBeGreaterThan(115)
        ->and($verdict->retryAfterSeconds)->toBeLessThanOrEqual(120);
});

it('shrugs at a 429 that says nothing about when to come back', function() {
    $verdict = linkAuditChecker([new Response(429)])->check('https://example.com/quiet-about-it');

    expect($verdict->isDeferred())->toBeTrue()
        ->and($verdict->retryAfterSeconds)->toBeNull();
});

it('never lets a URL that resolves to a private address out of the building', function() {
    $verdict = linkAuditChecker([new Response(200)])->check('https://169.254.169.254/latest/meta-data');

    expect($verdict->status)->toBe(UrlStatus::Unsafe)
        ->and($verdict->reason)->toBe(Verdict::REASON_PRIVATE_IP)
        ->and($verdict->httpStatus)->toBeNull();
});

it('calls a host that does not resolve unreachable, not unsafe', function() {
    $verdict = linkAuditChecker([new Response(200)])
        ->check('https://this-domain-does-not-exist-xyz123.invalid/');

    expect($verdict->status)->toBe(UrlStatus::Unreachable)
        ->and($verdict->reason)->toBe(Verdict::REASON_DNS);
});
