<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\enums;

use Craft;

/**
 * The verdict held on a URL row.
 *
 * The backing values are the `status` column's enum values, so a case can be
 * written straight to the database and read straight back out.
 *
 * The distinctions carry weight in the report: a redirect is not a failure but
 * a permanent one is work an author should do; unreachable is a maybe, and only
 * becomes broken once it has failed enough times in a row; and blocked means the
 * far end refuses robots, which is not the same as the link being wrong.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
enum UrlStatus: string
{
    // =========================================================================
    // Cases
    // =========================================================================

    /**
     * Never checked, or due to be checked again from scratch.
     */
    case Pending = 'pending';

    /**
     * Answered with a 2xx.
     */
    case Ok = 'ok';

    /**
     * Answered with a 404 or a 410, or failed often enough to be called gone.
     */
    case Broken = 'broken';

    /**
     * Redirected to something that answered with a 2xx.
     */
    case Redirect = 'redirect';

    /**
     * Did not answer: DNS failure, refused connection, TLS error, timeout, or a
     * 5xx. Held here until it has failed enough times to be called broken.
     */
    case Unreachable = 'unreachable';

    /**
     * Refused an automated request. Reported separately and never counted as
     * broken.
     */
    case Blocked = 'blocked';

    /**
     * Matched an ignore rule, or uses a scheme the plugin does not check.
     */
    case Ignored = 'ignored';

    /**
     * Refused by the SSRF guard, so it was never requested.
     */
    case Unsafe = 'unsafe';

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * The control panel status colour this verdict is drawn in.
     *
     * One of Craft's own status colours, so the pills match every other status
     * light in the control panel rather than inventing a palette. Red is kept
     * for the two verdicts that mean a visitor hit a wall; a blocked URL is
     * amber because it is a maybe, not a failure.
     *
     * @return string The Craft status colour class.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function colour(): string
    {
        return match ($this) {
            self::Ok => 'green',
            self::Redirect => 'blue',
            self::Broken, self::Unsafe => 'red',
            self::Unreachable => 'orange',
            self::Blocked => 'yellow',
            self::Ignored => 'grey',
            self::Pending => 'light',
        };
    }

    /**
     * What this verdict is called on screen.
     *
     * Written for an editor rather than a developer: nobody outside the plugin
     * knows what "unreachable" is meant to mean next to "broken", so the labels
     * say what happened.
     *
     * @return string The translated label.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => Craft::t('link-audit', 'Not checked yet'),
            self::Ok => Craft::t('link-audit', 'Working'),
            self::Broken => Craft::t('link-audit', 'Broken'),
            self::Redirect => Craft::t('link-audit', 'Redirect'),
            self::Unreachable => Craft::t('link-audit', 'No Answer'),
            self::Blocked => Craft::t('link-audit', 'Unverifiable'),
            self::Ignored => Craft::t('link-audit', 'Ignored'),
            self::Unsafe => Craft::t('link-audit', 'Not safe to request'),
        };
    }
}
