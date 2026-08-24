<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\helpers;

use Craft;
use johnhenry\linkaudit\exceptions\UnsafeUrlException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * Guards server-side URL fetches against SSRF (Server-Side Request Forgery).
 *
 * Every URL this plugin checks came out of a field an author can type into, so
 * nothing goes out on the wire without passing through here first. The guard:
 *
 *  - Restricts the scheme to http / https only;
 *  - Resolves the host to every IPv4 and IPv6 address it maps to; and
 *  - Rejects the request if any resolved address falls inside a private,
 *    loopback, link-local, or otherwise reserved range (e.g. the cloud
 *    metadata endpoint 169.254.169.254, RFC 1918 ranges, or ::1).
 *
 * Because DNS can resolve to a public address on the first lookup but a private
 * one on a later hop (DNS rebinding) or via a redirect, the
 * {@see self::redirectOptions()} helper re-validates the host on every redirect
 * hop as well.
 *
 * Two things about the shape of it are load-bearing. A refusal carries a reason,
 * so the checker can report a private address as an unsafe URL and a host that
 * will not resolve as an ordinary unreachable one. And resolutions are memoised
 * for the life of the process, because a batch of five hundred links on one
 * domain should cost one DNS lookup, not five hundred.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class UrlSafety
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var string[] The only URL schemes permitted for outbound fetches.
     */
    public const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * @var int The maximum number of redirects a guarded fetch may follow.
     */
    public const MAX_REDIRECTS = 5;

    // =========================================================================
    // Private Properties
    // =========================================================================

    /**
     * @var array<string, string[]> Hosts already resolved this process, keyed by
     * lowercased host. A queue worker is short lived, so this cannot go stale
     * for long, and holding one answer per host also narrows the window a DNS
     * rebinding attack has to work in.
     */
    private static array $_resolved = [];

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Asserts that a hostname (or IP literal) resolves only to public addresses.
     *
     * @param string $host The hostname or IP literal to validate.
     * @return void
     * @throws UnsafeUrlException If the host resolves to a private or reserved
     *                            address, or cannot be resolved at all.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function assertHostIsPublic(string $host): void
    {
        // Exempt hosts this install actually serves: the site's own hostname
        // comes from Craft's site config, not from user input, and local or
        // intranet installs legitimately resolve to private addresses.
        if (self::_isOwnSiteHost($host)) {
            return;
        }

        $ips = self::_resolveHost($host);

        if ($ips === []) {
            throw new UnsafeUrlException(
                'The host could not be resolved.',
                UnsafeUrlException::REASON_DNS,
            );
        }

        foreach ($ips as $ip) {
            if (self::isPrivateIp($ip)) {
                throw new UnsafeUrlException(
                    'The host resolves to a private or reserved network address.',
                    UnsafeUrlException::REASON_PRIVATE_IP,
                );
            }
        }
    }

    /**
     * Asserts that the given URL is safe to fetch server-side.
     *
     * @param string $url The URL to validate.
     * @return void
     * @throws UnsafeUrlException If the URL is malformed, uses a disallowed
     *                            scheme, or resolves to a private or reserved
     *                            address.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new UnsafeUrlException(
                'The URL is not valid.',
                UnsafeUrlException::REASON_MALFORMED,
            );
        }

        $scheme = strtolower($parts['scheme']);

        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new UnsafeUrlException(
                'Only http and https URLs may be fetched.',
                UnsafeUrlException::REASON_SCHEME,
            );
        }

        self::assertHostIsPublic($parts['host']);
    }

    /**
     * Forgets every memoised DNS resolution.
     *
     * Only wanted by tests, which run many cases in one process; a real request
     * or queue job never lives long enough to need it.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function flushResolutionCache(): void
    {
        self::$_resolved = [];
    }

    /**
     * Returns Guzzle client options that re-validate the host on every redirect
     * hop, preventing a public URL from redirecting to an internal target.
     *
     * @param int $maxRedirects How many hops may be followed.
     * @param bool $trackRedirects Whether the chain is recorded on the final
     *                             response, which is how the checker knows a
     *                             redirect happened and whether it was permanent.
     * @return array<string, mixed> The client options.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function guzzleRedirectConfig(
        int $maxRedirects = self::MAX_REDIRECTS,
        bool $trackRedirects = false,
    ): array {
        return ['allow_redirects' => self::redirectOptions($maxRedirects, $trackRedirects)];
    }

    /**
     * Returns whether the given IP address is in a private, loopback,
     * link-local, or otherwise reserved range.
     *
     * Covers (non-exhaustively): 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16,
     * 127.0.0.0/8, 169.254.0.0/16, 0.0.0.0/8, ::1, fc00::/7, fe80::/10, and
     * IPv4-mapped IPv6 addresses.
     *
     * @param string $ip The IP address to test.
     * @return bool Whether the address is private or reserved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function isPrivateIp(string $ip): bool
    {
        // Unwrap IPv4-mapped IPv6 addresses (e.g. ::ffff:169.254.169.254) so
        // the private-range check below applies to the embedded IPv4 address.
        if (stripos($ip, '::ffff:') === 0) {
            $mapped = substr($ip, 7);

            if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ip = $mapped;
            }
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            // Not a parseable IP, treat as unsafe.
            return true;
        }

        // PHP's own reserved/private range filter covers the common cases for
        // both IPv4 and IPv6 (RFC 1918, loopback, link-local, unique-local).
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        // Belt-and-braces explicit ranges that some PHP builds miss.
        return self::_isInReservedRange($ip);
    }

    /**
     * The `allow_redirects` option a guarded fetch uses.
     *
     * @param int $maxRedirects How many hops may be followed.
     * @param bool $trackRedirects Whether the chain is recorded on the final
     *                             response.
     * @return array<string, mixed> The option value.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function redirectOptions(
        int $maxRedirects = self::MAX_REDIRECTS,
        bool $trackRedirects = false,
    ): array {
        return [
            'max' => max(0, $maxRedirects),
            'strict' => true,
            'referer' => false,
            'protocols' => self::ALLOWED_SCHEMES,
            'track_redirects' => $trackRedirects,
            'on_redirect' => static function(
                RequestInterface $request,
                ResponseInterface $response,
                UriInterface $uri,
            ): void {
                // Throws UnsafeUrlException if the redirect target host
                // resolves to a private/reserved address.
                self::assertHostIsPublic($uri->getHost());
            },
        ];
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * The DNS records of one type for a host, or an empty list when the lookup
     * fails.
     *
     * A host that does not resolve is an ordinary answer here, not a fault, and
     * `dns_get_record()` raises a warning as well as returning false when it
     * cannot get one. The handler is put up for the length of the call only and
     * taken down in a `finally`, so nothing else in the process loses its own
     * error reporting to it.
     *
     * @param string $host The hostname to look up.
     * @param int $type The `DNS_*` record type.
     * @return array<int, array<string, mixed>> The records.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private static function _dnsRecords(string $host, int $type): array
    {
        set_error_handler(static fn(): bool => true);

        try {
            $records = dns_get_record($host, $type);
        } finally {
            restore_error_handler();
        }

        return is_array($records) ? $records : [];
    }

    /**
     * Explicit range checks for addresses some PHP filter builds do not flag.
     *
     * @param string $ip The IP address to test.
     * @return bool Whether the address is in a reserved range.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private static function _isInReservedRange(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);

            if ($long === false) {
                return true;
            }

            $reserved = [
                ['10.0.0.0', 8],
                ['172.16.0.0', 12],
                ['192.168.0.0', 16],
                ['127.0.0.0', 8],
                ['169.254.0.0', 16],
                ['0.0.0.0', 8],
                ['100.64.0.0', 10],
                ['192.0.0.0', 24],
                ['198.18.0.0', 15],
                ['192.0.2.0', 24],
                ['240.0.0.0', 4],
            ];

            foreach ($reserved as [$subnet, $mask]) {
                $subnetLong = ip2long($subnet);
                $maskLong = -1 << (32 - $mask);

                if (($long & $maskLong) === ($subnetLong & $maskLong)) {
                    return true;
                }
            }

            return false;
        }

        // IPv6 loopback / unspecified / unique-local / link-local.
        $normalised = strtolower($ip);

        return $normalised === '::1'
            || $normalised === '::'
            || str_starts_with($normalised, 'fc')
            || str_starts_with($normalised, 'fd')
            || str_starts_with($normalised, 'fe8')
            || str_starts_with($normalised, 'fe9')
            || str_starts_with($normalised, 'fea')
            || str_starts_with($normalised, 'feb');
    }

    /**
     * Whether the host is one this install serves.
     *
     * Matched against the hostname of each configured site's base URL, exactly:
     * a suffix match would let `evil-example.com` past a site on `example.com`,
     * and a wildcard would hand an attacker every subdomain of it.
     *
     * @param string $host The hostname to test.
     * @return bool Whether the host belongs to this installation.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private static function _isOwnSiteHost(string $host): bool
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return false;
        }

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteHost = parse_url((string)$site->getBaseUrl(), PHP_URL_HOST);

            if (is_string($siteHost) && strtolower($siteHost) === $host) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves a hostname to every IPv4 and IPv6 address it maps to.
     *
     * IP literals are returned as-is (a single-element list).
     *
     * @param string $host The hostname or IP literal to resolve.
     * @return string[] The resolved IP addresses.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private static function _resolveHost(string $host): array
    {
        // Strip brackets from IPv6 literals (e.g. [::1]).
        $host = strtolower(trim($host, '[]'));

        // Already an IP literal, nothing to resolve.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        if (isset(self::$_resolved[$host])) {
            return self::$_resolved[$host];
        }

        $ips = [];

        // IPv4 (A records).
        foreach (self::_dnsRecords($host, DNS_A) as $record) {
            if (!empty($record['ip'])) {
                $ips[] = $record['ip'];
            }
        }

        // IPv6 (AAAA records).
        foreach (self::_dnsRecords($host, DNS_AAAA) as $record) {
            if (!empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        // Fallback to gethostbyname when DNS records are unavailable.
        if ($ips === []) {
            $resolved = gethostbyname($host);

            if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP)) {
                $ips[] = $resolved;
            }
        }

        $ips = array_values(array_unique($ips));

        // Only a successful resolution is worth remembering. A failure is often
        // a blip, and caching it would hold every link on that host broken for
        // the rest of the run.
        if ($ips !== []) {
            self::$_resolved[$host] = $ips;
        }

        return $ips;
    }
}
