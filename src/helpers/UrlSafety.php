<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\helpers;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use johnhenry\ipguard\Dns;
use johnhenry\ipguard\IpRange;
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
 * @author John Henry Donovan <info@johnhenry.ie>
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
     * lowercased host. The address checked here is the one the connection is
     * pinned to (see pinningMiddleware()), so a second lookup can't swap in a
     * private one.
     */
    private static array $_resolved = [];

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Asserts that a hostname (or IP literal) resolves only to public addresses.
     *
     * The install's own sites are exempt, but only on their exact scheme, host
     * and port: another port on the same host is another service.
     *
     * @param string $host The hostname or IP literal to validate.
     * @param string $scheme The URL's scheme.
     * @param int|null $port The URL's port, or null for the scheme's default.
     * @return void
     * @throws UnsafeUrlException If the host resolves to a private or reserved
     *                            address, or cannot be resolved at all.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function assertHostIsPublic(string $host, string $scheme = 'https', ?int $port = null): void
    {
        self::publicAddressFor($host, $scheme, $port);
    }

    /**
     * Resolves a host, checks every address it has is public, and returns the
     * one to connect to.
     *
     * @param string $host The hostname or IP literal.
     * @param string $scheme The URL's scheme.
     * @param int|null $port The URL's port, or null for the scheme's default.
     * @return string|null The address to pin the connection to, or null where
     *                     there's nothing to pin (an IP literal, or one of the
     *                     install's own sites).
     * @throws UnsafeUrlException If the host is private, reserved or unresolvable.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function publicAddressFor(string $host, string $scheme = 'https', ?int $port = null): ?string
    {
        // A local or intranet install legitimately resolves to a private
        // address, so its own sites are exempt, on their exact origin only.
        if (IpRange::isOwnSiteOrigin($scheme, $host, $port)) {
            return null;
        }

        if (preg_match('/^[0-9]+$|^0x[0-9a-f]+$/i', trim($host, '[]')) === 1) {
            // `2130706433` or `0x7f000001` is 127.0.0.1 to cURL, but DNS may
            // answer it through a search domain with something public.
            throw new UnsafeUrlException(
                'The host is a numeric address in a form that is not allowed.',
                UnsafeUrlException::REASON_PRIVATE_IP,
            );
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

        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            return null;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $ip;
            }
        }

        return $ips[0];
    }

    /**
     * Guzzle middleware that checks every request it sees, redirect hops
     * included, and pins the connection to the address that was checked.
     *
     * Without the pin cURL resolves the host a second time, and a host that
     * answers the check with a public address and the connection with a
     * private one (DNS rebinding) would get through. Push it onto the handler
     * stack so it sits below the redirect middleware. Requests through a proxy
     * are left to the proxy, which does its own resolving.
     *
     * @return callable The middleware.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function pinningMiddleware(): callable
    {
        return static fn(callable $handler): callable => static function(RequestInterface $request, array $options) use ($handler) {
            if (!empty($options['proxy'])) {
                return $handler($request, $options);
            }

            $uri = $request->getUri();
            $scheme = strtolower($uri->getScheme());

            try {
                if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
                    throw new UnsafeUrlException('Only http and https URLs may be fetched.', UnsafeUrlException::REASON_SCHEME);
                }

                $ip = self::publicAddressFor($uri->getHost(), $scheme, $uri->getPort());
            } catch (UnsafeUrlException $e) {
                return Create::rejectionFor($e);
            }

            if ($ip !== null) {
                $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);
                $address = str_contains($ip, ':') ? "[$ip]" : $ip;
                $options['curl'][CURLOPT_RESOLVE][] = $uri->getHost() . ":$port:$address";
            }

            return $handler($request, $options);
        };
    }

    /**
     * A handler stack with pinningMiddleware() in place, for a client that
     * fetches URLs content editors control.
     *
     * @return HandlerStack The handler stack.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function pinnedHandlerStack(): HandlerStack
    {
        $stack = HandlerStack::create();
        $stack->push(self::pinningMiddleware(), 'link-audit-pin');

        return $stack;
    }

    /**
     * Asserts that the given URL is safe to fetch server-side.
     *
     * @param string $url The URL to validate.
     * @return void
     * @throws UnsafeUrlException If the URL is malformed, uses a disallowed
     *                            scheme, or resolves to a private or reserved
     *                            address.
     * @author John Henry Donovan <info@johnhenry.ie>
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

        self::assertHostIsPublic($parts['host'], $scheme, isset($parts['port']) ? (int)$parts['port'] : null);
    }

    /**
     * Forgets every memoised DNS resolution.
     *
     * Only wanted by tests, which run many cases in one process; a real request
     * or queue job never lives long enough to need it.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function isPrivateIp(string $ip): bool
    {
        return IpRange::isPrivate($ip);
    }

    /**
     * The `allow_redirects` option a guarded fetch uses.
     *
     * @param int $maxRedirects How many hops may be followed.
     * @param bool $trackRedirects Whether the chain is recorded on the final
     *                             response.
     * @return array<string, mixed> The option value.
     * @author John Henry Donovan <info@johnhenry.ie>
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
                self::assertHostIsPublic($uri->getHost(), $uri->getScheme(), $uri->getPort());
            },
        ];
    }

    // =========================================================================
    // Private Methods
    // =========================================================================
    /**
     * Resolves a hostname to every IPv4 and IPv6 address it maps to.
     *
     * IP literals are returned as-is (a single-element list).
     *
     * @param string $host The hostname or IP literal to resolve.
     * @return string[] The resolved IP addresses.
     * @author John Henry Donovan <info@johnhenry.ie>
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

        $ips = Dns::addressesFor($host);

        // Only a successful resolution is worth remembering. A failure is often
        // a blip, and caching it would hold every link on that host broken for
        // the rest of the run.
        if ($ips !== []) {
            self::$_resolved[$host] = $ips;
        }

        return $ips;
    }
}
