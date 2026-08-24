<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\enums;

use Craft;

/**
 * Where a scan run has got to.
 *
 * The backing values are the scans table's `status` enum values, so a case can
 * be written straight to the database and read straight back out.
 *
 * A run moves queued to extracting to checking to complete. It sits at queued
 * until a worker actually picks the first job up, which is why the scan row's
 * `dateStarted` is nullable: a scan that has been waiting in the queue for an
 * hour has not been running for an hour.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
enum ScanStatus: string
{
    // =========================================================================
    // Cases
    // =========================================================================

    /**
     * Pushed to the queue, not yet picked up.
     */
    case Queued = 'queued';

    /**
     * Reading links out of the content.
     */
    case Extracting = 'extracting';

    /**
     * Asking the URLs whether they still work.
     */
    case Checking = 'checking';

    /**
     * Finished, totals recalculated.
     */
    case Complete = 'complete';

    /**
     * Stopped on an error it could not carry on from.
     */
    case Failed = 'failed';

    /**
     * Called off before it finished.
     */
    case Cancelled = 'cancelled';

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * What this stage is called on screen.
     *
     * @return string The translated label.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function label(): string
    {
        return match ($this) {
            self::Queued => Craft::t('link-audit', 'Waiting to start'),
            self::Extracting => Craft::t('link-audit', 'Reading the content'),
            self::Checking => Craft::t('link-audit', 'Checking the URLs'),
            self::Complete => Craft::t('link-audit', 'Finished'),
            self::Failed => Craft::t('link-audit', 'Failed'),
            self::Cancelled => Craft::t('link-audit', 'Cancelled'),
        };
    }
}
