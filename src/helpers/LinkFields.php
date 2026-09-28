<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\helpers;

use Craft;
use craft\base\FieldInterface;
use craft\fields\BaseRelationField;
use craft\fields\Link;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\fields\Table;
use craft\htmlfield\HtmlField;
use verbb\hyper\fields\HyperField;

/**
 * Lists the fields a scan can read links out of, for the Excluded Fields
 * setting.
 *
 * Fields that can never hold a link (numbers, dates, lightswitches and the
 * like) are left out, so the list stays short enough to search on a site with
 * hundreds of fields.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0-beta.8
 */
class LinkFields
{
    // =========================================================================
    // Static Methods
    // =========================================================================

    /**
     * The link-capable fields as selectize options, sorted by name, with the
     * handle as a searchable hint.
     *
     * @return array<int, array{value: string, label: string, data: array{hint: string}}> The options.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0-beta.8
     */
    public static function options(): array
    {
        $options = [];

        foreach (Craft::$app->getFields()->getAllFields() as $field) {
            if (!self::canHoldLinks($field)) {
                continue;
            }

            $options[] = [
                'value' => (string)$field->uid,
                'label' => (string)$field->name,
                'data' => ['hint' => (string)$field->handle],
            ];
        }

        usort($options, static fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $options;
    }

    /**
     * Whether a field's value is one the link walker reads.
     *
     * @param FieldInterface $field The field.
     * @return bool Whether it can hold a link.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0-beta.8
     */
    public static function canHoldLinks(FieldInterface $field): bool
    {
        if (class_exists(HyperField::class) && $field instanceof HyperField) {
            return true;
        }

        return $field instanceof HtmlField
            || $field instanceof Link
            || $field instanceof Matrix
            || $field instanceof BaseRelationField
            || $field instanceof PlainText
            || $field instanceof Table;
    }
}
