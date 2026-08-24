<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\records;

use craft\db\ActiveRecord;

/**
 * One row per unique normalised URL: the verdict cache.
 *
 * `urlHash` is the dedupe key rather than the URL itself, because a column wide
 * enough for real URLs cannot carry a unique index. Everything else on the row
 * is the result of the last check, so a link appearing in ten thousand entries
 * is still checked once.
 *
 * External URLs are global (`siteId` null): an HTTP verdict does not change per
 * site. Internal URLs are site scoped, because `/about` resolves differently on
 * each one.
 *
 * @property int $id
 * @property string $urlHash
 * @property string $url
 * @property string $host
 * @property string $scheme
 * @property int|null $siteId
 * @property bool $isInternal
 * @property string $status
 * @property int|null $httpStatus
 * @property string|null $method
 * @property string|null $finalUrl
 * @property int $redirectCount
 * @property bool|null $redirectPermanent
 * @property int|null $redirectStatus
 * @property string|null $reason
 * @property string|null $message
 * @property int|null $responseTimeMs
 * @property int $failCount
 * @property string $dateFirstSeen
 * @property string|null $dateLastChecked
 * @property string|null $dateLastOk
 * @property string|null $dateLastBroken
 * @property string|null $nextCheckAfter
 * @author John Henry Donovan
 * @since 1.0.0
 */
class UrlRecord extends ActiveRecord
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
        return '{{%linkaudit_urls}}';
    }
}
