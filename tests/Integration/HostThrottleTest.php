<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\db\Query;
use craft\helpers\DateTimeHelper;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\Verdict;
use johnhenry\linkaudit\records\HostRecord;
use johnhenry\linkaudit\services\HttpChecker;
use johnhenry\linkaudit\services\RequestScheduler;
use Psr\Http\Message\RequestInterface;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * A handler that hands back promises nobody has resolved yet.
 *
 * A MockHandler answers the instant it is asked, so nothing is ever in flight
 * and a concurrency limit cannot be tested against it. This one holds each
 * request open until the scheduler waits on it, which is the only way to see how
 * many the scheduler was willing to have running at once.
 *
 * @param callable $responder Given a request, returns a response or a throwable.
 * @param array<string, int> $current Live count per host.
 * @param array<string, int> $peak The most that were ever live at once, per host.
 * @param array<int, string> $sent Every request URL, in the order it went out.
 */
function linkAuditTrackingHandler(
    callable $responder,
    array &$current,
    array &$peak,
    array &$sent,
): callable {
    return function(RequestInterface $request) use (&$current, &$peak, &$sent, $responder): PromiseInterface {
        $host = $request->getUri()->getHost();
        $current[$host] = ($current[$host] ?? 0) + 1;
        $peak[$host] = max($peak[$host] ?? 0, $current[$host]);
        $sent[] = (string)$request->getUri();

        $promise = new Promise(function() use (&$promise, &$current, $host, $request, $responder) {
            $current[$host]--;
            $result = $responder($request);

            if ($result instanceof Throwable) {
                $promise->reject($result);

                return;
            }

            $promise->resolve($result);
        });

        return $promise;
    };
}

/** A scheduler driven by the given handler. */
function linkAuditScheduler(callable $handler): RequestScheduler
{
    $checker = new HttpChecker(['client' => new Client(['handler' => HandlerStack::create($handler)])]);

    return new RequestScheduler(['checker' => $checker]);
}

/** The stored host row, or null when the domain has never been recorded. */
function linkAuditHostRow(string $host): ?array
{
    $row = (new Query())
        ->from([HostRecord::tableName()])
        ->where(['host' => $host])
        ->one();

    return is_array($row) ? $row : null;
}

