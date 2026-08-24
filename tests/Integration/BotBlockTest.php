<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\events\DefineVerdictEvent;
use johnhenry\linkaudit\helpers\BotBlockHeuristics;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\Verdict;
use johnhenry\linkaudit\services\HttpChecker;
use yii\base\Event;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * A checker wired to a mocked handler stack.
 *
 * @param array<int, mixed> $queue The responses to hand back, in order.
 * @param array<int, array<string, mixed>>|null $history Filled with the requests
 *                                                       that were sent.
 */
function linkAuditBlockChecker(array $queue, ?array &$history = null): HttpChecker
{
    $stack = HandlerStack::create(new MockHandler($queue));

    if (func_num_args() > 1) {
        $stack->push(Middleware::history($history));
    }

    return new HttpChecker(['client' => new Client(['handler' => $stack])]);
}

afterEach(function() {
    Event::off(HttpChecker::class, HttpChecker::EVENT_DEFINE_VERDICT);
    LinkAudit::getInstance()->getSettings()->botHostileHosts = [];
});

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('knows LinkedIn is refusing robots, not missing a page', function() {
    // This is what a 999 really looks like by the time the client is finished
    // with it: PSR-7 will not hold a status at or above 600, so the code is
    // gone and a request exception about the response arrives instead.
    $verdict = linkAuditBlockChecker([
        new RequestException(
            'An error was encountered while creating the response',
            new Request('HEAD', 'https://www.linkedin.com/in/someone'),
            null,
            new InvalidArgumentException('Status code must be an integer value between 1xx and 5xx.'),
        ),
    ])->check('https://www.linkedin.com/in/someone');

    expect($verdict->status)->toBe(UrlStatus::Blocked)
        ->and($verdict->reason)->toBe(Verdict::REASON_HTTP)
        ->and($verdict->message)->toContain(BotBlockHeuristics::SIGNATURE_NONSTANDARD_STATUS);
});

it('reads a Cloudflare challenge off the response headers', function() {
    $history = [];
    $verdict = linkAuditBlockChecker([
        new Response(403, ['cf-mitigated' => 'challenge', 'server' => 'cloudflare']),
    ], $history)->check('https://example.com/behind-cloudflare');

    expect($verdict->status)->toBe(UrlStatus::Blocked)
        ->and($verdict->message)->toContain(BotBlockHeuristics::SIGNATURE_CLOUDFLARE_CHALLENGE)
        // The signature settles it, so there is no point spending a GET on it.
        ->and($history)->toHaveCount(1);
});

it('reads a Cloudflare 1020 off the body the GET fallback fetched', function() {
    $history = [];
    $verdict = linkAuditBlockChecker([
        new Response(403, ['server' => 'cloudflare']),
        new Response(403, ['server' => 'cloudflare'], '<html><body>Error code: 1020</body></html>'),
    ], $history)->check('https://example.com/firewalled');

    expect($verdict->status)->toBe(UrlStatus::Blocked)
        ->and($verdict->method)->toBe('get')
        ->and($verdict->message)->toContain(BotBlockHeuristics::SIGNATURE_CLOUDFLARE_1020)
        ->and($history)->toHaveCount(2);
});

it('calls a plain 403 with nothing to hide broken', function() {
    $history = [];
    $verdict = linkAuditBlockChecker([
        new Response(403),
        new Response(403, [], 'Forbidden'),
    ], $history)->check('https://example.com/no-entry');

    expect($verdict->status)->toBe(UrlStatus::Broken)
        ->and($verdict->httpStatus)->toBe(403)
        ->and($verdict->reason)->toBe(Verdict::REASON_HTTP)
        // A 403 might be the server refusing the method, so it earns one GET.
        ->and($history)->toHaveCount(2);
});

it('spots the other vendors by their fingerprints', function(array $headers, string $signature) {
    $verdict = linkAuditBlockChecker([new Response(403, $headers)])
        ->check('https://example.com/protected');

    expect($verdict->status)->toBe(UrlStatus::Blocked)
        ->and($verdict->message)->toContain($signature);
})->with([
    'DataDome' => [['x-datadome' => 'protected'], BotBlockHeuristics::SIGNATURE_DATADOME],
    'PerimeterX' => [['x-px-block' => '1'], BotBlockHeuristics::SIGNATURE_PERIMETERX],
    'Imperva' => [['x-iinfo' => '1-2-3'], BotBlockHeuristics::SIGNATURE_IMPERVA],
    'Akamai' => [['server' => 'AkamaiGHost'], BotBlockHeuristics::SIGNATURE_AKAMAI],
    'AWS WAF' => [
        ['x-cache' => 'Error from cloudfront', 'via' => '1.1 abc.cloudfront.net (CloudFront)'],
        BotBlockHeuristics::SIGNATURE_AWS_WAF,
    ],
]);

