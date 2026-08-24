<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\models;

use Craft;
use johnhenry\linkaudit\enums\UrlStatus;

/**
 * The result of checking one URL.
 *
 * A plain immutable value object rather than a Craft model: nothing here is
 * posted from a form or validated, it is simply what the checker learned, on its
 * way to the URL row. The HTTP checker builds one, the URL store writes it, and
 * neither has to agree on the shape of an array.
 *
 * One verdict is not a verdict at all. A 429, or a host already inside a backoff
 * window, comes back as {@see UrlStatus::Pending} with a rate-limited or
 * host-backoff reason: nothing was learned about the link, so nothing should be
 * written about it. {@see self::isDeferred()} is how a caller tells the two
 * apart, and the scheduler uses it to hand the URL back for a later pass.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class Verdict
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * The connection was refused or otherwise failed to open.
     */
    public const REASON_CONNECT = 'connect';

    /**
     * The host does not resolve.
     */
    public const REASON_DNS = 'dns';

    /**
     * The page is there, but nothing on it carries the id the link's `#fragment`
     * asked for. The address is right and the anchor is not, which is a very
     * different thing to tell an author than a missing page.
     */
    public const REASON_FRAGMENT = 'fragment';

    /**
     * The host is inside a backoff window it earned earlier, so the URL was set
     * aside without a request. Not a verdict: see {@see self::isDeferred()}.
     */
    public const REASON_HOST_BACKOFF = 'host-backoff';

    /**
     * The server answered, and the status code is the finding.
     */
    public const REASON_HTTP = 'http';

    /**
     * An author decided this URL does not need checking. A row in the ignores
     * table, with a name and a date on it, undone only by restoring the URL.
     */
    public const REASON_IGNORED = 'ignored';

    /**
     * A settings rule quiets this URL: a host or a pattern nobody wants checked.
     * No row and no author, so taking the rule out of the settings is what undoes
     * it.
     */
    public const REASON_IGNORE_RULE = 'ignore-rule';

    /**
     * An internal link resolved to no element and matched no route.
     */
    public const REASON_NO_ELEMENT = 'no-element';

    /**
     * The SSRF guard refused the URL: it resolves to a private or reserved
     * address.
     */
    public const REASON_PRIVATE_IP = 'private-ip';

    /**
     * The host asked to be left alone with a 429, and the URL was set aside
     * rather than judged. Not a verdict: see {@see self::isDeferred()}.
     */
    public const REASON_RATE_LIMITED = 'rate-limited';

    /**
     * The TLS handshake failed or the certificate did not check out.
     */
    public const REASON_SSL = 'ssl';

    /**
     * The request ran out of time.
     */
    public const REASON_TIMEOUT = 'timeout';

    /**
     * The URL is unparseable, or uses a scheme that is never fetched. Normally
     * caught during normalisation, so this only shows up when a URL row predates
     * a rule change.
     */
    public const REASON_UNSAFE_SCHEME = 'unsafe-scheme';

    // =========================================================================
    // Static Methods
    // =========================================================================

    /**
     * What a stored reason code is called on screen.
     *
     * The codes are developer vocabulary and land in the database as-is; an
     * editor reading the URL detail page gets these instead. An unrecognised
     * code (an old row from before a rename, say) is shown as it is stored
     * rather than hidden.
     *
     * @param string|null $reason The stored reason code.
     * @return string|null The label, or null when there is no reason at all.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function reasonLabel(?string $reason): ?string
    {
        if ($reason === null || $reason === '') {
            return null;
        }

        return match ($reason) {
            self::REASON_CONNECT => Craft::t('link-audit', 'Could not connect'),
            self::REASON_DNS => Craft::t('link-audit', 'Could not find that domain'),
            self::REASON_FRAGMENT => Craft::t('link-audit', 'Missing anchor on the page'),
            self::REASON_HOST_BACKOFF => Craft::t('link-audit', 'Host asked to be left alone for a while'),
            self::REASON_HTTP => Craft::t('link-audit', 'The server answered with an error'),
            self::REASON_IGNORED => Craft::t('link-audit', 'Ignored by a person'),
            self::REASON_IGNORE_RULE => Craft::t('link-audit', 'Ignored by a rule in the settings'),
            self::REASON_NO_ELEMENT => Craft::t('link-audit', 'Nothing on this site answers to it'),
            self::REASON_PRIVATE_IP => Craft::t('link-audit', 'Not safe to request'),
            self::REASON_RATE_LIMITED => Craft::t('link-audit', 'The host asked us to ease off'),
            self::REASON_SSL => Craft::t('link-audit', 'A problem with the security certificate'),
            self::REASON_TIMEOUT => Craft::t('link-audit', 'Timed out'),
            self::REASON_UNSAFE_SCHEME => Craft::t('link-audit', 'Not an http or https address'),
            default => $reason,
        };
    }

    /**
     * What a response code means, in a sentence an editor can use.
     *
     * Fed to the title attribute on the code badges, so hovering a number
     * answers the question it raises. The common codes get their own words;
     * anything else falls back to what its class says.
     *
     * @param int|null $code The HTTP status code.
     * @return string|null The plain meaning, or null when there is no code.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function httpStatusLabel(?int $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return match ($code) {
            200 => Craft::t('link-audit', 'Answered normally'),
            301 => Craft::t('link-audit', 'Moved permanently: update the link to the new address'),
            302 => Craft::t('link-audit', 'Moved temporarily: the old address is still the right one'),
            307 => Craft::t('link-audit', 'Moved temporarily: the old address is still the right one'),
            308 => Craft::t('link-audit', 'Moved permanently: update the link to the new address'),
            400 => Craft::t('link-audit', 'The server could not make sense of the request'),
            401 => Craft::t('link-audit', 'Needs a login to see'),
            403 => Craft::t('link-audit', 'The server refused the request'),
            404 => Craft::t('link-audit', 'Not found: nothing lives at that address'),
            410 => Craft::t('link-audit', 'Gone: taken down on purpose'),
            429 => Craft::t('link-audit', 'The server asked to be asked less often'),
            500 => Craft::t('link-audit', 'The server hit an error of its own'),
            502 => Craft::t('link-audit', 'A server in the middle failed to pass the request on'),
            503 => Craft::t('link-audit', 'The server could not take the request just then'),
            504 => Craft::t('link-audit', 'A server in the middle gave up waiting'),
            999 => Craft::t('link-audit', 'A made-up code some sites use to refuse robots'),
            default => match (intdiv($code, 100)) {
                2 => Craft::t('link-audit', 'Answered normally'),
                3 => Craft::t('link-audit', 'A redirect'),
                4 => Craft::t('link-audit', 'The server refused it or could not find it'),
                5 => Craft::t('link-audit', 'The server had a problem'),
                default => Craft::t('link-audit', 'A code outside the usual run of things'),
            },
        };
    }

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Constructor.
     *
     * @param UrlStatus $status The verdict itself.
     * @param int|null $httpStatus The final HTTP status code, when there was
     *                             one.
     * @param string|null $method The request method that produced the verdict,
     *                            'head' or 'get'.
     * @param string|null $finalUrl Where a redirect chain ended up.
     * @param int $redirectCount How many hops the chain took.
     * @param bool|null $redirectPermanent Whether any hop was permanent, which
     *                                     is what makes a redirect worth fixing
     *                                     in the content.
     * @param int|null $redirectStatus What the first hop answered with, 301 or
     *                                 302 and the rest. `$httpStatus` is where
     *                                 the chain ended, which is a 200 on any
     *                                 redirect worth the name, so this is the
     *                                 only number on the verdict that says a
     *                                 redirect happened.
     * @param string|null $reason One of the REASON_* constants.
     * @param string|null $message What to show the author, in their own terms.
     * @param int|null $responseTimeMs How long the request took.
     * @param int|null $retryAfterSeconds How long the host asked to be left for,
     *                                    read off a 429's Retry-After header.
     * @param int $attempts How many attempts this verdict took. Set by the
     *                      scheduler, which owns retries; the checker itself
     *                      always reports one.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function __construct(
        public readonly UrlStatus $status,
        public readonly ?int $httpStatus = null,
        public readonly ?string $method = null,
        public readonly ?string $finalUrl = null,
        public readonly int $redirectCount = 0,
        public readonly ?bool $redirectPermanent = null,
        public readonly ?int $redirectStatus = null,
        public readonly ?string $reason = null,
        public readonly ?string $message = null,
        public readonly ?int $responseTimeMs = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly int $attempts = 1,
    ) {
    }

    /**
     * Whether the URL was set aside rather than judged, so the caller should ask
     * again on a later pass and write nothing in the meantime.
     *
     * @return bool Whether this is a deferral rather than a verdict.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function isDeferred(): bool
    {
        return $this->status === UrlStatus::Pending;
    }

    /**
     * A copy of this verdict carrying an attempt count.
     *
     * @param int $attempts How many attempts the verdict took.
     * @return self The copy.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function withAttempts(int $attempts): self
    {
        return new self(
            status: $this->status,
            httpStatus: $this->httpStatus,
            method: $this->method,
            finalUrl: $this->finalUrl,
            redirectCount: $this->redirectCount,
            redirectPermanent: $this->redirectPermanent,
            redirectStatus: $this->redirectStatus,
            reason: $this->reason,
            message: $this->message,
            responseTimeMs: $this->responseTimeMs,
            retryAfterSeconds: $this->retryAfterSeconds,
            attempts: max(1, $attempts),
        );
    }
}
