<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\linkaudit\exceptions\UnsafeUrlException;
use johnhenry\linkaudit\helpers\UrlSafety;

// ---------------------------------------------------------------------------
// The guard on every outbound fetch
//
// Every URL this plugin requests came out of a field somebody can type into, so
// the guard is the only thing between an author and this server's own network.
// Two answers matter and they are not the same: a host that resolves somewhere
// private is a refusal worth reporting, and a host that resolves nowhere at all
// is an ordinary broken link.
//
// The DNS lookups run with a scoped error handler rather than an `@`, so a host
// that does not resolve must come back as a plain refusal without leaking a
// warning into whatever else is running.
//
// Helper names carry a `safety` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/** The reason the guard gave for refusing a URL, or null when it allowed it. */
function safetyReasonFor(string $url): ?string
{
    try {
        UrlSafety::assertSafeUrl($url);
    } catch (UnsafeUrlException $e) {
        return $e->reason;
    }

    return null;
}

beforeEach(function() {
    UrlSafety::flushResolutionCache();
});

describe('UrlSafety::assertSafeUrl', function() {
    it('refuses a loopback or private address', function() {
        expect(safetyReasonFor('http://127.0.0.1/admin'))->toBe(UnsafeUrlException::REASON_PRIVATE_IP)
            ->and(safetyReasonFor('http://10.0.0.5/'))->toBe(UnsafeUrlException::REASON_PRIVATE_IP)
            ->and(safetyReasonFor('http://192.168.1.1/'))->toBe(UnsafeUrlException::REASON_PRIVATE_IP)
            ->and(safetyReasonFor('http://169.254.169.254/latest/meta-data/'))
            ->toBe(UnsafeUrlException::REASON_PRIVATE_IP)
            ->and(safetyReasonFor('http://[::1]/'))->toBe(UnsafeUrlException::REASON_PRIVATE_IP);
    });

    it('refuses a scheme it never fetches', function() {
        expect(safetyReasonFor('ftp://example.com/file'))->toBe(UnsafeUrlException::REASON_SCHEME)
            ->and(safetyReasonFor('javascript:alert(1)'))->toBe(UnsafeUrlException::REASON_MALFORMED);
    });

    it('refuses a host that resolves nowhere, and says that is why', function() {
        expect(safetyReasonFor('https://no-such-host.invalid/page'))
            ->toBe(UnsafeUrlException::REASON_DNS);
    });

    it('leaves no error handler of its own behind after a failed lookup', function() {
        $seen = null;
        set_error_handler(static function(int $number, string $message) use (&$seen): bool {
            $seen = $message;

            return true;
        });

        safetyReasonFor('https://another-host-that-is-not-there.invalid/');

        // The guard's handler is scoped to the lookup, so this one is still the
        // handler in force once it is done with.
        trigger_error('after the lookup', E_USER_WARNING);
        restore_error_handler();

        expect($seen)->toBe('after the lookup');
    });

    it('lets the hostname this installation serves through', function() {
        $host = parse_url(
            (string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(),
            PHP_URL_HOST,
        );

        expect(safetyReasonFor("https://$host/somewhere"))->toBeNull();
    });
});
