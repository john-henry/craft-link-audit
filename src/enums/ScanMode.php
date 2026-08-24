<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\enums;

use Craft;

/**
 * What a scan run set out to do.
 *
 * The backing values are the scans table's `mode` enum values, so a case can be
 * written straight to the database and read straight back out.
 *
 * The mode is not just a label. It decides which elements the extract phase
 * offers up, and whether the finish phase is allowed to prune reference rows it
 * did not see: only a full run has looked at everything, so only a full run may
 * conclude that a row it did not meet is stale.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
enum ScanMode: string
{
    // =========================================================================
    // Cases
    // =========================================================================

    /**
     * Every scannable element on every site in scope.
     */
    case Full = 'full';

    /**
     * Only elements edited since the last completed run.
     */
    case Incremental = 'incremental';

    /**
     * One element, usually because it was just saved.
     */
    case Single = 'single';

    /**
     * No extraction at all: work through the URLs already waiting to be
     * checked.
     */
    case CheckOnly = 'check-only';

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * What this kind of run is called on screen.
     *
     * @return string The translated label.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function label(): string
    {
        return match ($this) {
            self::Full => Craft::t('link-audit', 'Everything'),
            self::Incremental => Craft::t('link-audit', 'Only what changed'),
            self::Single => Craft::t('link-audit', 'One item'),
            self::CheckOnly => Craft::t('link-audit', 'URLs only'),
        };
    }

    /**
     * Whether a run in this mode has seen enough of the content to conclude
     * that a reference row it did not meet is stale.
     *
     * Only a full run has. An incremental run visited the handful of elements
     * that changed, and a single run visited one, so pruning on either would
     * throw away perfectly good references for everything it never looked at.
     *
     * @return bool Whether stale references may be pruned after this run.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function prunesStaleReferences(): bool
    {
        return $this === self::Full;
    }
}
