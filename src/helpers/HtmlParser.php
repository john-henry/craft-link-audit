<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\helpers;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Turns HTML into something the plugin can walk, without ever fetching anything
 * while it does.
 *
 * Two doors in, because the plugin reads two different kinds of HTML. A rich
 * text field holds a fragment: no `<html>`, no `<head>`, and a run of block
 * elements that only makes sense wrapped in something. A crawled page is a whole
 * document. Wrapping a document in a `<div>` the way a fragment needs would have
 * the parser tidying it into a shape nobody wrote.
 *
 * Network access is off on both paths, so an entity declaration in author
 * content cannot make the parser go and fetch anything, and the parser's
 * complaints about the tag soup real content is full of are swallowed rather
 * than raised: this is an audit, not a validator.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class HtmlParser
{
    // =========================================================================
    // Constants
    // =========================================================================

    /**
     * @var int The longest link text that can be stored, matching the width of
     * `linkText` on {{%linkaudit_references}}. Anything longer is clipped here
     * rather than truncated by the database.
     */
    public const LINK_TEXT_MAX_LENGTH = 255;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Collapses the whitespace out of anchor text and clips it to something a
     * report column can hold.
     *
     * @param string|null $text The text as it was found.
     * @return string|null The tidied text, or null when there was none.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    public static function tidyLinkText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $tidied = trim((string)preg_replace('/\s+/u', ' ', $text));

        return $tidied !== '' ? mb_substr($tidied, 0, self::LINK_TEXT_MAX_LENGTH) : null;
    }

    /**
     * Every anchor name a document offers, so a `#fragment` link can be told
     * whether it lands anywhere.
     *
     * Both spellings count: the `id` of any element, and the `name` of an `<a>`,
     * which is how anchors were written for years and is still what a lot of
     * older content carries.
     *
     * @param DOMDocument $document The parsed document.
     * @return string[] The anchor names, in document order and deduplicated.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function anchorNames(DOMDocument $document): array
    {
        $xpath = new DOMXPath($document);
        $names = [];

        foreach ([['//*[@id]', 'id'], ['//a[@name]', 'name']] as [$query, $attribute]) {
            $nodes = $xpath->query($query);

            if ($nodes === false) {
                continue;
            }

            foreach ($nodes as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }

                $name = trim($node->getAttribute($attribute));

                if ($name !== '') {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * Parses a whole HTML document, as fetched from a page.
     *
     * @param string $html The document source.
     * @return DOMDocument|null The parsed document, or null when it could not be
     *                          parsed at all.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function document(string $html): ?DOMDocument
    {
        return self::_load('<?xml encoding="utf-8" ?>' . $html);
    }

    /**
     * Parses a fragment of HTML, as stored in a rich text field.
     *
     * @param string $html The fragment source.
     * @return DOMDocument|null The parsed document, or null when it could not be
     *                          parsed at all.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function fragment(string $html): ?DOMDocument
    {
        return self::_load('<?xml encoding="utf-8" ?><div>' . $html . '</div>');
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Loads prepared source into a document.
     *
     * @param string $source The source, already wrapped for whichever door it
     *                       came in.
     * @return DOMDocument|null The parsed document, or null when it could not be
     *                          parsed at all.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private static function _load(string $source): ?DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        $loaded = $document->loadHTML($source, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $document : null;
    }
}
