<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\models;

use johnhenry\linkaudit\enums\LinkKind;
use johnhenry\linkaudit\enums\UrlStatus;

/**
 * One link, found in one place.
 *
 * A plain immutable value object rather than a Craft model, for the same reason
 * {@see Verdict} is one: nothing here is posted from a form or validated, it is
 * what the extractor read out of the content on its way to a URL row and a
 * reference row.
 *
 * It carries the element it was found in as well as the root element that owns
 * that content, because a link inside a Matrix block belongs to the block for
 * rebuilding purposes and to the entry for editing purposes, and the control
 * panel needs both.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class ExtractedLink
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * The scheme given to the stand-in URL used for a relation whose target has
     * no URL of its own. Kept apart from {@see self::SYNTHETIC_SCHEME} so a
     * relation and a Link field pointing at the same URL-less element get their
     * own URL row and their own verdict, rather than fighting over one: the two
     * are checked by different rules and a single row can only hold one answer.
     */
    public const RELATION_SYNTHETIC_SCHEME = 'relation';

    /**
     * Found in a stored field value.
     */
    public const SOURCE_FIELD = 'field';

    /**
     * Found on a navigation node.
     */
    public const SOURCE_NAV = 'nav';

    /**
     * Found in a rendered page rather than in stored content.
     */
    public const SOURCE_RENDERED = 'rendered';

    /**
     * The scheme given to the stand-in URL used for an element link whose target
     * has no URL of its own, so the row still has something unique to hash.
     */
    public const SYNTHETIC_SCHEME = 'element';

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Constructor.
     *
     * @param LinkKind $kind What the link points at, and so how it is checked.
     * @param int $elementId The element the link was found in. For a link inside
     *                       a Matrix block or a nested entry, that is the block,
     *                       not the page.
     * @param string $elementType The class of that element.
     * @param int $ownerElementId The root element that owns the content, from
     *                            {@see \craft\helpers\ElementHelper::rootElement()}.
     *                            The same as `$elementId` for a top level
     *                            element.
     * @param int $siteId The site the element was read on.
     * @param string $url The normalised URL, or a stand-in
     *                    `element:<id>` for an element link whose target has no
     *                    URL. Either way it is what the URL row is hashed on.
     * @param string $rawHref What the author actually typed, kept for display.
     * @param int|null $targetElementId The element an element link points at.
     * @param string|null $targetElementType The class of that element, when it
     *                                       could be resolved.
     * @param string|null $fieldUid The uid of the field the link was found in.
     * @param string|null $fieldHandle The handle of that field.
     * @param string|null $linkText The anchor text, alt text or label, for the
     *                              report.
     * @param string $source One of the SOURCE_* constants.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function __construct(
        public readonly LinkKind $kind,
        public readonly int $elementId,
        public readonly string $elementType,
        public readonly int $ownerElementId,
        public readonly int $siteId,
        public readonly string $url,
        public readonly string $rawHref,
        public readonly ?int $targetElementId = null,
        public readonly ?string $targetElementType = null,
        public readonly ?string $fieldUid = null,
        public readonly ?string $fieldHandle = null,
        public readonly ?string $linkText = null,
        public readonly string $source = self::SOURCE_FIELD,
    ) {
    }

    /**
     * The stand-in URL for an element link whose target has no URL of its own.
     *
     * Element ids are unique across every element type, so the id alone is
     * enough to keep two of these apart.
     *
     * @param int $elementId The element the link points at.
     * @return string The stand-in URL.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function syntheticUrl(int $elementId): string
    {
        return self::SYNTHETIC_SCHEME . ':' . $elementId;
    }

    /**
     * The stand-in URL for a relation whose target has no URL of its own.
     *
     * A relation and a Link field pointing at the same URL-less element must not
     * settle for one shared verdict, since {@see \johnhenry\linkaudit\services\InternalResolver::resolveElement()}
     * answers the two differently: a relation only asks whether the target still
     * exists and is enabled, a link also asks whether it has somewhere to send
     * anybody. Giving the relation its own stand-in, apart from
     * {@see self::syntheticUrl()}, is what keeps them on separate URL rows.
     *
     * @param int $elementId The element the relation points at.
     * @return string The stand-in URL.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function relationSyntheticUrl(int $elementId): string
    {
        return self::RELATION_SYNTHETIC_SCHEME . ':' . $elementId;
    }

    /**
     * Which stand-in scheme a URL was built with, or null when it is not one of
     * this plugin's stand-ins at all.
     *
     * Answered by string comparison rather than by
     * {@see \johnhenry\linkaudit\helpers\UrlNormaliser::schemeOf()}. A stand-in
     * is not a URI, and handing one to `parse_url()` is where that goes wrong:
     * PHP reads a bare `scheme:12345` as a host and a port whenever the digits
     * after the colon fit in one, which is every element id under 65536, so the
     * scheme comes back empty for the ordinary case and only survives by
     * accident once an id runs past five figures. Comparing the string directly
     * is not fooled either way.
     *
     * @param string $url The URL to read.
     * @return string|null One of the *_SYNTHETIC_SCHEME constants, or null.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function standInScheme(string $url): ?string
    {
        foreach ([self::RELATION_SYNTHETIC_SCHEME, self::SYNTHETIC_SCHEME] as $scheme) {
            if (str_starts_with($url, $scheme . ':')) {
                return $scheme;
            }
        }

        return null;
    }

    /**
     * The status a URL row should be given the first time this link is stored.
     *
     * Everything is pending until it is checked, except a link the plugin has
     * already decided not to check.
     *
     * @return UrlStatus The status.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function initialStatus(): UrlStatus
    {
        return $this->kind === LinkKind::Ignored ? UrlStatus::Ignored : UrlStatus::Pending;
    }

    /**
     * Whether the URL row for this link belongs to a single site.
     *
     * Internal, element and relation links resolve differently per site; an
     * external URL's HTTP verdict does not, so its row is global.
     *
     * @return bool Whether the link is internal to this installation.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function isInternal(): bool
    {
        return $this->kind === LinkKind::Internal
            || $this->kind === LinkKind::Element
            || $this->kind === LinkKind::Relation;
    }

    /**
     * This link in the shape {@see \johnhenry\linkaudit\services\UrlStore::replaceReferencesFor()}
     * wants, so a caller never has to assemble that array by hand.
     *
     * @param int $urlId The URL row this reference points at.
     * @param int|null $scanId The scan that recorded it, when there is one.
     * @return array<string, mixed> The reference row.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function toReference(int $urlId, ?int $scanId = null): array
    {
        return [
            'urlId' => $urlId,
            'elementType' => $this->elementType,
            'ownerElementId' => $this->ownerElementId,
            'fieldUid' => $this->fieldUid,
            'fieldHandle' => $this->fieldHandle,
            'source' => $this->source,
            'linkText' => $this->linkText,
            // Clamped to the column width. A reference insert runs as one
            // batch inside a transaction, outside the per-link guard, so a
            // single over-long href, a giant tracking or maps URL, would throw
            // Data too long on strict MySQL and take the whole batch down with
            // it, again on every rescan.
            'rawHref' => mb_substr($this->rawHref, 0, 500),
            'scanId' => $scanId,
        ];
    }
}
