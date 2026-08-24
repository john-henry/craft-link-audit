<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\records;

use craft\db\ActiveRecord;

/**
 * An author's decision to stop reporting a URL, a host, or anything matching a
 * pattern.
 *
 * Kept apart from [[ReferenceRecord]] on purpose: reference rows are rebuilt on
 * every extraction, so a dismissal stored there would evaporate at the next
 * scan.
 *
 * @property int $id
 * @property string $scope
 * @property string|null $urlHash
 * @property string|null $value
 * @property string|null $note
 * @property int|null $userId
 * @author John Henry Donovan
 * @since 1.0.0
 */
class IgnoreRecord extends ActiveRecord
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
        return '{{%linkaudit_ignores}}';
    }
}
