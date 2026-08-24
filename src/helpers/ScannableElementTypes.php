<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\helpers;

use Craft;
use craft\base\ElementInterface;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Tag;

/**
 * Enumerates the element types a scan can read links out of.
 *
 * Deliberately not gated on {@see ElementInterface::hasUris()}. A page checker
 * only cares about elements it can fetch; this one reads stored field values, so
 * a global set with a footer full of links matters every bit as much as an entry,
 * and it has no URL at all.
 *
 * Assets are left out entirely: they are binary files, and their field layout
 * holds alt text rather than links. Everything else is offered in the settings,
 * but the default set is a named list rather than everything Craft happens to
 * ship: users, addresses and Commerce orders are all elements a big site has by
 * the hundred thousand and none of them is where an editor puts a link.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class ScannableElementTypes
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var class-string[] The default scan set, in the order a person would
     * think of them. Anything not on this list is opt-in.
     */
    private const _DEFAULT_TYPES = [
        Entry::class,
        Category::class,
        Tag::class,
        GlobalSet::class,
        Product::class,
        Variant::class,
    ];

    // =========================================================================
    // Static Methods
    // =========================================================================

    /**
     * Every element type a scan could read, as class name to display label,
     * ordered by label for a settings checklist.
     *
     * @return array<class-string, string> The types.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function all(): array
    {
        $types = [];

        /** @var class-string<ElementInterface> $type */
        foreach (Craft::$app->getElements()->getAllElementTypes() as $type) {
            if ($type === Asset::class) {
                continue;
            }

            $types[$type] = $type::displayName();
        }

        asort($types);

        return $types;
    }

    /**
     * The default scan set: entries, categories, tags, global sets, and Commerce
     * products and variants where Commerce is installed.
     *
     * A named list rather than everything under the `craft\` namespace, which
     * would quietly enrol every Commerce order on the site. An order is not
     * content: it has no fields an editor types a link into, it cannot be edited
     * to fix one, and a shop that has been trading a few years has hundreds of
     * thousands of them, abandoned carts and all. A scan that reads those reads
     * for a day and finds nothing.
     *
     * Everything else, first-party or third-party, stays opt-in through the
     * settings: nobody but the project knows whether a given element's fields
     * are worth reading.
     *
     * @return class-string[] The types, minus any whose plugin is not installed.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function native(): array
    {
        $available = array_keys(self::all());

        return array_values(array_filter(
            self::_DEFAULT_TYPES,
            static fn(string $type): bool => in_array($type, $available, true),
        ));
    }
}