it('does not mistake an ordinary CloudFront 404 for a firewall', function() {
    $verdict = linkAuditBlockChecker([
        new Response(404, ['x-cache' => 'Error from cloudfront', 'via' => '1.1 abc.cloudfront.net (CloudFront)']),
    ])->check('https://example.com/really-gone');

    expect($verdict->status)->toBe(UrlStatus::Broken)
        ->and($verdict->httpStatus)->toBe(404);
});

it('spots a CAPTCHA hiding behind a 503', function() {
    $verdict = linkAuditBlockChecker([
        new Response(503),
        new Response(503, [], 'To discuss automated access, enter the characters you see below. Captcha'),
    ])->check('https://example.com/robot-check');

    expect($verdict->status)->toBe(UrlStatus::Blocked)
        ->and($verdict->message)->toContain(BotBlockHeuristics::SIGNATURE_CAPTCHA_503);
});

it('spots a social network sending an anonymous request to its login wall', function() {
    $verdict = linkAuditBlockChecker([
        new Response(302, ['Location' => 'https://www.instagram.com/accounts/login/']),
        new Response(200),
    ])->check('https://www.instagram.com/someone/');

    expect($verdict->status)->toBe(UrlStatus::Blocked)
        ->and($verdict->message)->toContain(BotBlockHeuristics::SIGNATURE_SOCIAL_LOGIN_WALL);
});

it('takes the settings word for it on a host that is known to be difficult', function() {
    LinkAudit::getInstance()->getSettings()->botHostileHosts = [['host' => 'example.com']];

    $verdict = linkAuditBlockChecker([new Response(404)])->check('https://www.example.com/anything');

    expect($verdict->status)->toBe(UrlStatus::Blocked)
        ->and($verdict->message)->toContain(BotBlockHeuristics::SIGNATURE_HOST_LIST);
});

it('leaves a listed host alone when it answers perfectly well', function() {
    LinkAudit::getInstance()->getSettings()->botHostileHosts = [['host' => 'example.com']];

    $verdict = linkAuditBlockChecker([new Response(200)])->check('https://example.com/fine');

    expect($verdict->status)->toBe(UrlStatus::Ok);
});

it('leaves a 429 as a deferral even on a difficult host', function() {
    LinkAudit::getInstance()->getSettings()->botHostileHosts = [['host' => 'example.com']];

    $verdict = linkAuditBlockChecker([new Response(429, ['Retry-After' => '30'])])
        ->check('https://example.com/slow-down');

    expect($verdict->isDeferred())->toBeTrue()
        ->and($verdict->retryAfterSeconds)->toBe(30);
});

it('lets a listener overrule the checker', function() {
    Event::on(
        HttpChecker::class,
        HttpChecker::EVENT_DEFINE_VERDICT,
        function(DefineVerdictEvent $event) {
            if ($event->httpStatus === 404 && str_contains($event->url, 'known-awkward')) {
                $event->verdict = new Verdict(
                    status: UrlStatus::Ignored,
                    httpStatus: $event->httpStatus,
                    method: $event->method,
                    message: 'This one always answers 404 for robots.',
                );
            }
        },
    );

    $overruled = linkAuditBlockChecker([new Response(404)])->check('https://example.com/known-awkward');
    $untouched = linkAuditBlockChecker([new Response(404)])->check('https://example.com/ordinary');

    expect($overruled->status)->toBe(UrlStatus::Ignored)
        ->and($overruled->message)->toBe('This one always answers 404 for robots.')
        ->and($untouched->status)->toBe(UrlStatus::Broken);
});

it('hands a listener everything it needs to judge for itself', function() {
    $seen = [];

    Event::on(
        HttpChecker::class,
        HttpChecker::EVENT_DEFINE_VERDICT,
        function(DefineVerdictEvent $event) use (&$seen) {
            $seen = [
                'url' => $event->url,
                'method' => $event->method,
                'httpStatus' => $event->httpStatus,
                'finalUrl' => $event->finalUrl,
                'server' => $event->headers['server'][0] ?? null,
                'status' => $event->verdict->status,
            ];
        },
    );

    linkAuditBlockChecker([
        new Response(301, ['Location' => 'https://example.com/there']),
        new Response(200, ['server' => 'nginx']),
    ])->check('https://example.com/here');

    expect($seen)->toBe([
        'url' => 'https://example.com/here',
        'method' => 'head',
        'httpStatus' => 200,
        'finalUrl' => 'https://example.com/there',
        'server' => 'nginx',
        'status' => UrlStatus::Redirect,
    ]);
});
