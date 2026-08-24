<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\helpers;

use Throwable;

/**
 * Tells a link that is broken from a link that simply will not talk to robots.
 *
 * This is the difference between a report an author acts on and a report an
 * author stops reading. A LinkedIn profile answering 999, or a Cloudflare
 * challenge page behind a 403, says nothing at all about whether the page is
 * still there: a person clicking that link gets the page. Calling either one
 * broken is the fastest way to make the whole plugin untrustworthy, so they are
 * reported separately and never counted as broken.
 *
 * Pure static and settings-free, in the same spirit as {@see UrlNormaliser}: the
 * caller passes in the response and the configured host list, and gets back the
 * name of the signature that matched, or null. Everything it knows is in the
 * table below, and a project that meets a protection this does not recognise can
 * either add the host to the `botHostileHosts` setting or listen to
 * {@see \johnhenry\linkaudit\services\HttpChecker::EVENT_DEFINE_VERDICT}.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class BotBlockHeuristics
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * Akamai's bot manager, which fronts a good deal of retail and banking.
     */
    public const SIGNATURE_AKAMAI = 'akamai';

    /**
     * AWS WAF behind CloudFront, which answers a request it does not like with
     * a 403 and a page reading "Request blocked". Common on government and
     * public-sector sites.
     */
    public const SIGNATURE_AWS_WAF = 'aws-waf';

    /**
     * A 503 carrying a CAPTCHA, which is how Amazon and several others say no.
     */
    public const SIGNATURE_CAPTCHA_503 = 'captcha-503';

    /**
     * Cloudflare's interstitial challenge, by header or by page marker.
     */
    public const SIGNATURE_CLOUDFLARE_CHALLENGE = 'cloudflare-challenge';

    /**
     * Cloudflare error 1020: the site owner's own firewall rule.
     */
    public const SIGNATURE_CLOUDFLARE_1020 = 'cloudflare-1020';

    /**
     * DataDome.
     */
    public const SIGNATURE_DATADOME = 'datadome';

    /**
     * A host named in the `botHostileHosts` setting.
     */
    public const SIGNATURE_HOST_LIST = 'host-list';

    /**
     * Imperva, formerly Incapsula.
     */
    public const SIGNATURE_IMPERVA = 'imperva';

    /**
     * LinkedIn's 999, which no other host uses and which every LinkedIn URL
     * gives an automated request.
     */
    public const SIGNATURE_LINKEDIN_999 = 'linkedin-999';

    /**
     * A status code outside the range HTTP defines, which is what LinkedIn's 999
     * actually looks like by the time it reaches us. Guzzle's PSR-7 response
     * refuses to hold anything at or above 600, so the code itself never
     * survives; what arrives is a request exception saying the response could
     * not be built. Nothing legitimate answers that way.
     */
    public const SIGNATURE_NONSTANDARD_STATUS = 'nonstandard-status';

    /**
     * HUMAN, formerly PerimeterX.
     */
    public const SIGNATURE_PERIMETERX = 'perimeterx';

    /**
     * A social network bouncing an anonymous request to its login wall.
     */
    public const SIGNATURE_SOCIAL_LOGIN_WALL = 'social-login-wall';

    /**
     * @var string[] Markers that identify a Cloudflare challenge page in the
     * first couple of kilobytes of the body.
     */
    private const _CLOUDFLARE_BODY_MARKERS = [
        '__cf_chl',
        'cf-browser-verification',
        'cf_chl_opt',
        'challenge-platform',
        'just a moment',
    ];

    /**
     * @var string[] Path fragments that mean a social network sent an anonymous
     * request to sign in rather than to the page it asked for.
     */
    private const _LOGIN_PATH_MARKERS = [
        '/accounts/login',
        '/checkpoint',
        '/i/flow/login',
        '/login',
        '/signin',
        '/uas/login',
    ];

    /**
     * @var string[] Hosts that answer an anonymous request with a login wall
     * rather than the page, matched on the registrable domain.
     */
    private const _SOCIAL_HOSTS = [
        'facebook.com',
        'instagram.com',
        'linkedin.com',
        'threads.net',
        'twitter.com',
        'x.com',
    ];

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Which bot-block signature a response matches, if any.
     *
     * @param string $url The URL that was requested.
     * @param int|null $httpStatus The status code that came back.
     * @param array<string, string[]> $headers The response headers, as Guzzle
     *                                         hands them over.
     * @param string|null $finalUrl Where a redirect chain ended up, when it went
     *                              anywhere.
     * @param string|null $bodySnippet The first couple of kilobytes of the body,
     *                                 lowercased. Only a GET has one.
     * @param string[] $botHostileHosts Extra hosts from the settings, any
     *                                  non-2xx from which counts as a block.
     * @return string|null The SIGNATURE_* constant that matched, or null when
     *                     the response is the host's own honest answer.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function detect(
        string $url,
        ?int $httpStatus,
        array $headers = [],
        ?string $finalUrl = null,
        ?string $bodySnippet = null,
        array $botHostileHosts = [],
    ): ?string {
        if ($httpStatus === null) {
            return null;
        }

        $host = UrlNormaliser::hostOf($url);

        // A social network that answered by putting a login page in front of the
        // request is the one signature that hides behind a 200.
        if (self::_isSocialLoginWall($host, $finalUrl)) {
            return self::SIGNATURE_SOCIAL_LOGIN_WALL;
        }

        if ($httpStatus >= 200 && $httpStatus < 300) {
            return null;
        }

        // 999 is LinkedIn's and nobody else's.
        if ($httpStatus === 999) {
            return self::SIGNATURE_LINKEDIN_999;
        }

        $header = self::_normaliseHeaders($headers);
        $body = $bodySnippet !== null ? strtolower($bodySnippet) : '';

        $signature = self::_vendorSignature($header, $body, $httpStatus);

        if ($signature !== null) {
            return $signature;
        }

        if (self::matchesHostList($host, $botHostileHosts)) {
            return self::SIGNATURE_HOST_LIST;
        }

        return null;
    }

    /**
     * Flattens the `botHostileHosts` setting, which arrives as editable table
     * rows, into a plain list of hosts.
     *
     * @param array<int, mixed> $rows The setting value.
     * @return string[] The hosts, lowercased and trimmed.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function hostList(array $rows): array
    {
        $hosts = [];

        foreach ($rows as $row) {
            foreach (is_array($row) ? $row : [$row] as $value) {
                if (!is_string($value)) {
                    continue;
                }

                $host = strtolower(trim($value));

                // An author who pastes a whole URL in means its host.
                if (str_contains($host, '//')) {
                    $host = UrlNormaliser::hostOf($host);
                }

                if ($host !== '') {
                    $hosts[] = $host;
                }
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * Whether a transport error is really a status code HTTP does not define,
     * which the client refused to build a response around.
     *
     * LinkedIn's 999 is the case that matters. It arrives as a request exception
     * wrapping an argument error about the status range, so the only way to tell
     * it from an ordinary failed connection is to read down the chain.
     *
     * @param Throwable $error The transport error.
     * @return bool Whether the far end answered with a status outside the
     *              standard range.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function isNonstandardStatusError(Throwable $error): bool
    {
        $candidate = $error;

        while ($candidate !== null) {
            if (str_contains(strtolower($candidate->getMessage()), 'status code must be')) {
                return true;
            }

            $candidate = $candidate->getPrevious();
        }

        return false;
    }

    /**
     * Whether a host is named in a configured host list, either exactly or as a
     * subdomain of an entry.
     *
     * Subdomains count because an author writing `linkedin.com` means the site,
     * not one hostname of it.
     *
     * @param string $host The host to test.
     * @param string[] $hosts The configured hosts.
     * @return bool Whether the host is on the list.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function matchesHostList(string $host, array $hosts): bool
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return false;
        }

        foreach ($hosts as $candidate) {
            $candidate = strtolower(trim($candidate));

            if ($candidate === '') {
                continue;
            }

            if ($host === $candidate || str_ends_with($host, '.' . $candidate)) {
                return true;
            }
        }

        return false;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Whether any of the given markers appears in a haystack.
     *
     * @param string $haystack The lowercased text to search.
     * @param string[] $markers The markers to look for.
     * @return bool Whether one of them is there.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private static function _containsAny(string $haystack, array $markers): bool
    {
        if ($haystack === '') {
            return false;
        }

        foreach ($markers as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a social network answered with its login wall instead of the page.
     *
     * @param string $host The host that was asked.
     * @param string|null $finalUrl Where the chain ended up.
     * @return bool Whether this is a login wall.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private static function _isSocialLoginWall(string $host, ?string $finalUrl): bool
    {
        if ($finalUrl === null || !self::matchesHostList($host, self::_SOCIAL_HOSTS)) {
            return false;
        }

        $path = strtolower((string)parse_url($finalUrl, PHP_URL_PATH));

        return self::_containsAny($path, self::_LOGIN_PATH_MARKERS);
    }

    /**
     * Flattens response headers into lowercased names against lowercased values.
     *
     * @param array<string, string[]> $headers The headers as Guzzle hands them
     *                                         over.
     * @return array<string, string> The flattened headers.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private static function _normaliseHeaders(array $headers): array
    {
        $normalised = [];

        foreach ($headers as $name => $values) {
            $normalised[strtolower($name)] = strtolower(implode(', ', (array)$values));
        }

        return $normalised;
    }

    /**
     * Which protection vendor's fingerprint a non-2xx response carries.
     *
     * @param array<string, string> $header The flattened response headers.
     * @param string $body The lowercased body snippet, empty when there is none.
     * @param int $httpStatus The status code.
     * @return string|null The SIGNATURE_* constant that matched.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private static function _vendorSignature(array $header, string $body, int $httpStatus): ?string
    {
        $server = $header['server'] ?? '';
        $cookies = $header['set-cookie'] ?? '';

        if (str_contains($header['cf-mitigated'] ?? '', 'challenge')) {
            return self::SIGNATURE_CLOUDFLARE_CHALLENGE;
        }

        if (self::_containsAny($body, ['error code: 1020', 'error 1020'])) {
            return self::SIGNATURE_CLOUDFLARE_1020;
        }

        // Only a body can tell a Cloudflare challenge from a Cloudflare-fronted
        // site returning an ordinary error, and only a GET has one.
        if (str_contains($server, 'cloudflare') && self::_containsAny($body, self::_CLOUDFLARE_BODY_MARKERS)) {
            return self::SIGNATURE_CLOUDFLARE_CHALLENGE;
        }

        if (isset($header['x-datadome']) || str_contains($cookies, 'datadome=')) {
            return self::SIGNATURE_DATADOME;
        }

        foreach (array_keys($header) as $name) {
            if (str_starts_with($name, 'x-px')) {
                return self::SIGNATURE_PERIMETERX;
            }
        }

        if (self::_containsAny($body, ['_pxhd', 'px-captcha'])) {
            return self::SIGNATURE_PERIMETERX;
        }

        if (isset($header['x-iinfo']) || str_contains($cookies, 'visid_incap')) {
            return self::SIGNATURE_IMPERVA;
        }

        if (str_contains($server, 'akamaighost') && $httpStatus === 403) {
            return self::SIGNATURE_AKAMAI;
        }

        // A 403 that came back through CloudFront is the web application
        // firewall in front of the site, not the site itself. Only a 403: an
        // ordinary 404 carries the same CloudFront headers and means exactly
        // what it says.
        if (
            $httpStatus === 403
            && (
                str_contains($header['x-cache'] ?? '', 'cloudfront')
                || str_contains($header['via'] ?? '', 'cloudfront')
                || isset($header['x-amzn-waf-action'])
            )
        ) {
            return self::SIGNATURE_AWS_WAF;
        }

        if ($httpStatus === 503 && self::_containsAny($body, ['captcha', 'automated access'])) {
            return self::SIGNATURE_CAPTCHA_503;
        }

        return null;
    }
}
