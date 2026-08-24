<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\base\NestedElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\fields\BaseRelationField;
use craft\fields\data\LinkData;
use craft\helpers\App;
use craft\helpers\ElementHelper;
use craft\htmlfield\HtmlFieldData;
use craft\models\Site;
use DOMElement;
use DOMNode;
use DOMXPath;
use johnhenry\linkaudit\enums\LinkKind;
use johnhenry\linkaudit\enums\SchemeKind;
use johnhenry\linkaudit\helpers\HtmlParser;
use johnhenry\linkaudit\helpers\UrlNormaliser;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\ExtractedLink;
use johnhenry\linkaudit\models\SettingsModel;
use Throwable;
use verbb\hyper\base\ElementLink as HyperElementLink;
use verbb\hyper\base\Link as HyperLink;
use verbb\hyper\models\LinkCollection;
use verbb\navigation\elements\Node;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Reads every link out of an element's stored field values.
 *
 * The walker dispatches on the type of the stored value, not on the class of
 * the field holding it. A field type this plugin has never heard of stores its
 * value as something PHP already understands, or it does not, and either way
 * nobody has to have thought of it in advance: an unrecognised value is skipped
 * rather than fatal. Every field is read inside its own try/catch for the same
 * reason, so one broken third-party field cannot take an entry's whole
 * extraction down with it.
 *
 * Rich text is read with `getRawContent()`, never by casting the value to a
 * string. Casting a CKEditor value renders every nested entry through its
 * partial template, which is slow, depends on the template mode the request
 * happens to be in, and double-counts the links that the nested entry's own pass
 * already found.
 *
 * Nothing here writes to the database. It hands back
 * {@see ExtractedLink} objects, which the caller feeds to
 * {@see UrlStore::upsert()} and {@see UrlStore::replaceReferencesFor()}.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class LinkExtractor extends Component
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var int How many levels of nested elements the walker will go down.
     * Matrix inside CKEditor inside Matrix is a real content structure; ten
     * levels of it is a mistake, and a runaway one would otherwise be paid for
     * in queue time.
     */
    public const MAX_DEPTH = 10;

    /**
     * @var string Regular expression matching a href that is nothing but a
     * Craft reference tag, mirroring the pattern
     * {@see \craft\services\Elements::REF_TAG_PATTERN} matches, plus an
     * optional trailing `#fragment`: CKEditor's anchor tool appends one to a
     * "link to an entry" href exactly as it would to an ordinary URL, and an
     * element link has nowhere to put a fragment, so it is allowed to be
     * there and then ignored. Anchored to the rest of the string, so a stray
     * `{` inside an otherwise ordinary URL, a dynamic nav path like
     * `/{slug}` for instance, is never mistaken for one.
     */
    private const _REFERENCE_TAG_PATTERN = '/^\{(?P<elementType>[\w\\\\]+)\:(?P<ref>[^@\:\}\|]+)(?:@(?P<site>[^\:\}\|]+))?(?:\:(?P<attr>[^\}\| ]+))?(?:\ *\|\|\ *(?P<fallback>[^\}]*))?\}(?:#\S*)?$/';

    // =========================================================================
    // Private Properties
    // =========================================================================

    /**
     * @var array<int, string> The base URL of every site that has one, keyed by
     * site id. Memoised for the length of one extraction.
     */
    private array $_baseUrls = [];

    /**
     * @var array<string, bool> The elements this extraction has already walked,
     * keyed by element and site, so a cycle cannot loop forever.
     */
    private array $_visited = [];

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Every link stored in an element's fields, including the fields of any
     * elements nested inside it.
     *
     * @param ElementInterface $element The element to read.
     * @return ExtractedLink[] The links found, in the order they were met.
     * @throws InvalidConfigException If the element is not on a valid site.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function extract(ElementInterface $element): array
    {
        $this->_baseUrls = [];
        $this->_visited = [];

        if ($element->id === null) {
            return [];
        }

        $root = ElementHelper::rootElement($element);

        return $this->_fromElement(
            element: $element,
            ownerElementId: (int)($root->id ?? $element->id),
            depth: 0,
        );
    }

    /**
     * Every link held by a navigation node.
     *
     * Navigations are a classic source of broken links precisely because
     * nothing in the field walker ever sees them: the URL lives on the node, not
     * in anybody's content. Nodes are elements in Navigation 3, so a reference
     * to one hangs off the same foreign key as everything else and the control
     * panel can link straight to the node's edit screen.
     *
     * Nothing happens at all when verbb/navigation is not installed, which is
     * why the plugin is not a dependency.
     *
     * @param int|null $siteId The site to read, or null for every site.
     * @return ExtractedLink[] The links found.
     * @throws InvalidConfigException If a node is not on a valid site.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function extractNavigationNodes(?int $siteId = null): array
    {
        $this->_baseUrls = [];
        $this->_visited = [];

        if (!class_exists(Node::class) || !$this->_settings()->scanNavigationNodes) {
            return [];
        }

        $siteIds = $siteId !== null
            ? [$siteId]
            : array_map(static fn(Site $site): int => (int)$site->id, Craft::$app->getSites()->getAllSites());

        $found = [];

        foreach ($siteIds as $id) {
            try {
                // Reading nodes is not free of side effects: Navigation works
                // out each node's active state as the query is populated, which
                // renders a dynamic custom URL through Twig. A worker has no
                // request behind it, so that is allowed to fail without taking
                // the rest of the navigation with it.
                $nodes = Node::find()->siteId($id)->all();
            } catch (Throwable $e) {
                Craft::warning(
                    "Could not read the navigation nodes for site $id: {$e->getMessage()}",
                    'link-audit',
                );

                continue;
            }

            foreach ($nodes as $node) {
                if (!$node instanceof Node) {
                    continue;
                }

                try {
                    $found[] = $this->_navNodeLinks($node);
                } catch (Throwable $e) {
                    Craft::warning(
                        "Skipped navigation node {$node->id}: {$e->getMessage()}",
                        'link-audit',
                    );
                }
            }
        }

        return array_merge([], ...$found);
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * The base URL of a site, or null when it does not serve one.
     *
     * @param int $siteId The site to look up.
     * @return string|null The base URL.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _baseUrlFor(int $siteId): ?string
    {
        $baseUrls = $this->_siteBaseUrls();

        return $baseUrls[$siteId] ?? null;
    }

    /**
     * A link that points at an element rather than at a URL.
     *
     * The URL row still gets a URL, because that is what the table is keyed on:
     * the target's own URL when it has one, so a typed link and a pasted link to
     * the same page share a row, and a stand-in otherwise.
     *
     * @param int $targetElementId The element being linked to.
     * @param ElementInterface|null $target That element, when it could be
     *                                      loaded.
     * @param ElementInterface $element The element the link was found in.
     * @param int $ownerElementId The root element that owns the content.
     * @param FieldInterface|null $field The field the link was found in.
     * @param string|null $linkText The label to show in the report.
     * @param string $rawHref What was stored, for display.
     * @param string $source One of the ExtractedLink SOURCE_* constants.
     * @param int|null $siteId The site to resolve the target against, when it
     *                         is not the element's own site. A reference tag
     *                         carrying an `@site` segment is the only caller
     *                         that passes one; everybody else is answered
     *                         from the element's own site.
     * @param bool $isRelation Whether this came from a relation field rather
     *                         than an authored hyperlink. Picks
     *                         {@see LinkKind::Relation} over
     *                         {@see LinkKind::Element}, and, when the target has
     *                         no URL of its own, a stand-in URL of its own too,
     *                         so a relation and a link field pointing at the
     *                         same URL-less element never share one verdict.
     * @return ExtractedLink The link.
     * @throws InvalidConfigException If the element is not on a valid site.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _elementLink(
        int $targetElementId,
        ?ElementInterface $target,
        ElementInterface $element,
        int $ownerElementId,
        ?FieldInterface $field,
        ?string $linkText,
        string $rawHref,
        string $source = ExtractedLink::SOURCE_FIELD,
        ?int $siteId = null,
        bool $isRelation = false,
    ): ExtractedLink {
        $siteId ??= $element->getSite()->id;
        $targetUrl = null;

        try {
            $targetUrl = $target?->getUrl();
        } catch (Throwable $e) {
            Craft::warning(
                "Could not read the URL of element $targetElementId: {$e->getMessage()}",
                'link-audit',
            );
        }

        $normalised = $targetUrl !== null
            ? UrlNormaliser::normalise(
                $targetUrl,
                $this->_baseUrlFor($siteId),
                $this->_settings()->stripTrackingParams,
            )
            : null;

        $standIn = $isRelation
            ? ExtractedLink::relationSyntheticUrl($targetElementId)
            : ExtractedLink::syntheticUrl($targetElementId);

        return new ExtractedLink(
            kind: $isRelation ? LinkKind::Relation : LinkKind::Element,
            elementId: (int)$element->id,
            elementType: $element::class,
            ownerElementId: $ownerElementId,
            siteId: $siteId,
            url: $normalised ?? $standIn,
            rawHref: $rawHref,
            targetElementId: $targetElementId,
            targetElementType: $target !== null ? $target::class : null,
            fieldUid: $field?->uid,
            fieldHandle: $field?->handle,
            linkText: $this->_tidyText($linkText),
            source: $source,
        );
    }

    /**
     * Reads an element query value: either elements nested inside this one, or
     * a relation field's targets.
     *
     * Matrix blocks and nested entries are content, so the walker goes into
     * them and records what it finds against the block, with the root element
     * as the owner so the report can offer an edit link that opens something an
     * author can actually edit.
     *
     * Relation fields are skipped unless the settings ask for them. Craft's own
     * foreign keys already guarantee the target row is there, so the only thing
     * this earns is catching a target that has been disabled or removed, and on
     * a large site that is a lot of rows for a narrow question. A relation's
     * target having no URL of its own is not one of those things: the author
     * only picked the element, they never asserted it renders a link, so
     * {@see ExtractedLink} is built with `isRelation` on and
     * {@see \johnhenry\linkaudit\services\InternalResolver::resolveElement()}
     * leaves that case alone.
     *
     * The query is cloned before its status filter is dropped, so a disabled
     * target is visible to the resolver rather than quietly missing, and the
     * field's own value is left exactly as it was found.
     *
     * @param ElementQueryInterface $query The stored value.
     * @param FieldInterface $field The field it came from.
     * @param ElementInterface $element The element the field belongs to.
     * @param int $ownerElementId The root element that owns the content.
     * @param int $depth How far down the nesting this element sits.
     * @return ExtractedLink[] The links found.
     * @throws InvalidConfigException If the element is not on a valid site.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _elementQueryLinks(
        ElementQueryInterface $query,
        FieldInterface $field,
        ElementInterface $element,
        int $ownerElementId,
        int $depth,
    ): array {
        $isRelation = $field instanceof BaseRelationField;

        if ($isRelation && !$this->_settings()->scanRelationFields) {
            return [];
        }

        $found = [];

        foreach ((clone $query)->status(null)->all() as $target) {
            if ($target->id === null) {
                continue;
            }

            if (!$isRelation) {
                if ($target instanceof NestedElementInterface && $this->_isLive($target)) {
                    $found[] = $this->_fromElement(
                        element: $target,
                        ownerElementId: $ownerElementId,
                        depth: $depth + 1,
                    );
                }

                continue;
            }

            $found[] = [
                $this->_elementLink(
                    targetElementId: (int)$target->id,
                    target: $target,
                    element: $element,
                    ownerElementId: $ownerElementId,
                    field: $field,
                    linkText: $target->getUiLabel(),
                    rawHref: ExtractedLink::syntheticUrl((int)$target->id),
                    isRelation: true,
                ),
            ];
        }

        return array_merge([], ...$found);
    }

    /**
     * Walks one element's field layout.
     *
     * @param ElementInterface $element The element to read.
     * @param int $ownerElementId The root element that owns the content.
     * @param int $depth How far down the nesting this element sits.
     * @return ExtractedLink[] The links found.
     * @throws InvalidConfigException If the element is not on a valid site.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _fromElement(ElementInterface $element, int $ownerElementId, int $depth): array
    {
        if ($depth > self::MAX_DEPTH || $element->id === null) {
            return [];
        }

        $key = $element->id . ':' . $element->getSite()->id;

        if (isset($this->_visited[$key])) {
            return [];
        }

        $this->_visited[$key] = true;
        $layout = $element->getFieldLayout();

        if ($layout === null) {
            return [];
        }

        $found = [];

        foreach ($layout->getCustomFields() as $field) {
            try {
                $found[] = $this->_fromValue(
                    value: $element->getFieldValue($field->handle),
                    field: $field,
                    element: $element,
                    ownerElementId: $ownerElementId,
                    depth: $depth,
                );
            } catch (Throwable $e) {
                Craft::warning(
                    "Skipped field {$field->handle} on element {$element->id}: {$e->getMessage()}",
                    'link-audit',
                );
            }
        }

        return array_merge([], ...$found);
    }

    /**
     * Dispatches one stored field value to whatever knows how to read it.
     *
     * @param mixed $value The stored value.
     * @param FieldInterface $field The field it came from.
     * @param ElementInterface $element The element the field belongs to.
     * @param int $ownerElementId The root element that owns the content.
     * @param int $depth How far down the nesting this element sits.
     * @return ExtractedLink[] The links found.
     * @throws InvalidConfigException If the element is not on a valid site.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _fromValue(
        mixed $value,
        FieldInterface $field,
        ElementInterface $element,
        int $ownerElementId,
        int $depth,
    ): array {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if ($value instanceof HtmlFieldData) {
            return $this->_htmlLinks(
                html: $value->getRawContent(),
                field: $field,
                element: $element,
                ownerElementId: $ownerElementId,
                depth: $depth,
            );
        }

        if ($value instanceof LinkData) {
            return $this->_linkDataLinks(
                value: $value,
                field: $field,
                element: $element,
                ownerElementId: $ownerElementId,
            );
        }

        if (class_exists(LinkCollection::class) && $value instanceof LinkCollection) {
            return $this->_hyperLinks(
                collection: $value,
                field: $field,
                element: $element,
                ownerElementId: $ownerElementId,
            );
        }

        if ($value instanceof ElementQueryInterface) {
            return $this->_elementQueryLinks(
                query: $value,
                field: $field,
                element: $element,
                ownerElementId: $ownerElementId,
                depth: $depth,
            );
        }

        if (is_string($value)) {
            return $this->_plainTextLinks(
                value: $value,
                field: $field,
                element: $element,
                ownerElementId: $ownerElementId,
            );
        }

        if (is_array($value)) {
            return $this->_tableLinks(
                rows: $value,
                field: $field,
                element: $element,
                ownerElementId: $ownerElementId,
            );
        }

        return [];
    }

    /**
     * Reads the links out of a rich text field's stored HTML.
     *
     * Anchors and image maps are always read. Image and iframe sources are read
     * only when the settings ask for them: images are usually worth knowing
     * about, embeds are routinely bot-hostile and rarely actionable.
     *
     * @param string $html The raw stored HTML, entry placeholders and all.
     * @param FieldInterface $field The field it came from.
     * @param ElementInterface $element The element the field belongs to.
     * @param int $ownerElementId The root element that owns the content.
     * @param int $depth How far down the nesting this element sits.
     * @return ExtractedLink[] The links found.
     * @throws InvalidConfigException If the element is not on a valid site.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _htmlLinks(
        string $html,
        FieldInterface $field,
        ElementInterface $element,
        int $ownerElementId,
        int $depth,
    ): array {
        if (trim($html) === '') {
            return [];
        }

        $document = HtmlParser::fragment($html);

        if ($document === null) {
            return [];
        }

        $settings = $this->_settings();
        $xpath = new DOMXPath($document);
        $found = [];

        // Each selector is the tag to read, the attribute holding the URL, and
        // the attribute holding its text, or null to use the tag's own text.
        $selectors = [
            ['//a[@href]', 'href', null],
            ['//area[@href]', 'href', 'alt'],
        ];

        if ($settings->checkImages) {
            $selectors[] = ['//img[@src]', 'src', 'alt'];
        }

        if ($settings->checkIframes) {
            $selectors[] = ['//iframe[@src]', 'src', 'title'];
        }

        foreach ($selectors as [$query, $urlAttribute, $textAttribute]) {
            $nodes = $xpath->query($query);

            if ($nodes === false) {
                continue;
            }

            foreach ($nodes as $node) {
                if (!$node instanceof DOMElement || $this->_isInsideNestedEntry($node)) {
                    continue;
                }

                $link = $this->_link(
                    rawHref: $node->getAttribute($urlAttribute),
                    linkText: $textAttribute !== null
                        ? $node->getAttribute($textAttribute)
                        : $node->textContent,
                    field: $field,
                    element: $element,
                    ownerElementId: $ownerElementId,
                );

                if ($link !== null) {
                    $found[] = $link;
                }
            }
        }

        return array_merge($found, $this->_nestedEntryLinks(
            xpath: $xpath,
            element: $element,
            ownerElementId: $ownerElementId,
            depth: $depth,
        ));
    }

    /**
     * The element id a Hyper element link holds, read straight off the stored
     * value so a target that has since been deleted is still reported.
     *
     * @param HyperElementLink $link The link to read.
     * @return int|null The element id.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _hyperElementId(HyperElementLink $link): ?int
    {
        $value = $link->linkValue;

        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_numeric($value) ? (int)$value : null;
    }

    /**
     * Reads a Hyper field's links.
     *
     * Hyper is not a dependency, so this only ever runs when the plugin is
     * installed. Its element links are answered from the database like any other
     * element link; everything else carries its own URL, including the email and
     * phone types, whose prefixes make them fall out as skippable without a case
     * of their own.
     *
     * @param LinkCollection $collection The stored value.
     * @param FieldInterface $field The field it came from.
     * @param ElementInterface $element The element the field belongs to.
     * @param int $ownerElementId The root element that owns the content.
     * @return ExtractedLink[] The links found.
     * @throws InvalidConfigException If the element is not on a valid site.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _hyperLinks(
        LinkCollection $collection,
        FieldInterface $field,
        ElementInterface $element,
        int $ownerElementId,
    ): array {
        $found = [];

        foreach ($collection as $link) {
            if (!$link instanceof HyperLink) {
                continue;
            }

            if ($link instanceof HyperElementLink) {
                $target = $link->getElement(null);
                $targetElementId = $target?->id ?? $this->_hyperElementId($link);

                if ($targetElementId === null) {
                    continue;
                }

                $found[] = $this->_elementLink(
                    targetElementId: (int)$targetElementId,
                    target: $target,
                    element: $element,
                    ownerElementId: $ownerElementId,
                    field: $field,
                    linkText: $link->getLinkText(),
                    rawHref: ExtractedLink::syntheticUrl((int)$targetElementId),
                );

                continue;
            }

            $extracted = $this->_link(
                rawHref: (string)$link->getUrl(),
                linkText: $link->getLinkText(),
                field: $field,
                element: $element,
                ownerElementId: $ownerElementId,
            );

            if ($extracted !== null) {
                $found[] = $extracted;
            }
        }

        return $found;
    }

    /**
     * Whether a node sits inside a nested entry placeholder.
     *
     * Belt and braces: a `<craft-entry>` chunk is empty in stored content, so
     * there should be nothing inside one to find. If a stray link ever does turn
     * up in there it still must not be counted here, because the nested entry's
     * own pass records it against the entry that can actually be edited.
     *
     * @param DOMNode $node The node to check.
     * @return bool Whether it is inside a nested entry placeholder.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _isInsideNestedEntry(DOMNode $node): bool
    {
        $parent = $node->parentNode;

        while ($parent !== null) {
            if (strtolower($parent->nodeName) === 'craft-entry') {
                return true;
            }

            $parent = $parent->parentNode;
        }

        return false;
    }

    /**
     * Whether a nested element is one a visitor would ever see.
     *
     * A disabled block is not on the page, so its links are not this scan's
     * business. Reporting them would mean an author being told to fix a link
     * nobody can reach, which is exactly the kind of noise that gets a link
     * checker turned off.
     *
     * @param ElementInterface $element The nested element.
     * @return bool Whether it is enabled, globally and for its site.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _isLive(ElementInterface $element): bool
    {
        return $element->enabled && $element->getEnabledForSite() !== false;
    }

    /**
     * Builds a link from a raw href, working out on the way whether it is worth
     * checking at all and whether it belongs to this installation.
     *
     * @param string $rawHref The href exactly as it was stored.
     * @param string|null $linkText The anchor or alt text.
     * @param FieldInterface|null $field The field the link was found in.
     * @param ElementInterface $element The element the link was found in.
     * @param int $ownerElementId The root element that owns the content.
     * @param string $source One of the ExtractedLink SOURCE_* constants.
     * @return ExtractedLink|null The link, or null when there is nothing to
     *                            record.
     * @throws InvalidConfigException If the element is not on a valid site.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _link(
        string $rawHref,
        ?string $linkText,
        ?FieldInterface $field,
        ElementInterface $element,
        int $ownerElementId,
        string $source = ExtractedLink::SOURCE_FIELD,
    ): ?ExtractedLink {
        $rawHref = trim($rawHref);

        if ($rawHref === '') {
            return null;
        }

        $referenceTag = $this->_referenceTagMatch($rawHref);

        if ($referenceTag !== null) {
            return $this->_referenceTagLink(
                matches: $referenceTag,
                rawHref: $rawHref,
                linkText: $linkText,
                field: $field,
                element: $element,
                ownerElementId: $ownerElementId,
                source: $source,
            );
        }

        $scheme = UrlNormaliser::classifyScheme($rawHref);

        // A mailto, a tel or a bare fragment is not a document, so there is
        // nothing to check and nothing to report.
        if ($scheme === SchemeKind::Skippable) {
            return null;
        }

        $siteId = $element->getSite()->id;
        $normalised = UrlNormaliser::normalise(
            $rawHref,
            $this->_baseUrlFor($siteId),
            $this->_settings()->stripTrackingParams,
        );

        if ($normalised === null) {
            // A scheme the plugin does not check is worth a row, so the author
            // can see it was met. A href too broken to parse is not.
            if ($scheme !== SchemeKind::Unknown) {
                return null;
            }

            return new ExtractedLink(
                kind: LinkKind::Ignored,
                elementId: (int)$element->id,
                elementType: $element::class,
                ownerElementId: $ownerElementId,
                siteId: $siteId,
                url: $rawHref,
                rawHref: $rawHref,
                fieldUid: $field?->uid,
                fieldHandle: $field?->handle,
                linkText: $this->_tidyText($linkText),
                source: $source,
            );
        }

        $isInternal = UrlNormaliser::isInternal($normalised, array_values($this->_siteBaseUrls()));

        // The one place a fragment is kept. `/about#team` and `/about#staff` are
        // two questions about the same page, so on an installation that has
        // asked for anchors to be validated they need a row each; everywhere
        // else the fragment stays off and both share the page's one row.
        if ($isInternal && $this->_settings()->checksAnchorFragments()) {
            $normalised = UrlNormaliser::withFragment($normalised, $rawHref);
        }

        return new ExtractedLink(
            kind: $isInternal ? LinkKind::Internal : LinkKind::External,
            elementId: (int)$element->id,
            elementType: $element::class,
            ownerElementId: $ownerElementId,
            siteId: $siteId,
            url: $normalised,
            rawHref: $rawHref,
            fieldUid: $field?->uid,
            fieldHandle: $field?->handle,
            linkText: $this->_tidyText($linkText),
            source: $source,
        );
    }

    /**
     * The id of the element a Link field value points at, read straight off the
     * stored reference so a soft-deleted target is still reported rather than
     * silently dropped.
     *
     * @param LinkData $value The stored value.
     * @return int|null The element id, or null when the value is not an element
     *                  reference.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _linkDataElementId(LinkData $value): ?int
    {
        $raw = $this->_linkDataRawValue($value);

        if (preg_match('/^\{[a-z][\w]*:(\d+)(?:@\d+)?:url\}$/i', $raw, $matches) !== 1) {
            return null;
        }

        return (int)$matches[1];
    }

    /**
     * The label of a Link field value, for the report.
     *
     * @param LinkData $value The stored value.
     * @return string|null The label.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _linkDataLabel(LinkData $value): ?string
    {
        try {
            return $value->getLabel();
        } catch (Throwable $e) {
            Craft::warning("Could not read a link field's label: {$e->getMessage()}", 'link-audit');

            return null;
        }
    }

    /**
     * Reads a native Link field value.
     *
     * The link type decides everything: an entry, category or asset link is an
     * element link, validated by whether that element is still there; a URL link
     * is an ordinary candidate; and an email, phone or SMS link carries its own
     * scheme, so it falls out as skippable without needing a case of its own.
     *
     * @param LinkData $value The stored value.
     * @param FieldInterface $field The field it came from.
     * @param ElementInterface $element The element the field belongs to.
     * @param int $ownerElementId The root element that owns the content.
     * @return ExtractedLink[] The links found.
     * @throws InvalidConfigException If the element is not on a valid site.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _linkDataLinks(
        LinkData $value,
        FieldInterface $field,
        ElementInterface $element,
        int $ownerElementId,
    ): array {
        $query = $value->getElementQuery();

        if ($query === null) {
            $link = $this->_link(
                rawHref: $value->getUrl(),
                linkText: $this->_linkDataLabel($value),
                field: $field,
                element: $element,
                ownerElementId: $ownerElementId,
            );

            return $link !== null ? [$link] : [];
        }

        $target = $query->one();
        $targetElementId = $target?->id ?? $this->_linkDataElementId($value);

        if ($targetElementId === null) {
            return [];
        }

        return [
            $this->_elementLink(
                targetElementId: $targetElementId,
                target: $target,
                element: $element,
                ownerElementId: $ownerElementId,
                field: $field,
                linkText: $this->_linkDataLabel($value),
                rawHref: $this->_linkDataRawValue($value),
            ),
        ];
    }

    /**
     * The value a Link field stored, before the link type made a URL of it.
     *
     * @param LinkData $value The stored value.
     * @return string The stored value.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _linkDataRawValue(LinkData $value): string
    {
        $serialized = $value->serialize();

        return is_array($serialized) ? (string)($serialized['value'] ?? '') : '';
    }

    /**
     * The links held by one navigation node.
     *
     * A node either points at an element, carries a URL of its own, or is
     * passive: a heading with nothing behind it, which has nothing to check.
     *
     * @param Node $node The node to read.
     * @return ExtractedLink[] The links found.
     * @throws InvalidConfigException If the node is not on a valid site.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _navNodeLinks(Node $node): array
    {
        if ($node->id === null || $node->isPassive()) {
            return [];
        }

        if ($node->isElement()) {
            $targetElementId = $node->elementId;

            if ($targetElementId === null) {
                return [];
            }

            return [
                $this->_elementLink(
                    targetElementId: (int)$targetElementId,
                    target: $node->getElement(),
                    element: $node,
                    ownerElementId: (int)$node->id,
                    field: null,
                    linkText: $node->title,
                    rawHref: ExtractedLink::syntheticUrl((int)$targetElementId),
                    source: ExtractedLink::SOURCE_NAV,
                ),
            ];
        }

        $rawHref = $this->_navNodeUrl($node);

        if ($rawHref === null) {
            return [];
        }

        $link = $this->_link(
            rawHref: $rawHref,
            linkText: $node->title,
            field: null,
            element: $node,
            ownerElementId: (int)$node->id,
            source: ExtractedLink::SOURCE_NAV,
        );

        return $link !== null ? [$link] : [];
    }

    /**
     * The URL a navigation node points at.
     *
     * A custom node's URL is read and its environment variables parsed here
     * rather than through the node's own getter, which renders the value as a
     * Twig object template against the current user. There is no request and no
     * user in a queue worker, so a URL that needs one is left alone instead of
     * being resolved to something meaningless.
     *
     * @param Node $node The node to read.
     * @return string|null The URL, or null when there is nothing checkable.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _navNodeUrl(Node $node): ?string
    {
        if (!$node->isCustom()) {
            try {
                return $node->getUrl();
            } catch (Throwable $e) {
                Craft::warning(
                    "Could not read the URL of navigation node {$node->id}: {$e->getMessage()}",
                    'link-audit',
                );

                return null;
            }
        }

        $url = trim((string)App::parseEnv((string)$node->getRawUrl()));

        if ($url === '' || str_contains($url, '{')) {
            return null;
        }

        return $url . (string)$node->urlSuffix;
    }

    /**
     * Walks the entries nested inside a rich text field.
     *
     * The placeholders are read out of the stored HTML rather than asked of the
     * field's own chunk API, which reaches for the current request to decide
     * about previews and embeds. A queue worker has no such request, and the
     * ids in the markup are the same ids either way.
     *
     * @param DOMXPath $xpath The parsed field content.
     * @param ElementInterface $element The element the field belongs to.
     * @param int $ownerElementId The root element that owns the content.
     * @param int $depth How far down the nesting this element sits.
     * @return ExtractedLink[] The links found.
     * @throws InvalidConfigException If the element is not on a valid site.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _nestedEntryLinks(
        DOMXPath $xpath,
        ElementInterface $element,
        int $ownerElementId,
        int $depth,
    ): array {
        if ($depth >= self::MAX_DEPTH) {
            return [];
        }

        $nodes = $xpath->query('//craft-entry[@data-entry-id]');

        if ($nodes === false) {
            return [];
        }

        $entryIds = [];

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $entryId = (int)$node->getAttribute('data-entry-id');

            if ($entryId > 0) {
                $entryIds[] = $entryId;
            }
        }

        if ($entryIds === []) {
            return [];
        }

        $entries = Entry::find()
            ->id($entryIds)
            ->siteId($element->getSite()->id)
            ->status(null)
            ->drafts(null)
            ->revisions(null)
            ->all();

        $found = [];

        foreach ($entries as $entry) {
            if (!$this->_isLive($entry)) {
                continue;
            }

            $found[] = $this->_fromElement(
                element: $entry,
                ownerElementId: $ownerElementId,
                depth: $depth + 1,
            );
        }

        return array_merge([], ...$found);
    }

    /**
     * Reads a plain string field value.
     *
     * Only whole values that parse as an absolute http(s) URL count, and only
     * when the setting is on. Scraping URLs out of prose is the noisiest source
     * of false positives there is, so this stays deliberately blunt.
     *
     * @param string $value The stored value.
     * @param FieldInterface $field The field it came from.
     * @param ElementInterface $element The element the field belongs to.
     * @param int $ownerElementId The root element that owns the content.
     * @return ExtractedLink[] The links found.
     * @throws InvalidConfigException If the element is not on a valid site.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _plainTextLinks(
        string $value,
        FieldInterface $field,
        ElementInterface $element,
        int $ownerElementId,
    ): array {
        if (!$this->_settings()->scanPlainTextUrls) {
            return [];
        }

        $value = trim($value);

        if (preg_match('/^https?:\/\/\S+$/i', $value) !== 1) {
            return [];
        }

        $link = $this->_link(
            rawHref: $value,
            linkText: null,
            field: $field,
            element: $element,
            ownerElementId: $ownerElementId,
        );

        return $link !== null ? [$link] : [];
    }

    /**
     * The element class a reference tag's type segment names, restricted to
     * the handles this extractor resolves on its own.
     *
     * Deliberately not delegated to
     * {@see \craft\services\Elements::getElementTypeByRefHandle()}: that call
     * falls back to Entry whenever a category or tag group has been
     * entrified, so the same tag could answer differently depending on
     * project config a queue worker has no reason to know about.
     *
     * @param string $refHandle The type segment of the tag, e.g. `entry`.
     * @return string|null The element class, or null when the handle is not
     *                     one this extractor resolves.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _referenceTagElementType(string $refHandle): ?string
    {
        return match (strtolower($refHandle)) {
            'entry' => Entry::class,
            'asset' => Asset::class,
            'category' => Category::class,
            default => null,
        };
    }

    /**
     * Turns a reference tag into a link, or into whatever its fallback says
     * instead.
     *
     * A known type with a numeric id becomes an element link, checked the
     * same way a native Link field's entry link is: by whether the target
     * still exists, is enabled, and has a URL, never by fetching anything.
     * Everything else, a type this extractor does not know or an id that is
     * not numeric, falls back to the `||` fallback URL when the tag carries
     * one, and is skipped with a debug log otherwise.
     *
     * @param array<int|string, string> $matches The named capture groups from
     *                                           {@see self::_referenceTagMatch()}.
     * @param string $rawHref The href exactly as it was stored, for display.
     * @param string|null $linkText The anchor or alt text.
     * @param FieldInterface|null $field The field the link was found in.
     * @param ElementInterface $element The element the link was found in.
     * @param int $ownerElementId The root element that owns the content.
     * @param string $source One of the ExtractedLink SOURCE_* constants.
     * @return ExtractedLink|null The link, or null when neither the tag nor
     *                            its fallback is usable.
     * @throws InvalidConfigException If the element is not on a valid site.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _referenceTagLink(
        array $matches,
        string $rawHref,
        ?string $linkText,
        ?FieldInterface $field,
        ElementInterface $element,
        int $ownerElementId,
        string $source,
    ): ?ExtractedLink {
        $elementType = $this->_referenceTagElementType((string)$matches['elementType']);
        $ref = (string)$matches['ref'];
        $fallback = trim((string)($matches['fallback'] ?? ''));

        if ($elementType !== null && is_numeric($ref)) {
            $siteId = $this->_referenceTagSiteId(
                isset($matches['site']) ? (string)$matches['site'] : null,
                $element,
            );
            $target = Craft::$app->getElements()->getElementById((int)$ref, $elementType, $siteId);

            return $this->_elementLink(
                targetElementId: (int)$ref,
                target: $target,
                element: $element,
                ownerElementId: $ownerElementId,
                field: $field,
                linkText: $linkText,
                rawHref: $rawHref,
                source: $source,
                siteId: $siteId,
            );
        }

        if ($fallback !== '') {
            return $this->_link(
                rawHref: $fallback,
                linkText: $linkText,
                field: $field,
                element: $element,
                ownerElementId: $ownerElementId,
                source: $source,
            );
        }

        Craft::warning("Skipped a reference tag with no usable fallback: $rawHref", 'link-audit');

        return null;
    }

    /**
     * The named capture groups of a reference tag, when a href is nothing
     * but one.
     *
     * Anchored to the whole string, so a stray `{` inside an otherwise
     * ordinary URL, a dynamic nav path like `/{slug}` for instance, is never
     * mistaken for a tag.
     *
     * @param string $rawHref The href to check.
     * @return array<int|string, string>|null The named capture groups, or
     *                                        null when the href is not a
     *                                        single reference tag.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _referenceTagMatch(string $rawHref): ?array
    {
        // HTML Purifier sometimes percent-encodes the braces on save, so a
        // stored tag can read `%7Basset:45684:url%7D`. Decode before matching,
        // but only when the string starts with an encoded brace: decoding an
        // ordinary URL here would corrupt legitimately encoded characters.
        if (stripos($rawHref, '%7B') === 0) {
            $rawHref = rawurldecode($rawHref);
        }

        if (preg_match(self::_REFERENCE_TAG_PATTERN, $rawHref, $matches) !== 1) {
            return null;
        }

        return $matches;
    }

    /**
     * The site a reference tag's `@site` segment names, or the site the
     * containing element is on when the tag carries none.
     *
     * @param string|null $site The `@site` segment, a numeric id or a
     *                          handle, or null when the tag had none.
     * @param ElementInterface $element The element the link was found in.
     * @return int The site id.
     * @throws InvalidConfigException If the element is not on a valid site.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _referenceTagSiteId(?string $site, ElementInterface $element): int
    {
        if ($site === null || $site === '') {
            return $element->getSite()->id;
        }

        if (is_numeric($site)) {
            return (int)$site;
        }

        return Craft::$app->getSites()->getSiteByHandle($site)?->id ?? $element->getSite()->id;
    }

    /**
     * The plugin's settings.
     *
     * @return SettingsModel The settings.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _settings(): SettingsModel
    {
        return LinkAudit::$plugin->getSettings();
    }

    /**
     * The base URL of every site that serves one, keyed by site id.
     *
     * @return array<int, string> The base URLs.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _siteBaseUrls(): array
    {
        if ($this->_baseUrls !== []) {
            return $this->_baseUrls;
        }

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $baseUrl = $site->getBaseUrl();

            if ($baseUrl !== null) {
                $this->_baseUrls[$site->id] = $baseUrl;
            }
        }

        return $this->_baseUrls;
    }

    /**
     * Reads a Table field's rows.
     *
     * @param array<mixed> $rows The stored rows.
     * @param FieldInterface $field The field they came from.
     * @param ElementInterface $element The element the field belongs to.
     * @param int $ownerElementId The root element that owns the content.
     * @return ExtractedLink[] The links found.
     * @throws InvalidConfigException If the element is not on a valid site.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _tableLinks(
        array $rows,
        FieldInterface $field,
        ElementInterface $element,
        int $ownerElementId,
    ): array {
        if (!$this->_settings()->scanPlainTextUrls) {
            return [];
        }

        $found = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($row as $cell) {
                if (!is_string($cell)) {
                    continue;
                }

                $found[] = $this->_plainTextLinks(
                    value: $cell,
                    field: $field,
                    element: $element,
                    ownerElementId: $ownerElementId,
                );
            }
        }

        return array_merge([], ...$found);
    }

    /**
     * Collapses the whitespace out of anchor text and clips it to something a
     * report column can hold.
     *
     * @param string|null $text The text as it was found.
     * @return string|null The tidied text, or null when there was none.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _tidyText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $tidied = trim((string)preg_replace('/\s+/u', ' ', $text));

        return $tidied !== '' ? mb_substr($tidied, 0, 255) : null;
    }
}
