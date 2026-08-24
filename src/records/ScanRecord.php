<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\records;

use craft\db\ActiveRecord;

/**
 * One row per scan run.
 *
 * Drives the progress display while a scan is going, and afterwards answers
 * "when did this last run" for incremental scans and the scheduler.
 *
 * @property int $id
 * @property int|null $siteId
 * @property string $mode
 * @property string $status
 * @property int $elementsScanned
 * @property int $urlsTotal
 * @property int $urlsChecked
 * @property int $urlsBroken
 * @property string|null $dateStarted
 * @property string|null $dateFinished
 * @author John Henry Donovan
 * @since 1.0.0
 */
class ScanRecord extends ActiveRecord
{
    // =========================================================================
    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return string The table name.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function tableName(): string
    {
        return '{{%linkaudit_scans}}';
    }
}
