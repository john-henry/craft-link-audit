<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTimeInterface;
use johnhenry\linkaudit\enums\ScanStatus;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\models\ExtractedLink;
use johnhenry\linkaudit\models\Verdict;
use johnhenry\linkaudit\records\ReferenceRecord;
use johnhenry\linkaudit\records\ScanRecord;
use johnhenry\linkaudit\records\UrlRecord;
use Throwable;
use yii\base\Component;
use yii\caching\TagDependency;

/**
 * Everything the control panel reads.
 *
 * The report screens are all the same question asked different ways: which URLs
 * hold a given verdict, and what points at them. Keeping that in one service
 * means the counts on the overview and the rows in the lists cannot drift apart,
 * and the controllers stay what they should be, which is a permission check and
 * a template name.
 *
 * Everything here is scoped by site through the references table, never through
 * the URL row. An external URL's verdict is the same answer on every site, so
 * its row is global; what makes it this site's problem is a reference to it from
 * content on this site. Scoping the other way would hide every external URL the
 * moment an install had a second site.
 *
 * Dates go through `DateTimeHelper` rather than Carbon, as they do in
 * {@see UrlStore} and {@see ScanService}: nothing in here is date arithmetic for
 * its own sake, it is all a column on its way out of the database.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class ReportService extends Component
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var string The cache tag every stored verdict count carries.
     *
     * Anything that can move a count invalidates this tag rather than working
     * out which sites it touched: the counts are two queries to rebuild, and a
     * write that has to reason about site scope to clear a cache is a write
     * waiting to get it wrong.
     */
    public const CACHE_TAG_COUNTS = 'link-audit:verdict-counts';

    /**
     * @var int The most reference rows one URL detail page will list.
     *
     * A footer link on a ten thousand page site has ten thousand references, and
     * nobody reads past the first screenful. The page says how many there are in
     * total either way.
     */
    public const MAX_REFERENCES = 100;

    /**
     * @var int How long a stored set of verdict counts is trusted for.
     *
     * A backstop rather than the mechanism: the writes that move a count clear
     * the tag as they go, and this covers the ones that do not bother, such as a
     * migration or a row pruned as an orphan. Short enough that nobody notices,
     * long enough that a burst of control panel page loads costs one pair of
     * queries between them.
     */
    private const _COUNTS_TTL = 60;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * The verdict counts for a site, kept in the cache between reads.
     *
     * For the control panel navigation, which asks for them on every single page
     * load, badge or no badge. {@see self::verdictCounts()} is two queries, which
     * is two queries too many to hang off the chrome, so the answer is stored
     * under {@see self::CACHE_TAG_COUNTS} and cleared by the writes that move it:
     * a verdict landing on a URL, an author ignoring or restoring one, and an
     * element being deleted out from under its references. A short time to live
     * covers whatever else touches a row without saying so.
     *
     * @param int $siteId The site being read.
     * @return array<string, int> Verdict value to count, plus
     *                            `permanentRedirect`, exactly as
     *                            {@see self::verdictCounts()} returns them.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function cachedVerdictCounts(int $siteId): array
    {
        $cache = Craft::$app->getCache();
        $key = ['link-audit', 'verdict-counts', $siteId];
        $cached = $cache->get($key);

        if (is_array($cached)) {
            /** @var array<string, int> $cached */
            return $cached;
        }

        $counts = $this->verdictCounts($siteId);

        $cache->set(
            $key,
            $counts,
            self::_COUNTS_TTL,
            new TagDependency(['tags' => self::CACHE_TAG_COUNTS]),
        );

        return $counts;
    }

    /**
     * What one element's links add up to, for the panel on its edit screen.
     *
     * The element is asked about as an owner as well as in its own right. A link
     * inside a Matrix block belongs to the block, and an author editing the page
     * has no idea the block is an element at all: what they want to be told is
     * that the page they are looking at is carrying a broken link.
     *
     * Counted distinct by URL rather than by reference row, because the same
     * address pasted into three fields on the one page is one thing to fix.
     *
     * @param int $elementId The element being edited.
     * @param int $siteId The site it is being edited on.
     * @param int $limit The most broken URLs to name.
     * @return array{total: int, checked: int, broken: int, lastChecked: DateTimeInterface|null, brokenUrls: array<int, array<string, mixed>>}
     *         The totals, when the last of them was asked about, and the broken
     *         ones worth naming.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function elementSummary(int $elementId, int $siteId, int $limit = 5): array
    {
        $rows = (new Query())
            ->select([
                'status' => 'u.status',
                'total' => 'COUNT(DISTINCT [[r.urlId]])',
                // The panel's header says when this page's links were last
                // asked about, and the answer is the most recent of them.
                'lastChecked' => 'MAX([[u.dateLastChecked]])',
            ])
            ->from(['r' => ReferenceRecord::tableName()])
            ->innerJoin(['u' => UrlRecord::tableName()], '[[u.id]] = [[r.urlId]]')
            ->where($this->_heldByElement($elementId, $siteId))
            ->groupBy(['u.status'])
            ->all();

        $total = 0;
        $pending = 0;
        $broken = 0;
        $lastChecked = null;

        foreach ($rows as $row) {
            $count = (int)$row['total'];
            $total += $count;

            if ((string)$row['status'] === UrlStatus::Pending->value) {
                $pending += $count;
            }

            if ((string)$row['status'] === UrlStatus::Broken->value) {
                $broken += $count;
            }

            $checked = $row['lastChecked'] !== null
                ? DateTimeHelper::toDateTime((string)$row['lastChecked'])
                : false;

            if ($checked instanceof DateTimeInterface && ($lastChecked === null || $checked > $lastChecked)) {
                $lastChecked = $checked;
            }
        }

        $brokenUrls = $broken === 0 ? [] : (new Query())
            ->select(['url' => 'u.url', 'urlHash' => 'u.urlHash', 'httpStatus' => 'u.httpStatus'])
            ->distinct()
            ->from(['r' => ReferenceRecord::tableName()])
            ->innerJoin(['u' => UrlRecord::tableName()], '[[u.id]] = [[r.urlId]]')
            ->where($this->_heldByElement($elementId, $siteId))
            ->andWhere(['u.status' => UrlStatus::Broken->value])
            ->orderBy(['u.url' => SORT_ASC])
            ->limit($limit)
            ->all();

        foreach ($brokenUrls as $i => $brokenUrl) {
            $brokenUrls[$i]['httpStatusLabel'] = Verdict::httpStatusLabel(
                $brokenUrl['httpStatus'] !== null ? (int)$brokenUrl['httpStatus'] : null,
            );
        }

        return [
            'total' => $total,
            // Anything still pending has been found but not yet asked about, so
            // saying it was checked would be a lie an author would catch.
            'checked' => $total - $pending,
            'broken' => $broken,
            'lastChecked' => $lastChecked,
            'brokenUrls' => $brokenUrls,
        ];
    }

    /**
     * What an element class is called on screen.
     *
     * Public because the export reads the same rows the report screens do, and
     * a second copy of this would drift from the labels the reader sees on the
     * list they exported from.
     *
     * @param string $class The stored element class.
     * @return string Its display name, or the class itself when the plugin that
     *                supplied it is no longer installed. A reference outlives
     *                its element type, so this really happens.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function elementTypeLabel(string $class): string
    {
        if (!class_exists($class) || !is_subclass_of($class, ElementInterface::class)) {
            return $class;
        }

        return $class::displayName();
    }

    /**
     * The element types that hold a URL with the given verdict, for the filter
     * menu.
     *
     * @param UrlStatus $status The verdict being listed.
     * @param int $siteId The site being read.
     * @return array<string, string> Element class to display name.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function elementTypeOptions(UrlStatus $status, int $siteId): array
    {
        $types = (new Query())
            ->select(['r.elementType'])
            ->distinct()
            ->from(['r' => ReferenceRecord::tableName()])
            ->innerJoin(['u' => UrlRecord::tableName()], '[[u.id]] = [[r.urlId]]')
            ->where(['r.siteId' => $siteId, 'u.status' => $status->value])
            ->orderBy(['r.elementType' => SORT_ASC])
            ->column();

        $options = [];

        foreach ($types as $type) {
            $options[(string)$type] = $this->elementTypeLabel((string)$type);
        }

        return $options;
    }

    /**
     * The name a content editor knows a reference's field by.
     *
     * The stored handle is developer vocabulary: an editor knows the field as
     * "Rich Text", not `richText`. And the global field name is not enough
     * either, because a field layout can override the label, and the override
     * is what the editor sees on the entry. So the layout of the element the
     * link sits on is asked first; a layout that hides the label altogether
     * answers with the block type's name instead, that being the only name the
     * editor has ever seen; the global field (by stored uid) stands in when
     * the layout cannot answer, the handle stands in when the field has since
     * been deleted, and null means the reference never came from a field at
     * all.
     *
     * Public for the same reason {@see self::elementTypeLabel()} is: the export
     * has to print the name the editor is looking for on screen, and a second
     * copy of this logic would drift from it.
     *
     * @param array<string, mixed> $row The stored reference row.
     * @param ElementInterface|null $element The element the reference sits on,
     *                                       when it could be loaded.
     * @return string|null The label the editor sees, or the best fallback, or
     *                     null when the reference has no field.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function fieldName(array $row, ?ElementInterface $element): ?string
    {
        $handle = $row['fieldHandle'] !== null ? (string)$row['fieldHandle'] : null;

        if ($handle === null) {
            return null;
        }

        if ($element !== null) {
            try {
                foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                    if ($field->handle !== $handle) {
                        continue;
                    }

                    // '__blank__' is Craft's way of saying the layout hides the
                    // label. The editor has never seen a name for this field,
                    // so for a field inside a block the block type's own name
                    // is the nearest thing to what they recognise.
                    if ($field->name !== '' && $field->name !== '__blank__') {
                        return $field->name;
                    }

                    $nested = $row['ownerElementId'] !== null
                        && (int)$row['ownerElementId'] !== (int)$row['elementId'];

                    if ($nested && $element instanceof Entry) {
                        return $element->getType()->name;
                    }

                    break;
                }
            } catch (Throwable) {
                // A layout that cannot be built is no reason to lose the row;
                // the global name below still answers.
            }
        }

        $uid = isset($row['fieldUid']) ? (string)$row['fieldUid'] : '';

        if ($uid !== '') {
            $field = Craft::$app->getFields()->getFieldByUid($uid);

            if ($field !== null) {
                return $field->name;
            }
        }

        return $handle;
    }

    /**
     * The hosts that hold a URL with the given verdict, for the filter menu.
     *
     * @param UrlStatus $status The verdict being listed.
     * @param int $siteId The site being read.
     * @return array<string, string> Host to host, alphabetically, in the shape
     *                               Craft's select macro takes.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function hostOptions(UrlStatus $status, int $siteId): array
    {
        $hosts = (new Query())
            ->select(['u.host'])
            ->distinct()
            ->from(['u' => UrlRecord::tableName()])
            ->where(['u.status' => $status->value])
            ->andWhere(['exists', $this->_referencedOnSite($siteId)])
            ->andWhere(['not', ['u.host' => '']])
            ->orderBy(['u.host' => SORT_ASC])
            ->column();

        $options = [];

        foreach ($hosts as $host) {
            $options[(string)$host] = (string)$host;
        }

        return $options;
    }

    /**
     * Throws away every stored verdict count.
     *
     * Called by the writes that can move one. Cheap enough to call per URL: it
     * is a single cache write against a request that has just been over the
     * network, and the alternative is a navigation badge that goes on claiming
     * eleven broken links after the scan that fixed them has finished.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function invalidateCounts(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), self::CACHE_TAG_COUNTS);
    }

    /**
     * The last scan to finish.
     *
     * @return array<string, mixed>|null The scan row with its duration worked
     *                                   out, or null when nothing has finished
     *                                   yet.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function latestScan(): ?array
    {
        $row = (new Query())
            ->from([ScanRecord::tableName()])
            ->where(['status' => ScanStatus::Complete->value])
            ->orderBy(['dateFinished' => SORT_DESC, 'id' => SORT_DESC])
            ->one();

        return is_array($row) ? $this->_withDuration($row) : null;
    }

    /**
     * How many places point at a URL.
     *
     * @param int $urlId The URL row.
     * @param int[] $siteIds The sites to count references on.
     * @return int The count.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function referenceCount(int $urlId, array $siteIds): int
    {
        return (int)(new Query())
            ->from([ReferenceRecord::tableName()])
            ->where(['urlId' => $urlId, 'siteId' => $siteIds])
            ->count();
    }

    /**
     * Every place a URL appears, with the element each one belongs to.
     *
     * The element loaded is the owner, not the element the link was found in: a
     * link inside a Matrix block belongs to the block for storage purposes and
     * to the entry for editing purposes, and an author offered an Edit link to a
     * nested entry gets a page they cannot open.
     *
     * @param int $urlId The URL row.
     * @param int[] $siteIds The sites to read references on.
     * @param User|null $user The user the Edit links are for. Nobody gets a link
     *                        to an element they may not view.
     * @param int $limit The most rows to return.
     * @return array<int, array<string, mixed>> The references, each with its
     *                                          `element`, `editUrl` and the
     *                                          stored row's own columns.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function references(int $urlId, array $siteIds, ?User $user = null, int $limit = self::MAX_REFERENCES): array
    {
        $rows = (new Query())
            ->from([ReferenceRecord::tableName()])
            ->where(['urlId' => $urlId, 'siteId' => $siteIds])
            ->orderBy(['siteId' => SORT_ASC, 'id' => SORT_ASC])
            ->limit($limit)
            ->all();

        $elements = Craft::$app->getElements();
        $references = [];

        foreach ($rows as $row) {
            $siteId = (int)$row['siteId'];
            $ownerId = $row['ownerElementId'] !== null
                ? (int)$row['ownerElementId']
                : (int)$row['elementId'];

            // The owner's class is not stored, only the nested element's, so
            // Craft is left to look it up. That costs a query per row, which is
            // what the row limit is for.
            $element = $elements->getElementById($ownerId, null, $siteId);
            $canView = $element !== null && $user !== null && $element->canView($user);

            // The field label has to come off the element the link actually
            // sits on: a layout can override a field's label, and the override
            // is the only name a content editor has ever seen.
            $fieldElement = (int)$row['elementId'] === $ownerId
                ? $element
                : $elements->getElementById((int)$row['elementId'], null, $siteId);

            $references[] = [
                'element' => $element,
                'elementType' => $this->elementTypeLabel((string)$row['elementType']),
                'editUrl' => $canView ? $element->getCpEditUrl() : null,
                'fieldHandle' => $row['fieldHandle'] !== null ? (string)$row['fieldHandle'] : null,
                'fieldName' => $this->fieldName($row, $fieldElement),
                'linkText' => $row['linkText'] !== null ? (string)$row['linkText'] : null,
                'nested' => $row['ownerElementId'] !== null && (int)$row['ownerElementId'] !== (int)$row['elementId'],
                'rawHref' => $row['rawHref'] !== null ? (string)$row['rawHref'] : null,
                'site' => Craft::$app->getSites()->getSiteById($siteId),
                'source' => (string)$row['source'],
            ];
        }

        return $references;
    }

    /**
     * The scan that is running now, if one is.
     *
     * @return array<string, mixed>|null The scan row, or null when nothing is
     *                                   running.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function runningScan(): ?array
    {
        $row = (new Query())
            ->from([ScanRecord::tableName()])
            ->where([
                'status' => [
                    ScanStatus::Queued->value,
                    ScanStatus::Extracting->value,
                    ScanStatus::Checking->value,
                ],
            ])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        return is_array($row) ? $row : null;
    }

    /**
     * What each source of a link is called on screen.
     *
     * One map, read by the filter menus on the list screens and by the export,
     * so a column and the filter above it cannot end up calling the same thing
     * by two different names.
     *
     * @return array<string, string> Source handle to label.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function sourceOptions(): array
    {
        return [
            ExtractedLink::SOURCE_FIELD => Craft::t('link-audit', 'A field'),
            ExtractedLink::SOURCE_NAV => Craft::t('link-audit', 'A navigation'),
            ExtractedLink::SOURCE_RENDERED => Craft::t('link-audit', 'A page template'),
        ];
    }

    /**
     * The hosts with the most broken URLs on a site.
     *
     * @param int $siteId The site being read.
     * @param int $limit How many hosts to return.
     * @return array<int, array{host: string, total: int}> The hosts, worst
     *                                                     first.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function topHosts(int $siteId, int $limit = 5): array
    {
        $rows = (new Query())
            ->select(['host' => 'u.host', 'total' => 'COUNT(*)'])
            ->from(['u' => UrlRecord::tableName()])
            ->where(['u.status' => UrlStatus::Broken->value])
            ->andWhere(['exists', $this->_referencedOnSite($siteId)])
            // Element-link stand-ins carry no host at all, and a hosts table
            // with a nameless row in it reads as a rendering bug. Their
            // brokenness is already on the tiles and the Broken list.
            ->andWhere(['not', ['u.host' => '']])
            ->groupBy(['u.host'])
            ->orderBy(['total' => SORT_DESC, 'host' => SORT_ASC])
            ->limit($limit)
            ->all();

        return array_map(
            static fn(array $row): array => [
                'host' => (string)$row['host'],
                'total' => (int)$row['total'],
            ],
            $rows,
        );
    }

    /**
     * The pages carrying the most broken links on a site.
     *
     * Grouped by the owning element rather than the element the link was found
     * in, so five broken links spread across a page's Matrix blocks read as one
     * page with five problems, which is how an author thinks about it.
     *
     * @param int $siteId The site being read.
     * @param int $limit How many pages to return.
     * @return array<int, array{element: ElementInterface|null, elementId: int, total: int}>
     *         The pages, worst first.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function topPages(int $siteId, int $limit = 5): array
    {
        $owner = 'COALESCE([[r.ownerElementId]], [[r.elementId]])';

        $rows = (new Query())
            ->select(['elementId' => $owner, 'total' => "COUNT(DISTINCT [[r.urlId]])"])
            ->from(['r' => ReferenceRecord::tableName()])
            ->innerJoin(['u' => UrlRecord::tableName()], '[[u.id]] = [[r.urlId]]')
            ->where(['r.siteId' => $siteId, 'u.status' => UrlStatus::Broken->value])
            ->groupBy([$owner])
            ->orderBy(['total' => SORT_DESC])
            ->limit($limit)
            ->all();

        $elements = Craft::$app->getElements();
        $pages = [];

        foreach ($rows as $row) {
            $elementId = (int)$row['elementId'];

            $pages[] = [
                'element' => $elements->getElementById($elementId, null, $siteId),
                'elementId' => $elementId,
                'total' => (int)$row['total'],
            ];
        }

        return $pages;
    }

    /**
     * One URL row, by the hash the control panel addresses it with.
     *
     * The hash rather than the id, because the id is a per install auto
     * increment and the hash is the URL itself: a link to a report page means
     * the same URL on staging as it does on production.
     *
     * @param string $hash The sha1 of the normalised URL.
     * @return array<string, mixed>|null The row, or null when no such URL has
     *                                   been seen.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function urlByHash(string $hash): ?array
    {
        $row = (new Query())
            ->from([UrlRecord::tableName()])
            ->where(['urlHash' => $hash])
            ->one();

        return is_array($row) ? $row : null;
    }

    /**
     * How many URLs a list screen would hold, without reading any of them.
     *
     * For the screens themselves, which want the number up front so they can
     * offer a proper empty state rather than an empty table with a paginator
     * under it. Asking {@see self::urlTable()} for one row to read its total off
     * paid for the whole sort, including the correlated reference count, to
     * throw the row away again.
     *
     * @param UrlStatus $status The verdict to count.
     * @param int $siteId The site being read.
     * @param array<string, mixed> $filters Any of `host`, `elementType`,
     *                                      `source`, `permanent` and `search`.
     * @return int The count.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function urlCount(UrlStatus $status, int $siteId, array $filters = []): int
    {
        return (int)$this->_urlQuery($status, $siteId, $filters)->count();
    }

    /**
     * A page of URLs holding one verdict, for a list screen.
     *
     * The default sort is the last checked date rather than the reference count,
     * and that is a performance decision rather than an editorial one. The
     * reference count is a correlated subquery: sorting on it runs that subquery
     * for every row the filters match and then sorts the lot in a temporary
     * file, which on a site with tens of thousands of URLs is the difference
     * between a list screen and a timeout. The last checked date is an indexed
     * column. The count is still there to sort by, it is just something the
     * reader asks for rather than something every page load pays for.
     *
     * @param UrlStatus $status The verdict to list.
     * @param int $siteId The site being read.
     * @param array<string, mixed> $filters Any of `host`, `elementType`,
     *                                      `source`, `permanent` and `search`.
     * @param int $page The page number, from one.
     * @param int $perPage How many rows a page holds.
     * @param string $sort One of `url`, `host`, `httpStatus`, `lastChecked` or
     *                     `refCount`.
     * @param int $direction `SORT_ASC` or `SORT_DESC`.
     * @return array{total: int, rows: array<int, array<string, mixed>>} The page
     *         of rows, and how many there are in total.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function urlTable(
        UrlStatus $status,
        int $siteId,
        array $filters = [],
        int $page = 1,
        int $perPage = 100,
        string $sort = 'lastChecked',
        int $direction = SORT_DESC,
    ): array {
        $query = $this->_urlQuery($status, $siteId, $filters);
        $total = (int)(clone $query)->count();

        $orderBy = match ($sort) {
            'url' => 'u.url',
            'host' => 'u.host',
            'httpStatus' => 'u.httpStatus',
            'refCount' => 'refCount',
            default => 'u.dateLastChecked',
        };

        $rows = $query
            ->select([
                'id' => 'u.id',
                'url' => 'u.url',
                'urlHash' => 'u.urlHash',
                'host' => 'u.host',
                // Carried so the table can tell a URL it may hand out as a link
                // from one it may only print. A URL the plugin never checks is
                // stored exactly as somebody typed it.
                'scheme' => 'u.scheme',
                'status' => 'u.status',
                'httpStatus' => 'u.httpStatus',
                'reason' => 'u.reason',
                'finalUrl' => 'u.finalUrl',
                'redirectCount' => 'u.redirectCount',
                'redirectPermanent' => 'u.redirectPermanent',
                'redirectStatus' => 'u.redirectStatus',
                'dateLastChecked' => 'u.dateLastChecked',
                'refCount' => $this->_referencedOnSite($siteId, $filters)->select(['COUNT(*)']),
            ])
            // The id breaks ties, so a row cannot appear on two pages, or on
            // none, when a dozen URLs share a reference count.
            ->orderBy([$orderBy => $direction, 'u.id' => SORT_ASC])
            ->offset(max(0, ($page - 1) * $perPage))
            ->limit($perPage)
            ->all();

        return [
            'total' => $total,
            'rows' => $rows,
        ];
    }

    /**
     * How many URLs on a site hold each verdict.
     *
     * @param int $siteId The site being read.
     * @return array<string, int> Verdict value to count, plus
     *                            `permanentRedirect` for the redirects worth
     *                            acting on.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function verdictCounts(int $siteId): array
    {
        return $this->verdictCountsForSites([$siteId]);
    }

    /**
     * How many URLs hold each verdict across a set of sites.
     *
     * For the dashboard widget, which has no site switcher above it and so
     * answers for every site its reader is entitled to. Counted distinct by URL,
     * so one address referenced from three sites is one problem rather than
     * three.
     *
     * @param int[] $siteIds The sites being read.
     * @return array<string, int> Verdict value to count, plus
     *                            `permanentRedirect` for the redirects worth
     *                            acting on.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function verdictCountsForSites(array $siteIds): array
    {
        $counts = [];

        foreach (UrlStatus::cases() as $case) {
            $counts[$case->value] = 0;
        }

        $counts['permanentRedirect'] = 0;

        if ($siteIds === []) {
            return $counts;
        }

        $rows = (new Query())
            ->select(['status' => 'u.status', 'total' => 'COUNT(*)'])
            ->from(['u' => UrlRecord::tableName()])
            ->where(['exists', $this->_referencedOnSite($siteIds)])
            ->groupBy(['u.status'])
            ->all();

        foreach ($rows as $row) {
            $counts[(string)$row['status']] = (int)$row['total'];
        }

        // Counted apart from the rest of the redirects because it is the only
        // one that is work: a permanent redirect means the content still holds
        // an address the far end has already moved off.
        $counts['permanentRedirect'] = (int)(new Query())
            ->from(['u' => UrlRecord::tableName()])
            ->where(['u.status' => UrlStatus::Redirect->value, 'u.redirectPermanent' => true])
            ->andWhere(['exists', $this->_referencedOnSite($siteIds)])
            ->count();

        return $counts;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * The reference table condition for everything one element is answerable
     * for on one site.
     *
     * Both columns, because a reference records where a link is stored and where
     * it is edited, and those are the same element only when the link is not in
     * a nested one.
     *
     * @param int $elementId The element being asked about.
     * @param int $siteId The site being read.
     * @return array<int, mixed> The condition, in Yii's array form.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _heldByElement(int $elementId, int $siteId): array
    {
        return [
            'and',
            ['r.siteId' => $siteId],
            [
                'or',
                ['r.elementId' => $elementId],
                ['r.ownerElementId' => $elementId],
            ],
        ];
    }

    /**
     * The correlated subquery that ties a URL row to the site being read.
     *
     * Correlated on purpose: as an `EXISTS` it stops at the first matching
     * reference, and as a `COUNT` in the select list it gives the reference
     * count for the same scope, so both agree without a join that would
     * multiply the URL rows out and need distinguishing again.
     *
     * @param int|int[] $siteIds The site, or sites, being read.
     * @param array<string, mixed> $filters Any of `elementType` and `source`,
     *                                      which are properties of the
     *                                      reference rather than the URL.
     * @return Query The subquery.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _referencedOnSite(int|array $siteIds, array $filters = []): Query
    {
        $query = (new Query())
            ->select(['r.id'])
            ->from(['r' => ReferenceRecord::tableName()])
            ->where('[[r.urlId]] = [[u.id]]')
            ->andWhere(['r.siteId' => $siteIds]);

        $elementType = trim((string)($filters['elementType'] ?? ''));

        if ($elementType !== '') {
            $query->andWhere(Db::parseParam('r.elementType', $elementType));
        }

        $source = trim((string)($filters['source'] ?? ''));

        if ($source !== '') {
            $query->andWhere(Db::parseParam('r.source', $source));
        }

        return $query;
    }

    /**
     * The query behind a list screen, without its select list.
     *
     * Left without one so the caller can count it first and then read a page of
     * it, rather than counting a query that carries a correlated subquery in its
     * select list for every row it is about to throw away.
     *
     * @param UrlStatus $status The verdict to list.
     * @param int $siteId The site being read.
     * @param array<string, mixed> $filters The filters from the request.
     * @return Query The query.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _urlQuery(UrlStatus $status, int $siteId, array $filters): Query
    {
        $query = (new Query())
            ->from(['u' => UrlRecord::tableName()])
            ->where(['u.status' => $status->value])
            ->andWhere(['exists', $this->_referencedOnSite($siteId, $filters)]);

        $host = trim((string)($filters['host'] ?? ''));

        if ($host !== '') {
            $query->andWhere(Db::parseParam('u.host', $host));
        }

        $permanent = trim((string)($filters['permanent'] ?? ''));

        if ($permanent !== '') {
            $query->andWhere(['u.redirectPermanent' => $permanent === '1']);
        }

        $search = trim((string)($filters['search'] ?? ''));

        if ($search !== '') {
            $query->andWhere(Db::parseParam('u.url', '*' . $search . '*'));
        }

        return $query;
    }

    /**
     * Turns a scan row's stored dates into real dates, and works out how long
     * the run took.
     *
     * The dates are handed on as objects rather than as the strings the database
     * gave back, because those strings are UTC with nothing to say so: rendered
     * straight into a template they read as local time and are quietly wrong by
     * the server's offset.
     *
     * @param array<string, mixed> $scan The scan row.
     * @return array<string, mixed> The row, with `durationSeconds` set when both
     *                              ends of the run are known.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _withDuration(array $scan): array
    {
        foreach (['dateStarted', 'dateFinished'] as $column) {
            $date = $scan[$column] !== null
                ? DateTimeHelper::toDateTime((string)$scan[$column])
                : false;
            $scan[$column] = $date instanceof DateTimeInterface ? $date : null;
        }

        $scan['durationSeconds'] = $scan['dateStarted'] !== null && $scan['dateFinished'] !== null
            ? max(0, $scan['dateFinished']->getTimestamp() - $scan['dateStarted']->getTimestamp())
            : null;

        return $scan;
    }
}
