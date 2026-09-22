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

    it('refuses a loopback address dressed up to look like something else', function() {
        // The forms somebody reaches for precisely because they defeat a naive
        // check: an integer, an octal quad, a short form, and a v4 address
        // wearing a v6 coat. cURL reads every one of these as 127.0.0.1.
        //
        // What refuses them is IpRange::isPrivate() treating anything that is
        // not a valid IP as private, so an address the guard cannot parse is
        // never given the benefit of the doubt. Pinned separately below,
        // because that one line is what holds this up.
        //
        // Asserted as "refused" rather than for a particular reason: whether
        // the C library normalises these or fails to resolve them is a property
        // of the platform, and either answer is a refusal.
        foreach ([
            'http://2130706433/',
            'http://0177.0.0.1/',
            'http://127.1/',
            'http://[::ffff:127.0.0.1]/',
            'http://0.0.0.0/',
        ] as $url) {
            expect(safetyReasonFor($url))->not->toBeNull("{$url} was allowed through");
        }
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

describe('the address check underneath it', function() {
    it('treats an address it cannot parse as private', function() {
        // The single line every one of these guards rests on, in this plugin
        // and in the accessibility audit alongside it. Reading it the other way
        // round, so an unparseable address is allowed rather than refused,
        // opens every obfuscated-host bypass at once and breaks no other test:
        // ip-guard ships none of its own.
        foreach (['', 'not-an-ip', '2130706433', '0177.0.0.1', '127.1', '999.999.999.999'] as $value) {
            expect(UrlSafety::isPrivateIp($value))->toBeTrue("{$value} was treated as public");
        }
    });

    it('still lets a genuine public address through', function() {
        // The other half: failing closed is only correct if it does not refuse
        // everything.
        expect(UrlSafety::isPrivateIp('93.184.216.34'))->toBeFalse()
            ->and(UrlSafety::isPrivateIp('2606:2800:220:1:248:1893:25c8:1946'))->toBeFalse();
    });

    it('unwraps an IPv4 address wearing an IPv6 coat before judging it', function() {
        expect(UrlSafety::isPrivateIp('::ffff:169.254.169.254'))->toBeTrue()
            ->and(UrlSafety::isPrivateIp('::ffff:127.0.0.1'))->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// IPv4 addresses carried inside IPv6 ones
//
// Three different blocks put an IPv4 address inside an IPv6 one, and the guard
// judged the outside. `::ffff:127.0.0.1` was unwrapped because the check looked
// for that text; `::ffff:7f00:1`, which is the same address written the other
// way, was not, and neither was NAT64 or 6to4. On a network carrying any of
// those, the address reached is the one inside.
//
// The check reads the packed bytes now, so a spelling cannot get past it.
// ---------------------------------------------------------------------------

describe('an IPv4 address inside an IPv6 one', function() {
    it('is judged by the address it actually reaches', function(string $ip, string $reaches) {
        expect(UrlSafety::isPrivateIp($ip))->toBeTrue("$ip reaches $reaches and was treated as public");
    })->with([
        'mapped, dotted' => ['::ffff:127.0.0.1', '127.0.0.1'],
        'mapped, hex' => ['::ffff:7f00:1', '127.0.0.1'],
        'mapped, hex metadata' => ['::ffff:a9fe:a9fe', '169.254.169.254'],
        'NAT64 loopback' => ['64:ff9b::7f00:1', '127.0.0.1'],
        'NAT64 metadata' => ['64:ff9b::a9fe:a9fe', '169.254.169.254'],
        'NAT64 private' => ['64:ff9b::a00:1', '10.0.0.1'],
        '6to4 loopback' => ['2002:7f00:1::', '127.0.0.1'],
        '6to4 metadata' => ['2002:a9fe:a9fe::', '169.254.169.254'],
    ]);

    it('does not refuse a public address wearing the same coat', function() {
        // 1.1.1.1 inside a NAT64 prefix is still 1.1.1.1, and unwrapping is
        // only correct while it keeps letting that through.
        expect(UrlSafety::isPrivateIp('64:ff9b::101:101'))->toBeFalse()
            ->and(UrlSafety::isPrivateIp('::ffff:1.1.1.1'))->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// Reserved blocks that are not private but are not worth fetching either
// ---------------------------------------------------------------------------

describe('reserved address blocks', function() {
    it('refuses them', function(string $ip) {
        expect(UrlSafety::isPrivateIp($ip))->toBeTrue("$ip was treated as public");
    })->with([
        'multicast' => '224.0.0.1',
        'SSDP multicast' => '239.255.255.250',
        'IPv6 multicast' => 'ff02::1',
        '6to4 relay anycast' => '192.88.99.1',
        'TEST-NET-2' => '198.51.100.1',
        'TEST-NET-3' => '203.0.113.1',
        'future use' => '240.0.0.1',
        'broadcast' => '255.255.255.255',
    ]);
});