/** Seconds from now until a stored UTC date. */
function linkAuditSecondsFromNow(?string $storedDate): float
{
    $date = DateTimeHelper::toDateTime($storedDate);
    expect($date)->not->toBeFalse();

    return $date->getTimestamp() - DateTimeHelper::now()->getTimestamp();
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('never has more than the allowed number of requests running at one host', function() {
    $settings = LinkAudit::getInstance()->getSettings();
    $settings->concurrency = 10;
    $settings->maxConcurrentPerHost = 2;
    $settings->minHostDelayMs = 0;

    $current = [];
    $peak = [];
    $sent = [];

    $urls = [];

    for ($i = 1; $i <= 20; $i++) {
        $urls[] = "https://example.com/page-$i";
    }

    $verdicts = linkAuditScheduler(linkAuditTrackingHandler(
        static fn(): Response => new Response(200),
        $current,
        $peak,
        $sent,
    ))->run($urls);

    expect($verdicts)->toHaveCount(20)
        ->and($peak['example.com'])->toBe(2)
        ->and($sent)->toHaveCount(20)
        ->and(array_values(array_unique(array_map(
            static fn(Verdict $verdict): string => $verdict->status->value,
            $verdicts,
        ))))->toBe([UrlStatus::Ok->value]);
});

it('backs a whole host off when one of its URLs answers 429', function() {
    $settings = LinkAudit::getInstance()->getSettings();
    $settings->maxConcurrentPerHost = 1;
    $settings->minHostDelayMs = 0;

    $current = [];
    $peak = [];
    $sent = [];

    $urls = [
        'https://example.com/one',
        'https://example.com/two',
        'https://example.com/three',
        'https://example.com/four',
    ];

    $verdicts = linkAuditScheduler(linkAuditTrackingHandler(
        static fn(): Response => new Response(429, ['Retry-After' => '60']),
        $current,
        $peak,
        $sent,
    ))->run($urls);

    $row = linkAuditHostRow('example.com');

    expect($verdicts)->toHaveCount(4)
        // Only the first URL was ever asked; the host spoke for the other three.
        ->and($sent)->toHaveCount(1)
        ->and($verdicts['https://example.com/one']->isDeferred())->toBeTrue()
        ->and($verdicts['https://example.com/one']->reason)->toBe(Verdict::REASON_RATE_LIMITED)
        ->and($verdicts['https://example.com/four']->isDeferred())->toBeTrue()
        ->and($verdicts['https://example.com/four']->reason)->toBe(Verdict::REASON_HOST_BACKOFF)
        ->and($row)->not->toBeNull()
        ->and(linkAuditSecondsFromNow($row['blockedUntil']))->toBeGreaterThan(50)
        ->and(linkAuditSecondsFromNow($row['blockedUntil']))->toBeLessThanOrEqual(60)
        ->and((int)$row['minDelayMs'])->toBe(30000);
});

it('honours a long Retry-After as a window, not as a gap to wait out per URL', function() {
    $hostState = LinkAudit::getInstance()->getHostState();
    $hostState->recordRateLimit('example.com', 900);

    $row = linkAuditHostRow('example.com');

    expect(linkAuditSecondsFromNow($row['blockedUntil']))->toBeGreaterThan(880)
        ->and(linkAuditSecondsFromNow($row['blockedUntil']))->toBeLessThanOrEqual(900)
        // Skipping a host for fifteen minutes costs nothing. Waiting fifteen
        // minutes between two requests costs the scan six hours a chunk.
        ->and((int)$row['minDelayMs'])->toBe(30000)
        ->and($hostState->minDelayMs('example.com'))->toBe(30000);
});

it('forgets a learned gap again as the host goes back to answering', function() {
    $settings = LinkAudit::getInstance()->getSettings();
    $settings->minHostDelayMs = 250;

    $hostState = LinkAudit::getInstance()->getHostState();
    $hostState->recordRateLimit('example.com', 60);

    expect($hostState->minDelayMs('example.com'))->toBe(30000);

    $seen = [];

    for ($i = 0; $i < 10; $i++) {
        $hostState->recordSuccess('example.com', 200);
        $seen[] = $hostState->minDelayMs('example.com');
    }

    expect($seen[0])->toBe(15000)
        ->and($seen[1])->toBe(7500)
        // Back to the configured floor, with nothing left on the row to
        // remember.
        ->and(end($seen))->toBe(250)
        ->and(linkAuditHostRow('example.com')['minDelayMs'])->toBeNull();
});

it('leaves a host out of the batch when its gap could never get through them', function() {
    $settings = LinkAudit::getInstance()->getSettings();
    $settings->minHostDelayMs = 0;
    $settings->maxConcurrentPerHost = 1;

    // A host that asked to be left alone, whose window has since run out: the
    // learned gap is what is left of it.
    $hostState = LinkAudit::getInstance()->getHostState();
    $hostState->recordRateLimit('example.com', 60);
    craft\helpers\Db::update(
        johnhenry\linkaudit\records\HostRecord::tableName(),
        ['blockedUntil' => null],
        ['host' => 'example.com'],
    );
    $hostState->flush();

    $current = [];
    $peak = [];
    $sent = [];

    $urls = [];

    for ($i = 1; $i <= 20; $i++) {
        $urls[] = "https://example.com/slow-$i";
    }

    $urls[] = 'https://example.net/quick';

    $startedAt = microtime(true);
    $verdicts = linkAuditScheduler(linkAuditTrackingHandler(
        static fn(): Response => new Response(200),
        $current,
        $peak,
        $sent,
    ))->run($urls);
    $elapsed = microtime(true) - $startedAt;

    expect($verdicts)->toHaveCount(21)
        // Nineteen gaps of thirty seconds is nearly ten minutes, so not one of
        // them was asked. The host on the next domain went out as usual.
        ->and($sent)->toBe(['https://example.net/quick'])
        ->and($verdicts['https://example.com/slow-1']->isDeferred())->toBeTrue()
        ->and($verdicts['https://example.com/slow-1']->reason)->toBe(Verdict::REASON_HOST_BACKOFF)
        ->and($verdicts['https://example.com/slow-1']->retryAfterSeconds)->toBeGreaterThan(0)
        ->and($verdicts['https://example.net/quick']->status)->toBe(UrlStatus::Ok)
        ->and($elapsed)->toBeLessThan(5.0);
});

it('hands the tail back deferred once the run has spent its waiting budget', function() {
    $settings = LinkAudit::getInstance()->getSettings();
    $settings->minHostDelayMs = 0;
    $settings->retryCount = 2;

    $current = [];
    $peak = [];
    $sent = [];

    $scheduler = linkAuditScheduler(linkAuditTrackingHandler(
        static fn(): Response => new Response(500),
        $current,
        $peak,
        $sent,
    ));
    // Next to nothing, so the first retry's backoff is already past it. Sixty
    // seconds is the real budget, and waiting that out in a test would be sixty
    // seconds nobody has.
    $scheduler->maxYieldSeconds = 0.05;

    $startedAt = microtime(true);
    $verdicts = $scheduler->run(['https://example.com/wobbly']);
    $elapsed = microtime(true) - $startedAt;

    expect($sent)->toHaveCount(1)
        ->and($verdicts['https://example.com/wobbly']->isDeferred())->toBeTrue()
        ->and($verdicts['https://example.com/wobbly']->reason)->toBe(Verdict::REASON_HOST_BACKOFF)
        // Carries what was left of its wait, so the row is put off rather than
        // offered again on the very next pass.
        ->and($verdicts['https://example.com/wobbly']->retryAfterSeconds)->toBeGreaterThan(0)
        ->and($elapsed)->toBeLessThan(1.0);
});

it('leaves a host alone entirely while it is inside a backoff window', function() {
    LinkAudit::getInstance()->getHostState()->recordRateLimit('example.com', 300);

    $current = [];
    $peak = [];
    $sent = [];

    $verdicts = linkAuditScheduler(linkAuditTrackingHandler(
        static fn(): Response => new Response(200),
        $current,
        $peak,
        $sent,
    ))->run([
        'https://example.com/one',
        'https://example.com/two',
        'https://example.net/elsewhere',
    ]);

    expect($sent)->toBe(['https://example.net/elsewhere'])
        ->and($verdicts['https://example.com/one']->reason)->toBe(Verdict::REASON_HOST_BACKOFF)
        ->and($verdicts['https://example.com/two']->isDeferred())->toBeTrue()
        ->and($verdicts['https://example.net/elsewhere']->status)->toBe(UrlStatus::Ok);
});

it('gives a refused connection another go before believing it', function() {
    $settings = LinkAudit::getInstance()->getSettings();
    $settings->minHostDelayMs = 0;
    $settings->retryCount = 2;

    $current = [];
    $peak = [];
    $sent = [];
    $calls = 0;

    $verdicts = linkAuditScheduler(linkAuditTrackingHandler(
        static function(RequestInterface $request) use (&$calls) {
            $calls++;

            return $calls === 1
                ? new ConnectException('cURL error 7: Failed to connect to example.com port 443', $request)
                : new Response(200);
        },
        $current,
        $peak,
        $sent,
    ))->run(['https://example.com/flaky']);

    expect($calls)->toBe(2)
        ->and($verdicts['https://example.com/flaky']->status)->toBe(UrlStatus::Ok)
        ->and($verdicts['https://example.com/flaky']->attempts)->toBe(2);
});

it('gives up once the retries are used, and remembers the host is struggling', function() {
    $settings = LinkAudit::getInstance()->getSettings();
    $settings->minHostDelayMs = 0;
    $settings->retryCount = 1;

    $current = [];
    $peak = [];
    $sent = [];

    $verdicts = linkAuditScheduler(linkAuditTrackingHandler(
        static fn(RequestInterface $request): ConnectException => new ConnectException(
            'cURL error 7: Failed to connect',
            $request,
        ),
        $current,
        $peak,
        $sent,
    ))->run(['https://example.com/down']);

    $row = linkAuditHostRow('example.com');

    expect($sent)->toHaveCount(2)
        ->and($verdicts['https://example.com/down']->status)->toBe(UrlStatus::Unreachable)
        ->and($verdicts['https://example.com/down']->attempts)->toBe(2)
        ->and($row)->not->toBeNull()
        ->and((int)$row['consecutiveFailures'])->toBe(1);
});

it('works its way round the hosts rather than through them, and keeps each in order', function() {
    $settings = LinkAudit::getInstance()->getSettings();
    $settings->concurrency = 4;
    $settings->maxConcurrentPerHost = 1;
    $settings->minHostDelayMs = 0;

    $current = [];
    $peak = [];
    $sent = [];

    $urls = [
        'https://example.com/1',
        'https://example.com/2',
        'https://example.com/3',
        'https://example.net/1',
        'https://example.net/2',
        'https://example.org/1',
    ];

    $verdicts = linkAuditScheduler(linkAuditTrackingHandler(
        static fn(): Response => new Response(200),
        $current,
        $peak,
        $sent,
    ))->run($urls);

    $perHost = [];

    foreach ($sent as $url) {
        $perHost[parse_url($url, PHP_URL_HOST)][] = $url;
    }

    expect($verdicts)->toHaveCount(6)
        ->and($sent)->toHaveCount(6)
        // The first sweep touches each host once before coming back for seconds.
        ->and(array_slice($sent, 0, 3))->toBe([
            'https://example.com/1',
            'https://example.net/1',
            'https://example.org/1',
        ])
        ->and($perHost['example.com'])->toBe([
            'https://example.com/1',
            'https://example.com/2',
            'https://example.com/3',
        ])
        ->and($perHost['example.net'])->toBe([
            'https://example.net/1',
            'https://example.net/2',
        ]);
});

it('keeps two requests to the same host apart', function() {
    $settings = LinkAudit::getInstance()->getSettings();
    $settings->maxConcurrentPerHost = 1;
    $settings->minHostDelayMs = 120;

    $current = [];
    $peak = [];
    $sent = [];
    $startedAt = microtime(true);

    linkAuditScheduler(linkAuditTrackingHandler(
        static fn(): Response => new Response(200),
        $current,
        $peak,
        $sent,
    ))->run(['https://example.com/one', 'https://example.com/two', 'https://example.com/three']);

    $elapsedMs = (microtime(true) - $startedAt) * 1000;

    expect($sent)->toHaveCount(3)
        ->and($elapsedMs)->toBeGreaterThan(200);
});

it('hands back a verdict for every URL it was given, whatever happened', function() {
    $settings = LinkAudit::getInstance()->getSettings();
    $settings->minHostDelayMs = 0;
    $settings->retryCount = 0;

    $current = [];
    $peak = [];
    $sent = [];

    $verdicts = linkAuditScheduler(linkAuditTrackingHandler(
        static fn(RequestInterface $request) => match ($request->getUri()->getPath()) {
            '/gone' => new Response(404),
            '/wobbly' => new Response(500),
            default => new Response(200),
        },
        $current,
        $peak,
        $sent,
    ))->run([
        'https://example.com/fine',
        'https://example.com/gone',
        'https://example.com/wobbly',
        'https://169.254.169.254/latest/meta-data',
    ]);

    expect($verdicts)->toHaveCount(4)
        ->and($verdicts['https://example.com/fine']->status)->toBe(UrlStatus::Ok)
        ->and($verdicts['https://example.com/gone']->status)->toBe(UrlStatus::Broken)
        ->and($verdicts['https://example.com/wobbly']->status)->toBe(UrlStatus::Unreachable)
        // Never requested: the SSRF guard turned it back at the door.
        ->and($verdicts['https://169.254.169.254/latest/meta-data']->status)->toBe(UrlStatus::Unsafe)
        ->and($sent)->toHaveCount(3);
});
