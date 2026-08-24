<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\records;

use craft\db\ActiveRecord;

/**
 * Where a URL appears: one row per link occurrence, per element, per site.
 *
 * These rows are rebuilt wholesale every time an element is extracted, so
 * nothing durable may live here. Author decisions belong in
 * [[IgnoreRecord]] and check history belongs on [[UrlRecord]].
 *
 * `ownerElementId` carries the root element for a link found inside a nested
 * entry, so the control panel can offer an Edit link that opens something the
 * author can actually edit.
 *
 * @property int $id
 * @property int $urlId
 * @property int $elementId
 * @property string $elementType
 * @property int $siteId
 * @property int|null $ownerElementId
 * @property string|null $fieldUid
 * @property string|null $fieldHandle
 * @property string $source
 * @property string|null $linkText
 * @property string|null $rawHref
 * @property int|null $scanId
 * @author John Henry Donovan
 * @since 1.0.0
 */
class ReferenceRecord extends ActiveRecord
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
        return '{{%linkaudit_references}}';
    }
}
