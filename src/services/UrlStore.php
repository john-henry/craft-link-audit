<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\services;

use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateInterval;
use DateTime;
use Exception;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\helpers\UrlNormaliser;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\ExtractedLink;
use johnhenry\linkaudit\models\Verdict;
use johnhenry\linkaudit\records\ReferenceRecord;
use johnhenry\linkaudit\records\UrlRecord;
use Throwable;
use yii\base\Component;
use yii\base\InvalidArgumentException;
use yii\db\IntegrityException;

/**
 * Owns the two tables the whole plugin hangs off: unique URLs and the places
 * they appear.
 *
 * The dedupe pillar lives here. A URL is stored once, keyed on the sha1 of its
 * normalised form, and every reference to it points at that one row. Ten
 * thousand entries sharing a footer link means one row, one verdict, and one
 * HTTP request.
 *
 * References are rebuilt wholesale per element per extraction rather than
 * upserted, so nothing durable may be kept on them. Verdict history lives on the
 * URL row and author decisions live in the ignores table.
 *
 * Dates go through `DateTimeHelper` throughout, since everything in here is a
 * row on its way in or out of the database.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class UrlStore extends Component
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var int The width of the URLs table's `host` column.
     */
    private const _MAX_HOST = 255;

    /**
     * @var int The width of the references table's `linkText` column.
     */
    private const _MAX_LINK_TEXT = 255;

    /**
     * @var int The shortest a deferred URL is ever put off for, used when the
     * host asked to be left alone without saying how long for.
     */
    private const _MIN_DEFER_SECONDS = 300;

    /**
     * @var int The width of the references table's `rawHref` column.
     */
    private const _MAX_RAW_HREF = 500;

    /**
     * @var int The width of the URLs table's `reason` column.
     */
    private const _MAX_REASON = 40;

    /**
     * @var int The width of the URLs table's `scheme` column.
     */
    private const _MAX_SCHEME = 32;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * The URLs a page already accounts for through its own content, so a
     * rendered crawl can leave them alone.
     *
     * The rendered crawl sees everything a visitor sees, which includes every
     * link the page's own fields put there. Recording those a second time would
     * double the row count and lose information doing it: a field reference
     * names the field an author has to open to fix the link, and a rendered one
     * can only say the page it came out on.
     *
     * Blocks count as the page's own content. A link inside a Matrix block is
     * recorded against the block, with the page as its owner, so a rendered
     * crawl of that page has to ask about both or it would file every block's
     * links again under the page.
     *
     * @param int $elementId The page being crawled.
     * @param int $siteId The site it was read on.
     * @return int[] The URL row ids already accounted for.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function attributedUrlIds(int $elementId, int $siteId): array
    {
        return array_map('intval', (new Query())
            ->select(['urlId'])
            ->distinct()
            ->from([ReferenceRecord::tableName()])
            ->where([
                'and',
                ['siteId' => $siteId],
                ['not', ['source' => ExtractedLink::SOURCE_RENDERED]],
                ['or', ['elementId' => $elementId], ['ownerElementId' => $elementId]],
            ])
            ->column());
    }

    /**
     * Notes that a URL was set aside rather than judged, and roughly when it is
     * worth offering again.
     *
     * Only ever written to a row that has never been checked. A URL that already
     * carries a verdict has a schedule that verdict earned, and a host having a
     * busy afternoon is no reason to throw that away.
     *
     * Two things keep the URL from being asked again straight away. Inside the
     * run, the check job pages forward through the ids it has already offered,
     * so a deferred URL is simply passed over. Across runs, it is this date:
     * {@see self::pendingQuery()} leaves a pending row alone until its date has
     * come round.
     *
     * @param int $urlId The URL row.
     * @param int|null $retryAfterSeconds How long the host asked to be left for,
     *                                    when it said.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function defer(int $urlId, ?int $retryAfterSeconds = null): void
    {
        $seconds = max(self::_MIN_DEFER_SECONDS, (int)$retryAfterSeconds);

        Db::update(UrlRecord::tableName(), [
            'nextCheckAfter' => Db::prepareDateForDb(
                DateTimeHelper::now()->modify("+$seconds seconds"),
            ),
        ], ['id' => $urlId, 'status' => UrlStatus::Pending->value]);
    }

    /**
     * Clears every reference recorded against an element, on every site.
     *
     * What happens when an element is deleted. The table's foreign key already
     * clears up after a hard delete, but a delete in Craft is a soft one: the
     * element row stays where it is with a deletion date on it, so nothing
     * cascades and the entry would go on carrying broken links on a report
     * nobody can open the page from.
     *
     * Rows are cleared by owner as well as by element, so the blocks inside a
     * deleted entry go with it rather than waiting for a full scan to notice
     * that the page they belonged to is gone.
     *
     * @param int $elementId The element that has gone.
     * @return int How many reference rows went.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function deleteReferencesForElement(int $elementId): int
    {
        $condition = ['or', ['elementId' => $elementId], ['ownerElementId' => $elementId]];

        $urlIds = array_map('intval', (new Query())
            ->select(['urlId'])
            ->distinct()
            ->from([ReferenceRecord::tableName()])
            ->where($condition)
            ->column());

        if ($urlIds === []) {
            return 0;
        }

        $deleted = Craft::$app->getDb()->createCommand()
            ->delete(ReferenceRecord::tableName(), $condition)
            ->execute();

        if (LinkAudit::$plugin->getSettings()->pruneOrphanUrls) {
            $this->_pruneUrls($urlIds);
        }

        LinkAudit::$plugin->getReportService()->invalidateCounts();

        return $deleted;
    }

    /**
     * The query behind the check phase: every URL that has never been checked,
     * plus every one whose verdict has gone stale.
     *
     * That one condition is what keeps a rescan cheap, so it is a query rather
     * than a result: the batched job pages through it, and the console commands
     * count it.
     *
     * Ignored and unsafe rows are left out. Neither is rechecked on a timer:
     * an ignore is an author's decision, and an unsafe URL was refused before a
     * request was ever made.
     *
     * A pending row carrying a next check date is left out until that date has
     * passed, which is what makes {@see self::defer()} mean anything. Without
     * that, a URL a host asked to be left alone would be offered again on the
     * very next pass, and fifty thousand rate limited URLs would come round again
     * on every check phase for ever.
     *
     * @return Query The query, ordered by id so paging cannot skip a row.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function pendingQuery(): Query
    {
        return (new Query())
            ->select([
                'id',
                'url',
                'urlHash',
                'host',
                'scheme',
                'siteId',
                'isInternal',
                'status',
                'failCount',
            ])
            ->from([UrlRecord::tableName()])
            ->where([
                'and',
                ['not', ['status' => [UrlStatus::Ignored->value, UrlStatus::Unsafe->value]]],
                [
                    'or',
                    ['and', ['status' => UrlStatus::Pending->value], ['nextCheckAfter' => null]],
                    ['<=', 'nextCheckAfter', Db::prepareDateForDb(DateTimeHelper::now())],
                ],
            ])
            ->orderBy(['id' => SORT_ASC]);
    }

    /**
     * Writes the result of a check to a URL row, and works out when it is worth
     * asking again.
     *
     * The fail count is the reason a URL that times out once is not reported as
     * broken: it counts consecutive failures, so the checker can hold an
     * unreachable URL until it has failed enough times to mean it. Once it has,
     * the row is written as broken rather than unreachable, which is what puts
     * it on the broken list and brings it back for a check the next day.
     *
     * A deferral is not a verdict and is ignored here: the scheduler hands one
     * back when a host asked to be left alone, and writing it would throw away a
     * perfectly good answer from last week in exchange for nothing.
     *
     * @param int $urlId The URL row to write to.
     * @param Verdict $verdict What the checker found.
     * @return void
     * @throws Exception If a time to live setting cannot be turned into an
     *                   interval.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function recordVerdict(int $urlId, Verdict $verdict): void
    {
        if ($verdict->isDeferred()) {
            return;
        }

        $row = (new Query())
            ->select(['failCount', 'status'])
            ->from([UrlRecord::tableName()])
            ->where(['id' => $urlId])
            ->one();

        if ($row === null) {
            Craft::warning("Tried to record a verdict for URL $urlId, which is gone.", 'link-audit');

            return;
        }

        // Nothing is ever written over an ignored row. That is the whole point of
        // the ignores table: extraction resolves internal links as it stores
        // them and the check phase writes whatever came back, so without this one
        // condition a rescan would overwrite an author's decision and put the URL
        // back in the lists.
        //
        // It also keeps the history. A URL ignored while it was broken holds the
        // 404, the message and the date it went, which is exactly what somebody
        // reviewing the decision months later wants to see; rewriting the row
        // with an empty ignored verdict on every scan would rub all of that out.
        if ((string)$row['status'] === UrlStatus::Ignored->value) {
            return;
        }

        $now = DateTimeHelper::now();
        $checkedAt = Db::prepareDateForDb($now);
        $failCount = $this->_nextFailCount($verdict->status, (int)$row['failCount']);
        // The recorded status, which is not always the verdict's: a URL that has
        // failed often enough stops being "no answer today" and becomes broken.
        $status = $this->_promotedStatus($verdict, $failCount);
        $nextCheckAfter = $this->_nextCheckAfter($status, $now);

        $columns = [
            'status' => $status->value,
            'httpStatus' => $verdict->httpStatus,
            'method' => $verdict->method,
            'finalUrl' => $verdict->finalUrl,
            'redirectCount' => $verdict->redirectCount,
            'redirectPermanent' => $verdict->redirectPermanent,
            'redirectStatus' => $verdict->redirectStatus,
            'reason' => $this->_truncate($verdict->reason, self::_MAX_REASON),
            'message' => $verdict->message,
            'responseTimeMs' => $verdict->responseTimeMs,
            'failCount' => $failCount,
            'dateLastChecked' => $checkedAt,
            'nextCheckAfter' => $nextCheckAfter !== null ? Db::prepareDateForDb($nextCheckAfter) : null,
        ];

        // A redirect that lands on a 2xx is a URL that answered, so it counts as
        // the last time this link was known to work.
        if ($status === UrlStatus::Ok || $status === UrlStatus::Redirect) {
            $columns['dateLastOk'] = $checkedAt;
        }

        // Stamped only when the URL crosses into broken, not on every recheck
        // that finds it still broken. The date then means when it broke, which
        // is what the "new broken links" notification counts from: restamping
        // it on a re-confirmation made a rescan or a page recheck report the
        // same standing failures as though they had just appeared.
        if ($status === UrlStatus::Broken && (string)$row['status'] !== UrlStatus::Broken->value) {
            $columns['dateLastBroken'] = $checkedAt;
        }

        Db::update(UrlRecord::tableName(), $columns, ['id' => $urlId]);

        LinkAudit::$plugin->getReportService()->invalidateCounts();
    }

    /**
     * Replaces the references recorded against an element on a site.
     *
     * Delete then insert, in one transaction, because an extraction knows the
     * whole truth about that element: a link the author removed has to go, and
     * an occurrence hash to upsert against would only be a slower way of saying
     * the same thing.
     *
     * Scoped to the sources the caller is actually authoritative for. A field
     * extraction knows everything about what an element's fields hold and
     * nothing about what its template hard-codes, so without the scope a reread
     * on save would quietly delete every finding the last rendered crawl made.
     * Pass no scope only when the element is genuinely being cleared.
     *
     * @param int $elementId The element the links were found in.
     * @param int $siteId The site the element was read on.
     * @param array<int, array<string, mixed>> $refs The references to record.
     *                                               Each needs `urlId` and
     *                                               `elementType`; `ownerElementId`,
     *                                               `fieldUid`, `fieldHandle`,
     *                                               `source`, `linkText`,
     *                                               `rawHref` and `scanId` are
     *                                               optional.
     * @param string[]|null $sources The sources being replaced, from the
     *                               {@see \johnhenry\linkaudit\models\ExtractedLink}
     *                               SOURCE_* constants. Null replaces
     *                               everything the element holds on this site.
     * @return void
     * @throws InvalidArgumentException If a reference is missing `urlId` or
     *                                  `elementType`.
     * @throws Throwable If the transaction cannot be completed.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function replaceReferencesFor(
        int $elementId,
        int $siteId,
        array $refs,
        ?array $sources = null,
    ): void {
        $rows = [];

        foreach ($refs as $ref) {
            if (empty($ref['urlId']) || empty($ref['elementType'])) {
                throw new InvalidArgumentException(
                    'A link reference needs both a urlId and an elementType.',
                );
            }

            $rows[] = [
                (int)$ref['urlId'],
                $elementId,
                (string)$ref['elementType'],
                $siteId,
                isset($ref['ownerElementId']) ? (int)$ref['ownerElementId'] : null,
                isset($ref['fieldUid']) ? (string)$ref['fieldUid'] : null,
                isset($ref['fieldHandle']) ? (string)$ref['fieldHandle'] : null,
                isset($ref['source']) ? (string)$ref['source'] : 'field',
                $this->_truncate(
                    isset($ref['linkText']) ? (string)$ref['linkText'] : null,
                    self::_MAX_LINK_TEXT,
                ),
                $this->_truncate(
                    isset($ref['rawHref']) ? (string)$ref['rawHref'] : null,
                    self::_MAX_RAW_HREF,
                ),
                isset($ref['scanId']) ? (int)$ref['scanId'] : null,
            ];
        }

        $condition = ['elementId' => $elementId, 'siteId' => $siteId];

        if ($sources !== null) {
            $condition['source'] = $sources;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            Db::delete(ReferenceRecord::tableName(), $condition);

            if ($rows !== []) {
                Db::batchInsert(ReferenceRecord::tableName(), [
                    'urlId',
                    'elementId',
                    'elementType',
                    'siteId',
                    'ownerElementId',
                    'fieldUid',
                    'fieldHandle',
                    'source',
                    'linkText',
                    'rawHref',
                    'scanId',
                ], $rows);
            }

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        // A rebuild moves the site-scoped counts even when no verdict changes
        // hands: a broken URL gaining or losing its references on this site is
        // what puts it on or takes it off this site's badge.
        LinkAudit::$plugin->getReportService()->invalidateCounts();
    }

    /**
     * Returns the id of the row for a normalised URL, inserting it first if this
     * is the first time the URL has been seen.
     *
     * Nothing on an existing row is overwritten. The verdict on it was earned by
     * a check, and meeting the same URL in another entry says nothing about
     * whether it still works.
     *
     * @param string $normalisedUrl The URL, already through
     *                              {@see UrlNormaliser::normalise()}.
     * @param bool $isInternal Whether the URL belongs to one of this
     *                         installation's sites.
     * @param int|null $siteId The site an internal URL belongs to. Ignored for
     *                         external URLs, which are global: an HTTP verdict
     *                         does not change per site.
     * @param UrlStatus|null $status The status to give a new row. Defaults to
     *                               pending; pass ignored for a URL that is
     *                               recorded but never checked. Overridden to
     *                               ignored when an author has already ignored
     *                               this URL, so a decision outlives the row it
     *                               was made about.
     * @return int The URL row id.
     * @throws IntegrityException If the row cannot be inserted for a reason
     *                            other than another worker having got there
     *                            first.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function upsert(
        string $normalisedUrl,
        bool $isInternal,
        ?int $siteId = null,
        ?UrlStatus $status = null,
    ): int {
        $urlHash = UrlNormaliser::hash($normalisedUrl);
        $existingId = $this->_findIdByHash($urlHash);

        if ($existingId !== null) {
            return $existingId;
        }

        // A URL nothing points at any more is pruned when a scan finishes, so an
        // ignored URL that comes back into the content arrives here as a brand
        // new row. Its ignore row survived the pruning, and it has to survive the
        // reinsertion too, or a full rescan would undo the decision by the back
        // door.
        if ($status !== UrlStatus::Ignored && LinkAudit::$plugin->getIgnoreService()->isHashIgnored($urlHash)) {
            $status = UrlStatus::Ignored;
        }

        // A stand-in is not a URI, so it is never handed to the URL parser
        // behind hostOf()/schemeOf(): that parser reads a bare `scheme:12345` as
        // a host and a port whenever the digits fit in one, which is every
        // element id under 65536, and would leave most stand-in rows carrying an
        // empty scheme and a host of "element" or "relation". See
        // {@see ExtractedLink::standInScheme()}.
        $standInScheme = ExtractedLink::standInScheme($normalisedUrl);

        try {
            Db::insert(UrlRecord::tableName(), [
                'urlHash' => $urlHash,
                'url' => $normalisedUrl,
                // Clipped rather than trusted. A URL that is never checked is
                // still stored, raw href and all, so the author can see it was
                // met, and the host and scheme on one of those are whatever the
                // author typed. On a database in strict mode an over-long value
                // is an error, not a truncation, and one of those in the middle
                // of a batched extraction takes the whole scan with it.
                'host' => $standInScheme !== null
                    ? ''
                    : $this->_truncate(UrlNormaliser::hostOf($normalisedUrl), self::_MAX_HOST),
                'scheme' => $this->_truncate(
                    $standInScheme ?? UrlNormaliser::schemeOf($normalisedUrl),
                    self::_MAX_SCHEME,
                ),
                'siteId' => $isInternal ? $siteId : null,
                'isInternal' => $isInternal,
                'status' => ($status ?? UrlStatus::Pending)->value,
                'dateFirstSeen' => Db::prepareDateForDb(DateTimeHelper::now()),
            ]);
        } catch (IntegrityException $e) {
            // Another worker inserted the same URL between the lookup and the
            // insert. Its row is as good as the one this call meant to write.
            $racedId = $this->_findIdByHash($urlHash);

            if ($racedId === null) {
                throw $e;
            }

            return $racedId;
        }

        return (int)Craft::$app->getDb()->getLastInsertID(UrlRecord::tableName());
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * A whole number of days as an interval.
     *
     * @param int $days The number of days, floored at zero.
     * @return DateInterval The interval.
     * @throws Exception If the interval cannot be built.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _days(int $days): DateInterval
    {
        return new DateInterval(sprintf('P%dD', max(0, $days)));
    }

    /**
     * Looks a URL row up by its hash.
     *
     * @param string $urlHash The sha1 of the normalised URL.
     * @return int|null The row id, or null when the URL has not been seen.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _findIdByHash(string $urlHash): ?int
    {
        $id = (new Query())
            ->select(['id'])
            ->from([UrlRecord::tableName()])
            ->where(['urlHash' => $urlHash])
            ->scalar();

        return $id !== false && $id !== null ? (int)$id : null;
    }

    /**
     * A whole number of hours as an interval.
     *
     * @param int $hours The number of hours, floored at zero.
     * @return DateInterval The interval.
     * @throws Exception If the interval cannot be built.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _hours(int $hours): DateInterval
    {
        return new DateInterval(sprintf('PT%dH', max(0, $hours)));
    }

    /**
     * When a URL with the given verdict is worth checking again.
     *
     * Working links are trusted for weeks, broken ones are asked again the next
     * day so a fix shows up quickly, and a blocked one is left alone for a
     * fortnight because asking again will not change the answer. Ignored and
     * unsafe URLs are never scheduled at all.
     *
     * @param UrlStatus $status The verdict just recorded.
     * @param DateTime $from The moment the check happened.
     * @return DateTime|null When to check again, or null to never schedule it.
     * @throws Exception If the interval cannot be built.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _nextCheckAfter(UrlStatus $status, DateTime $from): ?DateTime
    {
        $settings = LinkAudit::$plugin->getSettings();

        $interval = match ($status) {
            UrlStatus::Ok => $this->_days($settings->okTtlDays),
            UrlStatus::Redirect => $this->_days($settings->redirectTtlDays),
            UrlStatus::Blocked => $this->_days($settings->blockedTtlDays),
            UrlStatus::Broken => $this->_hours($settings->brokenRecheckHours),
            UrlStatus::Unreachable => $this->_hours($settings->unreachableRecheckHours),
            UrlStatus::Pending, UrlStatus::Ignored, UrlStatus::Unsafe => null,
        };

        return $interval !== null ? (clone $from)->add($interval) : null;
    }

    /**
     * The fail count a URL carries after this verdict.
     *
     * Only consecutive failures count, so a URL that answers resets to zero and
     * has to earn its way back up before it is called broken.
     *
     * @param UrlStatus $status The verdict just recorded.
     * @param int $current The count before this check.
     * @return int The count after it.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _nextFailCount(UrlStatus $status, int $current): int
    {
        return match ($status) {
            UrlStatus::Unreachable => $current + 1,
            UrlStatus::Ok, UrlStatus::Redirect => 0,
            default => $current,
        };
    }

    /**
     * The status a verdict is written as, once the fail count has been taken
     * into account.
     *
     * Everything except an unreachable URL is written as it came in. An
     * unreachable one is held until it has failed enough consecutive times to
     * mean it, and is then written as broken so it reaches the list somebody
     * actually reads.
     *
     * A host that does not resolve gets a lower bar than a timeout or a 500. The
     * latter two are routinely a server having a bad few minutes; a domain that
     * has stopped resolving is usually a domain that is gone, and making the
     * author wait an extra day to hear about it helps nobody.
     *
     * @param Verdict $verdict The verdict just recorded.
     * @param int $failCount The consecutive failures after this one.
     * @return UrlStatus The status to write.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _promotedStatus(Verdict $verdict, int $failCount): UrlStatus
    {
        if ($verdict->status !== UrlStatus::Unreachable) {
            return $verdict->status;
        }

        $settings = LinkAudit::$plugin->getSettings();
        $threshold = $verdict->reason === Verdict::REASON_DNS
            ? $settings->brokenAfterDnsFailures
            : $settings->brokenAfterFailures;

        return $failCount >= max(1, $threshold) ? UrlStatus::Broken : UrlStatus::Unreachable;
    }

    /**
     * Deletes the URL rows in a given set that nothing points at any more.
     *
     * Scoped to a set rather than sweeping the table, which is what
     * {@see ScanService::pruneOrphanUrls()} is for: this runs whenever an
     * element is deleted, and an anti-join across every URL on the site is not
     * something to hang off one author pressing Delete.
     *
     * @param int[] $urlIds The URLs whose last reference may have just gone.
     * @return int How many rows went.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _pruneUrls(array $urlIds): int
    {
        $stillReferenced = array_map('intval', (new Query())
            ->select(['urlId'])
            ->distinct()
            ->from([ReferenceRecord::tableName()])
            ->where(['urlId' => $urlIds])
            ->column());

        $orphans = array_values(array_diff($urlIds, $stillReferenced));

        if ($orphans === []) {
            return 0;
        }

        return Craft::$app->getDb()->createCommand()
            ->delete(UrlRecord::tableName(), ['id' => $orphans])
            ->execute();
    }

    /**
     * Clips a value to the width of the column it is bound for.
     *
     * Anchor text and raw hrefs come from whatever an author pasted in, and a
     * value two characters too long should not take a whole extraction down with
     * it.
     *
     * @param string|null $value The value to clip.
     * @param int $length The column width.
     * @return string|null The clipped value.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _truncate(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $length);
    }
}
