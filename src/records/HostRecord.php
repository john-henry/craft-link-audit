<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\records;

use craft\db\ActiveRecord;

/**
 * Per-domain throttle and backoff state.
 *
 * Durable rather than per-scan, so a host that asked to be left alone during
 * one run is still treated politely on the next.
 *
 * @property int $id
 * @property string $host
 * @property int $consecutiveFailures
 * @property int|null $lastStatus
 * @property int|null $minDelayMs
 * @property string|null $blockedUntil
 * @property bool $botHostile
 * @property string|null $dateLastRequest
 * @author John Henry Donovan
 * @since 1.0.0
 */
class HostRecord extends ActiveRecord
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
        return '{{%linkaudit_hosts}}';
    }
}
