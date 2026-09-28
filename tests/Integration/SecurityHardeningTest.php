<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\elements\Entry;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use johnhenry\ipguard\IpRange;
use johnhenry\linkaudit\enums\LinkKind;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\helpers\CappedStream;
use johnhenry\linkaudit\helpers\UrlNormaliser;
use johnhenry\linkaudit\helpers\UrlSafety;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\services\HttpChecker;

// ---------------------------------------------------------------------------
// Hardening against hostile content
//
// Anything an author, a navigation editor or a crawled page can put in a link
// ends up in a server-side request and on a report an admin opens. These pin
// down what can't get through: secrets expanded from environment variables,
// markup in a host, a Retry-After that breaks the database, a request to a
// private address, and page details shown to somebody who can't view the page.
//
// Helper names carry a `sec` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/** Runs the pinning middleware over one request and returns the options it passed on, or the rejection. */
function secPin(string $url): array|Throwable
{
    $seen = null;
    $handler = static function(Psr\Http\Message\RequestInterface $request, array $options) use (&$seen) {
        $seen = $options;

        return Create::promiseFor(new Response(200));
    };

    $promise = UrlSafety::pinningMiddleware()($handler)(new Request('GET', $url), []);

    try {
        $promise->wait();
    } catch (Throwable $e) {
        return $e;
    }

    return $seen ?? [];
}

it('does not expand environment variables in a navigation node URL', function() {
    $node = navNode([
        'title' => 'Secret',
        'url' => 'https://collector.example/$CRAFT_SECURITY_KEY',
    ]);
    $braced = navNode([
        'title' => 'Braced secret',
        'url' => 'https://collector.example/${CRAFT_DB_PASSWORD}',
    ]);

    expect(navLinksFor($node))->toBe([])
        ->and(navLinksFor($braced))->toBe([]);
})->skip(fn() => !navInstalled(), 'verbb/navigation is not installed.');

it('refuses a host carrying markup', function() {
    expect(UrlNormaliser::normalise('https://x<img src=x onerror=alert(1)>/'))->toBeNull()
        ->and(UrlNormaliser::normalise('http://<script>/'))->toBeNull()
        ->and(UrlNormaliser::normalise('https://Example.COM/ok'))->toBe('https://example.com/ok');
});

it('clamps an absurd Retry-After to a day', function() {
    $verdict = linkAuditChecker([
        new Response(429, ['Retry-After' => '999999999999']),
    ])->check('https://example.com/');

    expect($verdict->status)->toBe(UrlStatus::Pending)
        ->and($verdict->retryAfterSeconds)->toBeLessThanOrEqual(HttpChecker::MAX_RETRY_AFTER_SECONDS);
});

it('can defer a row by an absurd Retry-After without the database refusing it', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $urlId = $store->upsert('https://example.com/rate-limited-' . bin2hex(random_bytes(4)), false);

    $store->defer($urlId, PHP_INT_MAX);

    expect(true)->toBeTrue();
});

it('only treats the site\'s own scheme, host and port as its own origin', function() {
    $baseUrl = (string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl();
    $parts = parse_url($baseUrl);
    $scheme = (string)$parts['scheme'];
    $host = (string)$parts['host'];
    $port = isset($parts['port']) ? (int)$parts['port'] : null;

    expect(IpRange::isOwnSiteOrigin($scheme, $host, $port))->toBeTrue()
        ->and(IpRange::isOwnSiteOrigin($scheme, $host, 6379))->toBeFalse()
        ->and(IpRange::isOwnSiteOrigin($scheme === 'https' ? 'http' : 'https', $host, 6379))->toBeFalse();
});

it('pins the connection to the address it checked', function() {
    // Seeded rather than looked up, so the test doesn't need the network.
    $resolved = new ReflectionProperty(UrlSafety::class, '_resolved');
    $resolved->setValue(null, ['pinned.example' => ['93.184.215.14']]);

    try {
        $options = secPin('https://pinned.example/page');
    } finally {
        $resolved->setValue(null, []);
    }

    expect($options)->toBeArray()
        ->and($options['curl'][CURLOPT_RESOLVE] ?? [])->toContain('pinned.example:443:93.184.215.14');
});

it('refuses to connect to a private address', function() {
    expect(secPin('http://127.0.0.1/'))->toBeInstanceOf(Throwable::class)
        ->and(secPin('http://169.254.169.254/latest/meta-data/'))->toBeInstanceOf(Throwable::class)
        ->and(secPin('http://2130706433/'))->toBeInstanceOf(Throwable::class);
});

it('keeps only the first bytes of a response body', function() {
    $stream = new CappedStream(2048);

    $written = $stream->write(str_repeat('a', 5000));

    expect($written)->toBe(5000)
        ->and($stream->getSize())->toBe(2048);
});

it('strips credentials from a link with a scheme it does not check', function() {
    $entry = laEntry();
    $entry->setFieldValue('laBody', '<p><a href="ftp://admin:hunter2@files.example/x">Files</a></p>');

    $links = laExtract($entry);

    expect($links)->toHaveCount(1)
        ->and($links[0]->kind)->toBe(LinkKind::Ignored)
        ->and($links[0]->url)->toBe('ftp://files.example/x')
        ->and($links[0]->rawHref)->not->toContain('hunter2');
});

it('says only that a page exists when the reader cannot view it', function() {
    $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
    $entry = laEntry('A page nobody else may read');
    $entry->setFieldValue('laBody', '<p><a href="https://example.com/secret-target">Secret text</a></p>');
    Craft::$app->getElements()->saveElement($entry);

    LinkAudit::getInstance()->getScanService()->extractElement((int)$entry->id, Entry::class, $siteId);

    $urlId = (int)(new craft\db\Query())
        ->select(['id'])
        ->from([johnhenry\linkaudit\records\UrlRecord::tableName()])
        ->where(['urlHash' => UrlNormaliser::hash('https://example.com/secret-target')])
        ->scalar();

    $reader = permUserWith(['link-audit:view-reports']);
    $references = LinkAudit::getInstance()->getReportService()->references($urlId, [$siteId], $reader);

    expect($references)->toHaveCount(1)
        ->and($references[0]['hidden'])->toBeTrue()
        ->and($references[0]['element'])->toBeNull()
        ->and($references[0]['linkText'])->toBeNull()
        ->and($references[0]['rawHref'])->toBeNull();
});
