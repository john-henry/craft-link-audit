<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\helpers;

use johnhenry\linkaudit\enums\SchemeKind;

/**
 * Turns an author-typed href into the one canonical string the plugin hashes.
 *
 * This is the dedupe pillar. Two links that a browser would send to the same
 * place have to come out of here identical, or the same URL gets checked twice
 * and appears twice in the report. It is also why the class is pure static and
 * takes everything it needs as arguments: no settings lookup, no site lookup, no
 * database, so it can be reasoned about and tested on its own.
 *
 * The pipeline, in order:
 *
 *  1. Strip tabs and newlines, trim, decode HTML entities (`&amp;` to `&`);
 *  2. Resolve a relative reference against the given base URL (RFC 3986 §5.2);
 *  3. Lowercase the scheme and host, and convert an international host to
 *     punycode;
 *  4. Drop the default port (80 on http, 443 on https);
 *  5. Drop the fragment (the caller keeps the original href on the reference
 *     row, so the control panel can still show what the author typed, and
 *     {@see self::withFragment()} puts it back for the one path that wants it);
 *  6. Drop the userinfo, credentials and all;
 *  7. Normalise percent-encoding: uppercase the hex digits, decode anything
 *     unreserved; and
 *  8. Strip tracking parameters, when the caller asks for it.
 *
 * Deliberately not done: sorting query parameters and stripping trailing
 * slashes. Both look like tidying and both change where a real server sends
 * you.
 *
 * Callers should ask {@see self::classifyScheme()} first. A null out of
 * {@see self::normalise()} means "nothing to check here", which covers both a
 * skippable scheme and a href too broken to parse; the classification is what
 * tells the two apart.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class UrlNormaliser
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var string[] Query parameter names dropped as tracking noise, matched
     * case-insensitively.
     */
    public const TRACKING_PARAMS = [
        '_hsenc',
        '_hsmi',
        'dclid',
        'fbclid',
        'gbraid',
        'gclid',
        'igshid',
        'mc_cid',
        'mc_eid',
        'msclkid',
        'ttclid',
        'wbraid',
        'yclid',
    ];

    /**
     * @var string[] Query parameter name prefixes dropped as tracking noise,
     * matched case-insensitively.
     */
    public const TRACKING_PARAM_PREFIXES = [
        'utm_',
    ];

    /**
     * @var string[] The schemes this plugin checks.
     */
    private const _CHECKABLE_SCHEMES = ['http', 'https'];

    /**
     * @var array<string, int> The port each checkable scheme uses by default,
     * and therefore the port that carries no meaning in the URL.
     */
    private const _DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /**
     * @var int The longest a host name may be, in octets, per RFC 1035. Also the
     * width of the URLs table's host column, which is not a coincidence.
     */
    private const _MAX_HOST_OCTETS = 253;

    /**
     * @var string[] Schemes that address something other than a document, so
     * there is nothing to fetch and nothing to report.
     */
    private const _SKIPPABLE_SCHEMES = ['data', 'javascript', 'mailto', 'sms', 'tel'];

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Says whether a href is worth checking, worth ignoring, or something the
     * plugin has never heard of.
     *
     * A relative reference counts as checkable: it resolves to http or https
     * once it meets its base URL.
     *
     * @param string $url The raw href, exactly as it appeared in the content.
     * @return SchemeKind What the scheme means to the checker.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function classifyScheme(string $url): SchemeKind
    {
        $candidate = self::_clean($url);

        if ($candidate === '' || str_starts_with($candidate, '#')) {
            return SchemeKind::Skippable;
        }

        if (preg_match('/^([a-z][a-z0-9+\-.]*):/i', $candidate, $matches) !== 1) {
            return SchemeKind::Checkable;
        }

        $scheme = strtolower($matches[1]);

        if (in_array($scheme, self::_CHECKABLE_SCHEMES, true)) {
            return SchemeKind::Checkable;
        }

        if (in_array($scheme, self::_SKIPPABLE_SCHEMES, true)) {
            return SchemeKind::Skippable;
        }

        return SchemeKind::Unknown;
    }

    /**
     * The fragment a URL carries, or null when it has none.
     *
     * @param string $url The URL to read.
     * @return string|null The fragment, without its leading hash.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function fragmentOf(string $url): ?string
    {
        $fragment = parse_url($url, PHP_URL_FRAGMENT);

        return is_string($fragment) && $fragment !== '' ? $fragment : null;
    }

    /**
     * The sha1 of a normalised URL: the dedupe key, and the value the URLs
     * table carries a unique index on.
     *
     * @param string $normalisedUrl A URL that has already been through
     *                              {@see self::normalise()}.
     * @return string The 40 character hash.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function hash(string $normalisedUrl): string
    {
        return sha1($normalisedUrl);
    }

    /**
     * The host of a URL, lowercased, or an empty string when it has none.
     *
     * @param string $url The URL to read.
     * @return string The host.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function hostOf(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? mb_strtolower($host, 'UTF-8') : '';
    }

    /**
     * Whether a URL points at one of the given base URLs' hosts.
     *
     * Host and port only, never the path: a site served from a subfolder still
     * shares its host with everything else on that domain, and treating a link
     * to a sibling subfolder as external would fire an HTTP request at your own
     * server for no reason.
     *
     * Pass the base URLs in from the caller (`$site->getBaseUrl()` for each
     * site) rather than having this class reach for them, so it stays pure.
     *
     * @param string $url A normalised, absolute URL.
     * @param string[] $baseUrls The base URLs of every site to count as
     *                           internal.
     * @return bool Whether the URL belongs to one of those sites.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function isInternal(string $url, array $baseUrls): bool
    {
        $authority = self::_comparableAuthority($url);

        if ($authority === null) {
            return false;
        }

        foreach ($baseUrls as $baseUrl) {
            if (self::_comparableAuthority($baseUrl) === $authority) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalises a href into the canonical string the plugin hashes and checks.
     *
     * @param string $url The raw href, exactly as it appeared in the content.
     * @param string|null $baseUrl The base URL to resolve a relative reference
     *                             against, usually the site's. Without it, a
     *                             relative reference cannot be resolved and
     *                             null comes back.
     * @param bool $stripTrackingParams Whether tracking parameters are dropped.
     *                                  The caller reads the `stripTrackingParams`
     *                                  setting and passes the answer in.
     * @return string|null The normalised URL, or null when there is nothing to
     *                     check. Call {@see self::classifyScheme()} to tell a
     *                     skippable href from an unparseable one.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function normalise(
        string $url,
        ?string $baseUrl = null,
        bool $stripTrackingParams = true,
    ): ?string {
        if (self::classifyScheme($url) !== SchemeKind::Checkable) {
            return null;
        }

        $candidate = self::_clean($url);

        $absolute = self::_absolutise($candidate, $baseUrl);

        if ($absolute === null) {
            return null;
        }

        $parts = parse_url($absolute);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if (!in_array($scheme, self::_CHECKABLE_SCHEMES, true)) {
            return null;
        }

        $host = self::_normaliseHost($parts['host']);

        if ($host === '') {
            return null;
        }

        $port = isset($parts['port']) ? (int)$parts['port'] : null;

        if ($port === self::_DEFAULT_PORTS[$scheme]) {
            $port = null;
        }

        $query = self::_normaliseQuery($parts['query'] ?? null, $stripTrackingParams);

        // Userinfo comes off and stays off. A password typed into a href ends up
        // on a report screen, in a notification email and in a Slack channel,
        // none of which is a place for one, and it is no part of which document
        // a server returns: `https://admin:hunter2@host/` and `https://host/`
        // are the same page, so they are the same row.
        return $scheme
            . '://'
            . $host
            . ($port !== null ? ':' . $port : '')
            . self::_normalisePath($parts['path'] ?? '')
            . ($query !== null ? '?' . $query : '');
    }

    /**
     * The scheme of a URL, lowercased, or an empty string when it has none.
     *
     * @param string $url The URL to read.
     * @return string The scheme.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function schemeOf(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme) ? strtolower($scheme) : '';
    }

    /**
     * The same URL with any fragment taken back off it.
     *
     * @param string $url The URL to strip.
     * @return string The URL, fragment and all removed.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function stripFragment(string $url): string
    {
        $hash = strpos($url, '#');

        return $hash === false ? $url : substr($url, 0, $hash);
    }

    /**
     * Puts a href's fragment back onto its normalised URL.
     *
     * The fragment-keeping mode, kept as a step of its own rather than as a flag
     * on {@see self::normalise()}, because it applies to one path and one path
     * only: an internal link, on an installation that has asked for anchor
     * fragments to be validated. Everywhere else the fragment comes off and
     * stays off, which is what keeps `/about#team` and `/about#staff` one URL
     * row and one request.
     *
     * A text directive (`#:~:text=...`) is not an anchor and never was: it tells
     * the browser to go looking for words, and no element on the page carries it
     * as a name. Those are left off, so a link using one is checked as the plain
     * page it points at.
     *
     * @param string $normalisedUrl A URL already through {@see self::normalise()}.
     * @param string $rawHref The href it came from, fragment and all.
     * @return string The URL, with the fragment on it when there was one worth
     *                keeping.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function withFragment(string $normalisedUrl, string $rawHref): string
    {
        $fragment = self::fragmentOf(self::_clean($rawHref));

        if ($fragment === null || str_starts_with($fragment, ':~:')) {
            return $normalisedUrl;
        }

        return self::stripFragment($normalisedUrl)
            . '#'
            . self::_normalisePercentEncoding(str_replace(' ', '%20', $fragment));
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Resolves a reference against a base URL, per RFC 3986 §5.2.2.
     *
     * @param string $reference The cleaned reference.
     * @param string|null $baseUrl The base URL, if there is one.
     * @return string|null The absolute URL, or null when the reference needs a
     *                     base URL and none is usable.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private static function _absolutise(string $reference, ?string $baseUrl): ?string
    {
        if (preg_match('/^[a-z][a-z0-9+\-.]*:/i', $reference) === 1) {
            return $reference;
        }

        if ($baseUrl === null) {
            return null;
        }

        $base = parse_url(self::_clean($baseUrl));

        if ($base === false || !isset($base['scheme'], $base['host'])) {
            return null;
        }

        $authority = self::_userInfo($base)
            . $base['host']
            . (isset($base['port']) ? ':' . $base['port'] : '');

        // A scheme-relative reference brings its own authority and only needs
        // the scheme.
        if (str_starts_with($reference, '//')) {
            return $base['scheme'] . ':' . $reference;
        }

        $prefix = $base['scheme'] . '://' . $authority;

        if (str_starts_with($reference, '/')) {
            return $prefix . $reference;
        }

        $basePath = $base['path'] ?? '/';

        // A query-only reference replaces the base query and keeps everything
        // to its left.
        if (str_starts_with($reference, '?')) {
            return $prefix . $basePath . $reference;
        }

        $directory = substr($basePath, 0, (int)strrpos($basePath, '/') + 1);

        if ($directory === '') {
            $directory = '/';
        }

        return $prefix . $directory . $reference;
    }

    /**
     * Strips the whitespace a browser would ignore and decodes HTML entities,
     * so `&amp;` in the source is the `&` a request would actually carry.
     *
     * @param string $url The raw href.
     * @return string The cleaned href.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private static function _clean(string $url): string
    {
        $cleaned = str_replace(["\t", "\n", "\r"], '', $url);

        return trim(html_entity_decode(trim($cleaned), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * The host and meaningful port of a URL, in the form two URLs can be
     * compared on.
     *
     * @param string $url The URL to read.
     * @return string|null The comparable authority, or null when the URL has no
     *                     host.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private static function _comparableAuthority(string $url): ?string
    {
        $parts = parse_url(self::_clean($url));

        if ($parts === false || !isset($parts['host'])) {
            return null;
        }

        $host = self::_normaliseHost($parts['host']);

        if ($host === '') {
            return null;
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $port = isset($parts['port']) ? (int)$parts['port'] : null;

        if ($port !== null && $port === (self::_DEFAULT_PORTS[$scheme] ?? null)) {
            $port = null;
        }

        return $host . ($port !== null ? ':' . $port : '');
    }

    /**
     * Whether a query parameter name is tracking noise.
     *
     * @param string $name The raw parameter name.
     * @return bool Whether it should be dropped.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private static function _isTrackingParam(string $name): bool
    {
        $name = strtolower(rawurldecode($name));

        if (in_array($name, self::TRACKING_PARAMS, true)) {
            return true;
        }

        foreach (self::TRACKING_PARAM_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lowercases a host and converts an international one to punycode.
     *
     * The punycode step is guarded: `idn_to_ascii()` needs ext-intl, and a host
     * that cannot be converted is better left lowercased than dropped.
     *
     * A host longer than a host is allowed to be comes back empty, which the
     * callers read as unparseable. No name server would answer it, so there is
     * nothing to check, and storing it would overflow the column it is bound
     * for.
     *
     * @param string $host The host as parsed.
     * @return string The normalised host, or an empty string when there is
     *                nothing usable.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private static function _normaliseHost(string $host): string
    {
        $host = mb_strtolower(trim($host), 'UTF-8');

        if ($host === '') {
            return '';
        }

        if (preg_match('/[^\x20-\x7f]/', $host) === 1 && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $host = is_string($ascii) && $ascii !== '' ? $ascii : $host;
        }

        return strlen($host) > self::_MAX_HOST_OCTETS ? '' : $host;
    }

    /**
     * Normalises a path: percent-encoding tidied, dot segments removed, and an
     * empty path written as the root it means.
     *
     * Dot segment removal and the empty-to-root rewrite are both RFC 3986
     * equivalences, so neither changes which document a server returns.
     *
     * @param string $path The path as parsed.
     * @return string The normalised path.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private static function _normalisePath(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        return self::_removeDotSegments(
            self::_normalisePercentEncoding(str_replace(' ', '%20', $path)),
        );
    }

    /**
     * Uppercases percent-encoded hex digits and decodes the characters that
     * never needed encoding, so `%7Euser` and `%7euser` both come out as
     * `~user`.
     *
     * @param string $value The path or query to normalise.
     * @return string The normalised value.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private static function _normalisePercentEncoding(string $value): string
    {
        $normalised = preg_replace_callback(
            '/%([0-9A-Fa-f]{2})/',
            static function(array $matches): string {
                $character = chr((int)hexdec($matches[1]));

                if (preg_match('/^[A-Za-z0-9\-._~]$/', $character) === 1) {
                    return $character;
                }

                return '%' . strtoupper($matches[1]);
            },
            $value,
        );

        return $normalised ?? $value;
    }

    /**
     * Normalises a query string, dropping tracking parameters when asked.
     *
     * Parsed by hand rather than with `parse_str()`, which rewrites parameter
     * names containing dots and silently collapses repeated ones. Order is left
     * exactly as the author wrote it: sorting looks tidy and changes what some
     * servers return.
     *
     * @param string|null $query The query as parsed.
     * @param bool $stripTrackingParams Whether tracking parameters are dropped.
     * @return string|null The normalised query, or null when nothing is left.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private static function _normaliseQuery(?string $query, bool $stripTrackingParams): ?string
    {
        if ($query === null || $query === '') {
            return null;
        }

        $kept = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            $separator = strpos($pair, '=');
            $name = $separator === false ? $pair : substr($pair, 0, $separator);

            if ($stripTrackingParams && self::_isTrackingParam($name)) {
                continue;
            }

            $kept[] = self::_normalisePercentEncoding(str_replace(' ', '%20', $pair));
        }

        return $kept === [] ? null : implode('&', $kept);
    }

    /**
     * Removes `.` and `..` segments from a path, per RFC 3986 §5.2.4.
     *
     * @param string $path The path to flatten.
     * @return string The flattened path.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private static function _removeDotSegments(string $path): string
    {
        $output = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '.') {
                continue;
            }

            if ($segment !== '..') {
                // Empty segments are kept: the first one is what puts the
                // leading slash back, and a trailing one is a trailing slash,
                // which this class does not touch.
                $output[] = $segment;
                continue;
            }

            // Never past the root: the leading empty segment stays.
            if (count($output) > 1) {
                array_pop($output);
            }
        }

        $flattened = implode('/', $output);

        return $flattened === '' ? '/' : $flattened;
    }

    /**
     * The userinfo portion of a parsed URL, rebuilt with its trailing `@`, or
     * an empty string when there is none.
     *
     * Only {@see self::_absolutise()} wants this, and only so that a relative
     * reference resolves against the whole of its base URL's authority. It is
     * never part of what {@see self::normalise()} returns: credentials have no
     * business being stored, rendered or emailed, and they are no part of which
     * document the far end serves.
     *
     * @param array<string, mixed> $parts The output of `parse_url()`.
     * @return string The userinfo portion.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private static function _userInfo(array $parts): string
    {
        $user = isset($parts['user']) ? (string)$parts['user'] : '';

        if ($user === '') {
            return '';
        }

        $password = isset($parts['pass']) ? (string)$parts['pass'] : '';

        return $user . ($password !== '' ? ':' . $password : '') . '@';
    }
}
