<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\services;

use Craft;
use craft\base\Element;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Queue as QueueHelper;
use DateTimeInterface;
use Exception;
use johnhenry\linkaudit\enums\ScanMode;
use johnhenry\linkaudit\enums\ScanStatus;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\exceptions\ScanInProgressException;
use johnhenry\linkaudit\jobs\CheckUrls;
use johnhenry\linkaudit\jobs\CrawlPages;
use johnhenry\linkaudit\jobs\ExtractLinks;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\ExtractedLink;
use johnhenry\linkaudit\models\SettingsModel;
use johnhenry\linkaudit\models\Verdict;
use johnhenry\linkaudit\records\ReferenceRecord;
use johnhenry\linkaudit\records\ScanRecord;
use johnhenry\linkaudit\records\UrlRecord;
use Throwable;
use yii\base\Component;
use yii\db\Expression;

/**
 * Runs a scan from end to end: which elements get read, what the counters say
 * while it is going, and what gets tidied away when it stops.
 *
 * The jobs are deliberately thin. Everything a scan actually does lives here, so
 * the console commands, the queue and the on-save hook all go through the same
 * code rather than three near-copies of it.
 *
 * A scan is three phases, chained rather than nested: extract the links out of
 * the content, ask the unique URLs whether they still work, then recount and
 * tidy. Each phase pushes the next when it finishes, which keeps every step
 * inside its own time to run instead of one job trying to hold the lot.
 *
 * Dates go through `DateTimeHelper` rather than Carbon, for the same reason
 * {@see UrlStore} does: nothing in here is date arithmetic for its own sake, it
 * is all a column on its way in or out of the database.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class ScanService extends Component
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var int How long a run may go untouched before another may be started
     * over the top of it. Every phase writes to the scan row as it works, so a
     * row that has gone quiet for this long belongs to a worker that died, and
     * one of those should not lock the plugin out for good.
     */
    private const _ABANDONED_AFTER_MINUTES = 60;

    /**
     * @var int How long a URL row nothing points at is left alone before it
     * counts as an orphan, so a row waiting on its reference rows is not deleted
     * out from under the insert that is about to point at it.
     */
    private const _ORPHAN_GRACE_MINUTES = 60;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Checks one chunk of URL rows and writes what came back.
     *
     * Internal rows are answered from the database rather than over HTTP, but
     * they are answered: their verdict has a time to live like any other, and a
     * row nobody settles would be offered on every pass for ever. The one
     * exception is a file-shaped internal URL that matched no element or route:
     * {@see InternalResolver::resolveUrl()} hands that one back as null, and it
     * joins the external URLs in this chunk's HTTP batch instead, its own host's
     * throttling applying automatically since the scheduler treats every host
     * alike.
     *
     * A deferral is not written as a verdict: the host asked to be left alone,
     * so the row keeps whatever it already knew and is simply offered again on a
     * later pass. Everything else lands on the row and moves the scan's
     * counters.
     *
     * The ignore rules are consulted here rather than in the query behind this,
     * so a rule added to the settings today applies to URLs stored long before
     * it: each one comes past the gate on its next due check and settles as
     * ignored without a request being made.
     *
     * @param array<int, array<string, mixed>> $rows The URL rows to check, as
     *                                               {@see UrlStore::pendingQuery()}
     *                                               returns them.
     * @param int|null $scanId The scan to count the results against.
     * @return int How many rows got a verdict, which is not the same as how many
     *             were offered.
     * @throws Exception If a time to live setting cannot be turned into an
     *                   interval.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function checkChunk(array $rows, ?int $scanId = null): int
    {
        $store = LinkAudit::$plugin->getUrlStore();
        $ignores = LinkAudit::$plugin->getIgnoreService();
        $checked = 0;
        $broken = 0;
        $external = [];

        foreach ($rows as $row) {
            // Asked here rather than in the query behind this, so a rule added
            // today quiets URLs that were stored months ago: every one of them
            // comes past this line the next time it is due, and settles as
            // ignored instead of being requested.
            $ignored = $ignores->verdictFor(
                (string)$row['url'],
                isset($row['urlHash']) ? (string)$row['urlHash'] : null,
            );

            if ($ignored !== null) {
                $store->recordVerdict((int)$row['id'], $ignored);
                $checked++;

                continue;
            }

            if (!(bool)($row['isInternal'] ?? false)) {
                $external[(string)$row['url']] = (int)$row['id'];

                continue;
            }

            $verdict = $this->_resolveStored($row);

            if ($verdict === null) {
                // A file-shaped internal URL that matched no element or route:
                // the database has nothing more to say, so it is judged the same
                // way an external URL is, own-host throttling included, since
                // the scheduler treats every host alike.
                $external[(string)$row['url']] = (int)$row['id'];

                continue;
            }

            $store->recordVerdict((int)$row['id'], $verdict);
            $checked++;

            if ($verdict->status === UrlStatus::Broken) {
                $broken++;
            }
        }

        if ($external !== []) {
            $verdicts = LinkAudit::$plugin->getRequestScheduler()->run(array_keys($external));

            foreach ($external as $url => $urlId) {
                $verdict = $verdicts[$url] ?? null;

                if ($verdict === null) {
                    continue;
                }

                if ($verdict->isDeferred()) {
                    $store->defer($urlId, $verdict->retryAfterSeconds);

                    continue;
                }

                $store->recordVerdict($urlId, $verdict);
                $checked++;

                if ($verdict->status === UrlStatus::Broken) {
                    $broken++;
                }
            }
        }

        if ($scanId !== null) {
            $this->recordCheckProgress($scanId, $checked, $broken);
        }

        return $checked;
    }

    /**
     * Queues whatever comes after the content phases.
     *
     * The crawl is a phase the scan only has when it has been asked for, so the
     * decision lives here rather than in the job that has to make it: the chain
     * is the same shape either way, and one method knows what the next link in
     * it is.
     *
     * @param int $scanId The scan being carried on.
     * @param int[] $siteIds The sites it covers.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function continueAfterExtraction(int $scanId, array $siteIds): void
    {
        if ($this->_settings()->renderedCrawlEnabled) {
            QueueHelper::push(new CrawlPages(['scanId' => $scanId, 'siteIds' => $siteIds]));

            return;
        }

        QueueHelper::push(new CheckUrls(['scanId' => $scanId]));
    }

    /**
     * The query behind the extract phase: every element a scan should read.
     *
     * Three things it deliberately does not do. It does not require a URI, since
     * an entry with no public page still has fields full of links. It does not
     * return nested elements in their own right, because the walker already
     * reaches them through whatever owns them, and processing them twice would
     * have two batches rebuilding the same reference rows. And it does not apply
     * the excluded URI patterns, which are author-written PCRE and belong in
     * PHP rather than in a regular expression operator that differs between
     * MySQL and Postgres; {@see self::isUriExcluded()} is the gate for those.
     *
     * @param int[] $siteIds The sites to read.
     * @param DateTimeInterface|null $since Only elements edited since this
     *                                      moment, for an incremental run.
     * @param int[]|null $elementIds Only these elements, for a rescan of a known
     *                               set.
     * @return Query The query, ordered so that paging cannot skip a row.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function elementQuery(
        array $siteIds,
        ?DateTimeInterface $since = null,
        ?array $elementIds = null,
    ): Query {
        $query = (new Query())
            ->select([
                'elementId' => 'es.elementId',
                'siteId' => 'es.siteId',
                'uri' => 'es.uri',
                'elementType' => 'e.type',
            ])
            ->from(['es' => Table::ELEMENTS_SITES])
            ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[es.elementId]]')
            ->leftJoin(['eo' => Table::ELEMENTS_OWNERS], '[[eo.elementId]] = [[es.elementId]]')
            ->where([
                'es.siteId' => $siteIds,
                'es.enabled' => true,
                'e.enabled' => true,
                'e.archived' => false,
                'e.draftId' => null,
                'e.revisionId' => null,
                'e.dateDeleted' => null,
                'eo.elementId' => null,
            ])
            ->andWhere(['e.type' => $this->_settings()->resolvedScannedElementTypes()])
            // Deterministic order is load-bearing for the batched job: the
            // batcher pages with LIMIT/OFFSET, and without a total order the
            // database may hand back a different order per page, silently
            // skipping some elements and reading others twice. Site and element
            // together are unique in this table, so this is a total order.
            ->orderBy(['es.siteId' => SORT_ASC, 'es.elementId' => SORT_ASC]);

        if ($since !== null) {
            $stamp = Db::prepareDateForDb($since);

            // Either date moving means the content on this site may have
            // changed: the element row carries edits to the element, the site
            // row carries edits to its content on this particular site.
            $query->andWhere([
                'or',
                ['>', 'e.dateUpdated', $stamp],
                ['>', 'es.dateUpdated', $stamp],
            ]);
        }

        if ($elementIds !== null) {
            $query->andWhere(['es.elementId' => $elementIds]);
        }

        return $query;
    }

    /**
     * Reads one element, stores every link it holds, and rebuilds its reference
     * rows.
     *
     * Internal links are answered here rather than left for the check phase:
     * the answer is already in the database, and asking your own server about a
     * page it just rendered is a request paid for twice.
     *
     * @param int $elementId The element to read.
     * @param string|null $elementType Its class, when the caller knows it.
     *                                 Without it Craft has to look the type up
     *                                 first, which costs a query.
     * @param int $siteId The site to read it on.
     * @param int|null $scanId The scan to stamp the reference rows with.
     * @param string|null $uri The element's URI, when the caller already has it,
     *                         so the exclusion check costs nothing.
     * @return int|null How many links were found, or null when the element was
     *                  skipped.
     * @throws Throwable If the reference rows cannot be rebuilt.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function extractElement(
        int $elementId,
        ?string $elementType,
        int $siteId,
        ?int $scanId = null,
        ?string $uri = null,
    ): ?int {
        $element = Craft::$app->getElements()->getElementById($elementId, $elementType, $siteId);

        if ($element === null) {
            return null;
        }

        if ($this->isUriExcluded($uri ?? $element->uri, $siteId)) {
            return null;
        }

        $store = LinkAudit::$plugin->getUrlStore();
        $resolver = LinkAudit::$plugin->getInternalResolver();
        $ignores = LinkAudit::$plugin->getIgnoreService();

        try {
            $links = LinkAudit::$plugin->getLinkExtractor()->extract($element);
        } catch (Throwable $e) {
            Craft::warning("Skipped element $elementId on site $siteId: {$e->getMessage()}", 'link-audit');

            return null;
        }

        // The element itself always gets an entry, even when nothing was found,
        // so that removing the last link from a page clears its references
        // rather than leaving the old ones standing.
        $refs = ["$elementId:$siteId" => []];

        foreach ($links as $link) {
            // One link that will not store is one link, not one element and not
            // one scan. A href nobody has ever seen before can carry anything at
            // all, and the batched job behind this dies on an uncaught database
            // error, taking the rest of the batch with it and dying again on the
            // same element on every rescan after that.
            try {
                $urlId = $store->upsert($link->url, $link->isInternal(), $link->siteId, $link->initialStatus());
                // Grouped under the site being extracted, never the link's own
                // site: a reference tag pinned to another site (`{entry:29@1:url}`
                // met while reading site 2) still sits on THIS site's page, and
                // filing it under site 1 would hand the whole of site 1's
                // reference set to a replace call carrying one row.
                $refs["$link->elementId:$siteId"][] = $link->toReference($urlId, $scanId);
                // An ignore rule is asked first, so a URL nobody wants checked
                // is settled as it is stored and never reaches the check phase
                // at all.
                $verdict = $ignores->verdictFor($link->url) ?? $resolver->resolve($link);

                if ($verdict !== null) {
                    $store->recordVerdict($urlId, $verdict);
                }
            } catch (Throwable $e) {
                Craft::warning(
                    "Skipped a link on element $elementId, site $siteId: {$e->getMessage()}",
                    'link-audit',
                );
            }
        }

        foreach ($refs as $key => $rows) {
            [$refElementId, $refSiteId] = explode(':', (string)$key);
            // Field rows only. What a template hard-codes onto this page is the
            // rendered crawl's to say, and reading the fields again says nothing
            // about it either way.
            $store->replaceReferencesFor(
                (int)$refElementId,
                (int)$refSiteId,
                $rows,
                [ExtractedLink::SOURCE_FIELD],
            );
        }

        return count($links);
    }

    /**
     * Reads every navigation node on the given sites.
     *
     * Its own phase because nothing in the field walker ever sees a navigation:
     * the URL lives on the node, not in anybody's content, which is exactly why
     * navigations are such a reliable source of links nobody has checked in
     * years.
     *
     * @param int[] $siteIds The sites to read.
     * @param int|null $scanId The scan to stamp the reference rows with.
     * @return int How many links were found.
     * @throws Throwable If the reference rows cannot be rebuilt.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function extractNavigation(array $siteIds, ?int $scanId = null): int
    {
        if (!$this->_settings()->scanNavigationNodes) {
            return 0;
        }

        $store = LinkAudit::$plugin->getUrlStore();
        $resolver = LinkAudit::$plugin->getInternalResolver();
        $ignores = LinkAudit::$plugin->getIgnoreService();
        $extractor = LinkAudit::$plugin->getLinkExtractor();
        $found = 0;

        foreach ($siteIds as $siteId) {
            $refs = [];

            foreach ($extractor->extractNavigationNodes($siteId) as $link) {
                // One node that will not store is one node: see the same guard
                // in {@see self::extractElement()}.
                try {
                    $urlId = $store->upsert(
                        $link->url,
                        $link->isInternal(),
                        $link->siteId,
                        $link->initialStatus(),
                    );
                    // Grouped under the site being read, never the link's own
                    // site, for the same reason as {@see self::extractElement()}:
                    // a node link pinned at another site must not carry this
                    // replace call over to that site's rows.
                    $refs["$link->elementId:$siteId"][] = $link->toReference($urlId, $scanId);
                    $verdict = $ignores->verdictFor($link->url) ?? $resolver->resolve($link);

                    if ($verdict !== null) {
                        $store->recordVerdict($urlId, $verdict);
                    }
                } catch (Throwable $e) {
                    Craft::warning(
                        "Skipped a navigation link on site $siteId: {$e->getMessage()}",
                        'link-audit',
                    );

                    continue;
                }

                $found++;
            }

            foreach ($refs as $key => $rows) {
                [$elementId, $nodeSiteId] = explode(':', (string)$key);
                $store->replaceReferencesFor(
                    (int)$elementId,
                    (int)$nodeSiteId,
                    $rows,
                    [ExtractedLink::SOURCE_NAV],
                );
            }
        }

        return $found;
    }

    /**
     * Closes a scan out: recount, tidy up, and mark it done.
     *
     * The pruning is the part worth being careful about. Reference rows are
     * rebuilt per element as each one is read, so a link an author deleted is
     * already gone by the time this runs. What is left is rows belonging to
     * elements this run never visited, and only a full run is entitled to
     * conclude that those are stale.
     *
     * @param int $scanId The scan to close.
     * @return void
     * @throws Throwable If the tidy up cannot be completed.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function finalise(int $scanId): void
    {
        $scan = $this->getScan($scanId);

        if ($scan === null) {
            Craft::warning("Tried to finish scan $scanId, which is gone.", 'link-audit');

            return;
        }

        $mode = ScanMode::tryFrom((string)$scan['mode']) ?? ScanMode::Full;
        $siteId = $scan['siteId'] !== null ? (int)$scan['siteId'] : null;

        if ($mode->prunesStaleReferences()) {
            $this->pruneStaleReferences($scanId, $siteId);
            $this->_pruneRenderedReferences();
        }

        if ($this->_settings()->pruneOrphanUrls) {
            $this->pruneOrphanUrls();
        }

        Db::update(ScanRecord::tableName(), [
            'status' => ScanStatus::Complete->value,
            'urlsTotal' => $this->_scannedUrlCount($scanId),
            // Recounted rather than left as whatever the check phase added up,
            // so the finished number is what the site is still carrying rather
            // than what this run happened to look at.
            'urlsBroken' => $this->_referencedBrokenCount($siteId),
            'dateFinished' => Db::prepareDateForDb(DateTimeHelper::now()),
        ], ['id' => $scanId]);

        // Pruning changes what the badges count without a single verdict being
        // recorded, and a scan finishing is exactly when somebody looks at them.
        LinkAudit::$plugin->getReportService()->invalidateCounts();

        $this->_notify($scan);
    }

    /**
     * One scan row.
     *
     * @param int $scanId The scan to read.
     * @return array<string, mixed>|null The row, or null when there is no such
     *                                   scan.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getScan(int $scanId): ?array
    {
        $row = (new Query())
            ->from([ScanRecord::tableName()])
            ->where(['id' => $scanId])
            ->one();

        return is_array($row) ? $row : null;
    }

    /**
     * Whether a URI is excluded from scanning by the settings.
     *
     * Each row is a regular expression tested against the URI, optionally
     * scoped to one site; an empty pattern matches the homepage. The single gate
     * every extraction path consults, so an element excluded here is skipped by
     * a full scan, an incremental one and the on-save hook alike.
     *
     * @param string|null $uri The element's URI. Null or the homepage token both
     *                         mean the homepage.
     * @param int $siteId The site the URI belongs to.
     * @return bool Whether the element is excluded.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function isUriExcluded(?string $uri, int $siteId): bool
    {
        $patterns = $this->_settings()->excludedUriPatterns;

        if ($patterns === []) {
            return false;
        }

        // Normalise to what the patterns are written against: no leading slash,
        // and the homepage as an empty string, so matching the homepage is an
        // empty pattern.
        $subject = ($uri === null || $uri === Element::HOMEPAGE_URI) ? '' : ltrim($uri, '/');

        foreach ($patterns as $row) {
            if (!(bool)($row['enabled'] ?? true)) {
                continue;
            }

            $rowSiteId = $row['siteId'] ?? '';

            if ($rowSiteId !== '' && $rowSiteId !== null && (int)$rowSiteId !== $siteId) {
                continue;
            }

            $pattern = trim((string)($row['uriPattern'] ?? ''));

            if ($pattern === '') {
                if ($subject === '') {
                    return true;
                }

                continue;
            }

            if ($this->_matchesPattern($pattern, $subject)) {
                return true;
            }
        }

        return false;
    }

    /**
     * When the last run that covered the whole site started.
     *
     * The reference point for an incremental scan, and it is the start rather
     * than the finish on purpose: an element edited while the last scan was
     * running may well have been read before the edit landed, so counting from
     * the finish would miss it.
     *
     * @return DateTimeInterface|null The moment, or null when nothing has
     *                                completed yet.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function lastCompletedScanStart(): ?DateTimeInterface
    {
        $started = (new Query())
            ->select(['dateStarted'])
            ->from([ScanRecord::tableName()])
            ->where([
                'status' => ScanStatus::Complete->value,
                'mode' => [ScanMode::Full->value, ScanMode::Incremental->value],
            ])
            ->andWhere(['not', ['dateStarted' => null]])
            ->orderBy(['dateStarted' => SORT_DESC])
            ->scalar();

        if ($started === false || $started === null) {
            return null;
        }

        // Stored dates are UTC, so they are read back as UTC: assuming the
        // system time zone here would shift the cut-off by the server's offset
        // and quietly rescan, or miss, an hour's worth of edits.
        $date = DateTimeHelper::toDateTime((string)$started);

        return $date !== false ? $date : null;
    }

    /**
     * Moves a scan on to its next phase.
     *
     * @param int $scanId The scan to move.
     * @param ScanStatus $status The phase it is entering.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function markStatus(int $scanId, ScanStatus $status): void
    {
        Db::update(ScanRecord::tableName(), ['status' => $status->value], ['id' => $scanId]);

        if ($status === ScanStatus::Queued) {
            return;
        }

        // A queued scan has not started, however long it has been waiting, so
        // the clock only starts when a worker actually picks the work up. The
        // null condition is what keeps a later phase from resetting it.
        Db::update(ScanRecord::tableName(), [
            'dateStarted' => Db::prepareDateForDb(DateTimeHelper::now()),
        ], ['id' => $scanId, 'dateStarted' => null]);
    }

    /**
     * Deletes URL rows nothing points at any more.
     *
     * Rows first seen in the last hour are left where they are, however
     * unreferenced they look. Extraction stores a URL and writes the reference
     * rows pointing at it a moment later, so there is a window in which a
     * perfectly good row has nothing pointing at it yet. A scan finishing during
     * another one's window would delete it out from under the insert, and the
     * foreign key error that follows takes the extracting job down with it. An
     * hour is far longer than that window and far shorter than the gap between
     * scans, so a genuine orphan waits at most until the next run.
     *
     * @return int How many rows went.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function pruneOrphanUrls(): int
    {
        $referenced = (new Query())
            ->select(['urlId'])
            ->from([ReferenceRecord::tableName()]);

        $cutOff = DateTimeHelper::now()->modify('-' . self::_ORPHAN_GRACE_MINUTES . ' minutes');

        return Craft::$app->getDb()->createCommand()
            ->delete(UrlRecord::tableName(), [
                'and',
                ['not', ['id' => $referenced]],
                ['<', 'dateFirstSeen', Db::prepareDateForDb($cutOff)],
            ])
            ->execute();
    }

    /**
     * Deletes reference rows this run did not touch.
     *
     * Only ever called for a full run: anything else has looked at a fraction of
     * the content, and would be pruning rows it simply never asked about.
     *
     * Rendered rows are left alone, whatever scan stamped them. A crawl is
     * capped, so a full run of a five thousand page site has looked at five
     * hundred of them and knows nothing at all about the rest; treating page
     * five hundred and one's findings as stale because this run never reached it
     * would empty the report a page at a time. Every page the crawl did reach
     * had its rows rebuilt as it went, so there is nothing of theirs left to
     * prune. {@see self::_pruneRenderedReferences()} is what clears them when
     * the crawl is switched off altogether.
     *
     * @param int $scanId The run that has just finished.
     * @param int|null $siteId The site it covered, or null when it covered them
     *                         all.
     * @return int How many rows went.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function pruneStaleReferences(int $scanId, ?int $siteId = null): int
    {
        $condition = [
            'and',
            ['or', ['scanId' => null], ['<', 'scanId', $scanId]],
            ['not', ['source' => ExtractedLink::SOURCE_RENDERED]],
        ];

        if ($siteId !== null) {
            $condition[] = ['siteId' => $siteId];
        }

        return Craft::$app->getDb()->createCommand()
            ->delete(ReferenceRecord::tableName(), $condition)
            ->execute();
    }

    /**
     * Adds a chunk's results to a scan's counters.
     *
     * @param int $scanId The scan to count against.
     * @param int $checked How many URLs got a verdict.
     * @param int $broken How many of them turned out to be broken.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function recordCheckProgress(int $scanId, int $checked, int $broken): void
    {
        $this->_increment($scanId, ['urlsChecked' => $checked, 'urlsBroken' => $broken]);
    }

    /**
     * Adds a batch's elements to a scan's counter.
     *
     * @param int $scanId The scan to count against.
     * @param int $count How many elements were read.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function recordElementsScanned(int $scanId, int $count): void
    {
        $this->_increment($scanId, ['elementsScanned' => $count]);
    }

    /**
     * Adds a batch's pages to a scan's crawl counter.
     *
     * @param int $scanId The scan to count against.
     * @param int $count How many pages were fetched and read.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function recordPagesCrawled(int $scanId, int $count): void
    {
        $this->_increment($scanId, ['pagesCrawled' => $count]);
    }

    /**
     * Rereads one element on every site it lives on, without opening a scan.
     *
     * What the on-save job runs. The sites come from the scan query rather than
     * from a list handed in, so an element gets exactly the treatment a full
     * scan would give it: the same enabled, draft, nested and element type
     * conditions, decided in one place.
     *
     * A site the element is no longer readable on has its references cleared
     * rather than left standing. Disabling an entry for one site should take its
     * links off the report there and then, not at the next full scan, and an
     * empty rebuild is how {@see UrlStore::replaceReferencesFor()} says that.
     *
     * @param int $elementId The element to reread.
     * @param int|null $scanId The scan to stamp the reference rows with, when
     *                         this is part of one.
     * @return int How many links were found across every site.
     * @throws Throwable If the reference rows cannot be rebuilt.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function refreshElement(int $elementId, ?int $scanId = null): int
    {
        $siteIds = $this->siteIds();
        $rows = [];

        foreach ($this->elementQuery($siteIds, null, [$elementId])->all() as $row) {
            $rows[(int)$row['siteId']] = $row;
        }

        $found = 0;

        foreach ($siteIds as $siteId) {
            $row = $rows[$siteId] ?? null;

            if ($row === null) {
                // Every source, not just the fields: an element that is no
                // longer readable on this site has no page here either, so
                // whatever a crawl found on it goes with the rest.
                LinkAudit::$plugin->getUrlStore()->replaceReferencesFor($elementId, $siteId, []);

                continue;
            }

            $found += (int)$this->extractElement(
                elementId: $elementId,
                elementType: ((string)$row['elementType']) ?: null,
                siteId: $siteId,
                scanId: $scanId,
                uri: $row['uri'] !== null ? (string)$row['uri'] : null,
            );
        }

        return $found;
    }

    /**
     * Queues an incremental scan if one is due, for installs with no cron.
     *
     * Called from Craft's garbage collection, which is the only thing in Craft
     * that runs on a rough schedule without anybody setting one up. That makes
     * this a backstop rather than a schedule: garbage collection runs when it
     * runs, so an install that cares about the timing wants the cron entry on
     * the Schedule settings screen instead.
     *
     * Because it can be called often, the gates are as important as the scan.
     * The interval is measured from when the last run *started* rather than from
     * when it finished, so a scan that takes three hours cannot have a second
     * one queued on top of it, and a run that is still going stops another being
     * queued at all. A run that has not been touched in longer than the interval
     * is treated as abandoned, so a worker that died mid scan does not stop the
     * schedule for good.
     *
     * @return ScanRecord|null The scan queued, or null when none was due.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function runScheduledScan(): ?ScanRecord
    {
        $settings = $this->_settings();

        if (!$settings->scheduledScanEnabled) {
            return null;
        }

        $hours = max(1, $settings->scheduledScanIntervalHours);
        $cutOff = DateTimeHelper::now()->modify("-$hours hours");

        if ($this->_scanInProgress($cutOff)) {
            return null;
        }

        $last = $this->_lastScanStart();

        if ($last !== null && $last > $cutOff) {
            return null;
        }

        try {
            $scan = $this->startScan(ScanMode::Incremental);
        } catch (ScanInProgressException $e) {
            // The gate above uses a window at least as wide as the one inside
            // startScan(), so this should not be reachable. It is caught anyway:
            // the schedule runs inside garbage collection, and throwing into
            // that would take a lot more than a scan down with it.
            Craft::warning($e->getMessage(), 'link-audit');

            return null;
        }

        Craft::info("Queued scan $scan->id: nothing has run in $hours hours.", 'link-audit');

        return $scan;
    }

    /**
     * Reads one element and closes the scan out in the same breath.
     *
     * Synchronous by design: this is what a console command and the on-save hook
     * want, and one element is not worth three queue jobs.
     *
     * @param int $elementId The element to read.
     * @param int|null $siteId The site to read it on, or null for every site the
     *                         element lives on.
     * @return int How many links were found.
     * @throws Throwable If the reference rows cannot be rebuilt.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function scanElement(int $elementId, ?int $siteId = null): int
    {
        $scan = $this->_createScan(ScanMode::Single, $siteId);
        $scanId = (int)$scan->id;
        $this->markStatus($scanId, ScanStatus::Extracting);

        $found = 0;
        $scanned = 0;

        foreach ($this->siteIds($siteId) as $id) {
            $links = $this->extractElement($elementId, null, $id, $scanId);

            if ($links === null) {
                continue;
            }

            $found += $links;
            $scanned++;
        }

        $this->recordElementsScanned($scanId, $scanned);
        $this->finalise($scanId);

        return $found;
    }

    /**
     * The sites a scan covers.
     *
     * @param int|null $siteId One site, or null for every site.
     * @return int[] The site ids.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function siteIds(?int $siteId = null): array
    {
        if ($siteId !== null) {
            return [$siteId];
        }

        return array_map(
            static fn($site): int => (int)$site->id,
            Craft::$app->getSites()->getAllSites(),
        );
    }

    /**
     * Opens a scan and pushes the first job of its chain.
     *
     * A run that reads content is refused while another one is going. Two of
     * them at once rebuild the same reference rows, prune each other's findings
     * and take rows out of the pending set the other is paging through, and the
     * report that comes out the far side is nobody's. Check only and single
     * element runs are narrow enough to be harmless and are always allowed:
     * they are what the recheck buttons push.
     *
     * @param ScanMode $mode What the run is for.
     * @param int|null $siteId The site to cover, or null for every site.
     * @return ScanRecord The scan row.
     * @throws ScanInProgressException If a run that reads content was asked for
     *                                 while one is already going.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function startScan(ScanMode $mode, ?int $siteId = null): ScanRecord
    {
        if ($mode === ScanMode::Full || $mode === ScanMode::Incremental) {
            $running = $this->_runningScanId(
                DateTimeHelper::now()->modify('-' . self::_ABANDONED_AFTER_MINUTES . ' minutes'),
            );

            if ($running !== null) {
                throw new ScanInProgressException($running);
            }
        }

        $scan = $this->_createScan($mode, $siteId);
        $scanId = (int)$scan->id;

        if ($mode === ScanMode::CheckOnly) {
            QueueHelper::push(new CheckUrls(['scanId' => $scanId]));

            return $scan;
        }

        $since = $mode === ScanMode::Incremental ? $this->lastCompletedScanStart() : null;

        QueueHelper::push(new ExtractLinks([
            'scanId' => $scanId,
            'siteIds' => $this->siteIds($siteId),
            'since' => $since?->format(DATE_ATOM),
        ]));

        return $scan;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Inserts a scan row.
     *
     * @param ScanMode $mode What the run is for.
     * @param int|null $siteId The site it covers.
     * @return ScanRecord The saved row.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _createScan(ScanMode $mode, ?int $siteId): ScanRecord
    {
        $scan = new ScanRecord([
            'siteId' => $siteId,
            'mode' => $mode->value,
            'status' => ScanStatus::Queued->value,
        ]);
        $scan->save(false);

        return $scan;
    }

    /**
     * Adds to a scan's counters without reading them first.
     *
     * Several workers can be part way through the same scan, so the counters are
     * moved with an expression rather than a read followed by a write, which
     * would lose whatever the other worker did in between.
     *
     * @param int $scanId The scan to move.
     * @param array<string, int> $columns The columns to add to, and by how much.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _increment(int $scanId, array $columns): void
    {
        $values = [];

        foreach ($columns as $column => $by) {
            if ($by === 0) {
                continue;
            }

            $values[$column] = new Expression("[[$column]] + " . (int)$by);
        }

        if ($values === []) {
            return;
        }

        $values['dateUpdated'] = Db::prepareDateForDb(DateTimeHelper::now());

        Craft::$app->getDb()->createCommand()
            ->update(ScanRecord::tableName(), $values, ['id' => $scanId])
            ->execute();
    }

    /**
     * When the most recent run that reads content began, whatever became of it.
     *
     * Not the same question as {@see self::lastCompletedScanStart()}, which is
     * the incremental cut-off and only counts runs that finished. This one is
     * the schedule's clock, so a run that failed or is still going counts: it
     * still means work was started, and starting another on top of it would be
     * the stampede this is here to prevent.
     *
     * Check only runs are left out. They read no content, so they say nothing
     * about when the content was last looked at.
     *
     * @return DateTimeInterface|null The moment, or null when nothing has ever
     *                                run.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _lastScanStart(): ?DateTimeInterface
    {
        $row = (new Query())
            ->select(['dateStarted', 'dateCreated'])
            ->from([ScanRecord::tableName()])
            ->where(['mode' => [ScanMode::Full->value, ScanMode::Incremental->value]])
            ->orderBy(['id' => SORT_DESC])
            ->limit(1)
            ->one();

        if (!is_array($row)) {
            return null;
        }

        // A scan waiting in the queue has no start date yet, and the moment it
        // was asked for is the honest answer for scheduling purposes.
        $started = $row['dateStarted'] ?? $row['dateCreated'];
        $date = DateTimeHelper::toDateTime((string)$started);

        return $date !== false ? $date : null;
    }

    /**
     * Tests a URI against one pattern as a regular expression.
     *
     * The tilde delimiter means slashes in the pattern need no escaping; a
     * literal tilde is escaped instead. A malformed pattern makes preg_match
     * return false rather than 1, so it simply does not match, and the scoped
     * error handler swallows the warning without reaching for the @ operator.
     *
     * @param string $pattern The pattern from the settings.
     * @param string $subject The normalised URI.
     * @return bool Whether it matches.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _matchesPattern(string $pattern, string $subject): bool
    {
        $delimited = '~' . str_replace('~', '\~', $pattern) . '~';
        set_error_handler(static fn(): bool => true);

        try {
            return preg_match($delimited, $subject) === 1;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Clears everything the rendered crawl ever found, once it has been switched
     * off.
     *
     * Turning the crawl off has to take its findings off the report, or an
     * install that tried it and thought better of it would be left carrying a
     * list of template links that nothing will ever check or refresh again.
     * Nothing happens at all while the crawl is on, which is the usual case.
     *
     * @return int How many rows went.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _pruneRenderedReferences(): int
    {
        if ($this->_settings()->renderedCrawlEnabled) {
            return 0;
        }

        return Craft::$app->getDb()->createCommand()
            ->delete(ReferenceRecord::tableName(), ['source' => ExtractedLink::SOURCE_RENDERED])
            ->execute();
    }

    /**
     * Reports a finished scan to whoever asked to hear about it.
     *
     * Last thing, once the scan is closed and its totals are final, so what goes
     * out matches what the screen says. Nothing here is allowed to fail a scan:
     * the notification service swallows and logs its own delivery problems, and
     * anything else it might throw is caught for the same reason.
     *
     * @param array<string, mixed> $scan The scan that just finished.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _notify(array $scan): void
    {
        Craft::info("Scan {$scan['id']} finished.", 'link-audit');

        try {
            LinkAudit::$plugin->getNotificationService()->notifyScanComplete($scan);
        } catch (Throwable $e) {
            Craft::error("Could not report scan {$scan['id']}: {$e->getMessage()}", 'link-audit');
        }
    }

    /**
     * How many broken URLs something still points at.
     *
     * Orphans are left out: a URL nothing links to any more is not a broken link
     * on anybody's page, whether or not it has been pruned yet.
     *
     * Scoped to the scan's own site when it had one, which is the number the
     * scan is entitled to claim. Counting the whole installation would have a
     * single site's run reporting every other site's broken links as its own,
     * with nothing on the finished scan to say so.
     *
     * @param int|null $siteId The site the scan covered, or null for a run that
     *                         covered every site.
     * @return int The count.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _referencedBrokenCount(?int $siteId): int
    {
        $referenced = (new Query())
            ->select(['urlId'])
            ->from([ReferenceRecord::tableName()]);

        if ($siteId !== null) {
            $referenced->where(['siteId' => $siteId]);
        }

        return (int)(new Query())
            ->from([UrlRecord::tableName()])
            ->where(['status' => UrlStatus::Broken->value])
            ->andWhere(['id' => $referenced])
            ->count();
    }

    /**
     * Answers a stored internal URL row out of the database.
     *
     * An element link is stored under a stand-in `element:<id>` URL, and a
     * relation under `relation:<id>`, when its target has no URL of its own, so
     * the row is taken apart rather than fed through the URL resolver, which
     * would make nothing of it. The two stand-in schemes are kept apart on
     * purpose: a relation and a Link field pointing at the same URL-less element
     * are answered by different rules, and this is the branch that has to keep
     * asking each one its own question every time a row's verdict goes stale and
     * comes back through here for a recheck.
     *
     * Which scheme a row carries is read off the URL itself, via
     * {@see ExtractedLink::standInScheme()}, rather than off the row's own
     * `scheme` column: that column is written by a URL parser that is not being
     * asked a question it can safely answer here, see that method for why, so the
     * column may hold nothing usable on a stand-in row. Reading the URL means any
     * such row resolves correctly on its next recheck, with no backfill needed.
     *
     * @param array<string, mixed> $row The URL row.
     * @return Verdict|null The verdict, or null when the row is a file-shaped
     *                      internal URL that belongs to the HTTP check phase
     *                      rather than a database lookup.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _resolveStored(array $row): ?Verdict
    {
        $resolver = LinkAudit::$plugin->getInternalResolver();
        $url = (string)$row['url'];
        $siteId = isset($row['siteId'])
            ? (int)$row['siteId']
            : Craft::$app->getSites()->getPrimarySite()->id;
        $standInScheme = ExtractedLink::standInScheme($url);

        if ($standInScheme === ExtractedLink::RELATION_SYNTHETIC_SCHEME) {
            $elementId = (int)substr($url, strlen(ExtractedLink::RELATION_SYNTHETIC_SCHEME) + 1);

            return $resolver->resolveElement($elementId, null, $siteId, isRelation: true);
        }

        if ($standInScheme === ExtractedLink::SYNTHETIC_SCHEME) {
            $elementId = (int)substr($url, strlen(ExtractedLink::SYNTHETIC_SCHEME) + 1);

            return $resolver->resolveElement($elementId, null, $siteId);
        }

        return $resolver->resolveUrl($url, $siteId);
    }

    /**
     * The most recent run that is still going, if there is one.
     *
     * Anything queued, extracting or checking counts, of any mode: they all mean
     * the queue already has this plugin's work in it.
     *
     * A run that has not been touched since the cut-off does not count. Every
     * phase writes to the row as it goes, so a row that has gone quiet for
     * longer than the cut-off belongs to a worker that died, and one of those
     * should not stop the schedule, or lock the buttons, for ever.
     *
     * @param DateTimeInterface $cutOff The moment before which a running scan is
     *                                  treated as abandoned.
     * @return int|null The scan id, or null when nothing is running.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _runningScanId(DateTimeInterface $cutOff): ?int
    {
        $id = (new Query())
            ->select(['id'])
            ->from([ScanRecord::tableName()])
            ->where([
                'status' => [
                    ScanStatus::Queued->value,
                    ScanStatus::Extracting->value,
                    ScanStatus::Checking->value,
                ],
            ])
            ->andWhere(['>', 'dateUpdated', Db::prepareDateForDb($cutOff)])
            ->orderBy(['id' => SORT_DESC])
            ->scalar();

        return $id !== false && $id !== null ? (int)$id : null;
    }

    /**
     * Whether a run is still going.
     *
     * @param DateTimeInterface $cutOff The moment before which a running scan is
     *                                  treated as abandoned.
     * @return bool Whether something is already running.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _scanInProgress(DateTimeInterface $cutOff): bool
    {
        return $this->_runningScanId($cutOff) !== null;
    }

    /**
     * How many distinct URLs a scan recorded a reference to.
     *
     * @param int $scanId The scan.
     * @return int The count.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _scannedUrlCount(int $scanId): int
    {
        return (int)(new Query())
            ->select(['urlId'])
            ->distinct()
            ->from([ReferenceRecord::tableName()])
            ->where(['scanId' => $scanId])
            ->count('[[urlId]]');
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
}
