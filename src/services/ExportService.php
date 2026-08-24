<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTimeInterface;
use Generator;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\Verdict;
use johnhenry\linkaudit\records\ReferenceRecord;
use johnhenry\linkaudit\records\UrlRecord;
use yii\base\Component;

/**
 * The list screens, as a file somebody can work off.
 *
 * A row here is a reference, not a URL, which is the whole point of it. The
 * report screens are built around the URL, because that is the unit a check is
 * made against: one address, asked once, however many pages carry it. A CSV is
 * built around the work instead. It goes to the person who has to open the
 * pages and put the right address in, and to them the same broken URL sitting in
 * three places is three jobs, so it is three rows, each naming its own page, its
 * own field and its own site.
 *
 * Nothing is read into memory whole. The rows come out of a generator, paged on
 * the reference id rather than on an offset, and the CSV is handed back in
 * chunks: one for the header and one per batch. An export of a large site is
 * then a few seconds and a few hundred rows of memory rather than the whole
 * table at once. The cursor discipline is {@see \johnhenry\linkaudit\queue\ChunkedUrlBatcher}'s,
 * for a milder version of the same reason: rows can leave the set underneath a
 * long read, and offset paging would quietly skip whatever slid down past the
 * offset.
 *
 * Dates go through `DateTimeHelper` rather than Carbon, as they do in
 * {@see ReportService}: nothing here is date arithmetic, it is a column on its
 * way out of the database.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class ExportService extends Component
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var int How many reference rows are read, and written out, at a time.
     *
     * Big enough that the per batch work (one count query, one pass of element
     * loads) is spread thin, small enough that a batch of rows and the elements
     * behind it are a bounded amount of memory whatever the size of the export.
     */
    public const BATCH_SIZE = 500;

    /**
     * @var string The byte order mark that goes on the front of the file.
     *
     * Excel reads a CSV as the machine's own legacy encoding unless it is told
     * otherwise, and the only thing it takes as being told is this. Without it a
     * host with an accent in it, or a page title in Irish, comes out as mojibake
     * for the one reader most likely to open the file.
     */
    private const _BOM = "\xEF\xBB\xBF";

    /**
     * @var string The characters a spreadsheet will treat as the start of a
     * formula rather than as text.
     *
     * Tab and carriage return are in there because Excel strips leading
     * whitespace before deciding, so a cell starting with a tab and then an
     * equals sign is a formula as far as it is concerned.
     */
    private const _FORMULA_LEADERS = "=+-@\t\r";

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * The whole export, a chunk at a time.
     *
     * The first chunk is the byte order mark and the header row; every chunk
     * after it is one batch of references. Yielded rather than returned so the
     * caller can write each chunk out and forget it: the web response streams
     * them straight to the browser, and the console command writes them to a
     * file.
     *
     * @param UrlStatus $status The verdict being exported.
     * @param int[] $siteIds The sites to read references on. The caller fences
     *                       this: nothing here checks who is asking.
     * @param array<string, mixed> $filters Any of `host`, `elementType`,
     *                                      `source`, `permanent` and `search`,
     *                                      exactly as the list screen carries
     *                                      them.
     * @return Generator<int, string> The file, in chunks.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function csv(UrlStatus $status, array $siteIds, array $filters = []): Generator
    {
        yield self::_BOM . $this->_chunk([$this->_header()]);

        if ($siteIds === []) {
            return;
        }

        $sourceLabels = LinkAudit::$plugin->getReportService()->sourceOptions();

        foreach ($this->_batches($status, $siteIds, $filters) as $batch) {
            $places = $this->_placeCounts($batch, $siteIds, $filters);
            $elements = $this->_elements($batch);

            yield $this->_chunk(array_map(
                fn(array $row): array => $this->_row($row, $elements, $places, $sourceLabels),
                $batch,
            ));
        }
    }

    /**
     * What the downloaded file is called.
     *
     * The verdict and the date, because the two questions somebody asks of a
     * file sitting in their downloads folder a fortnight later are which list it
     * came off and how old it is.
     *
     * @param UrlStatus $status The verdict being exported.
     * @return string The file name.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function filename(UrlStatus $status): string
    {
        return sprintf('link-audit-%s-%s.csv', $status->value, DateTimeHelper::now()->format('Y-m-d'));
    }

    /**
     * How many rows the file will hold, without writing any of them.
     *
     * For the console command, which has a terminal to answer to and so ought to
     * be able to say what it wrote rather than just that it wrote something. One
     * count against the query the file itself comes out of, so the number cannot
     * disagree with what lands on disk.
     *
     * @param UrlStatus $status The verdict being exported.
     * @param int[] $siteIds The sites to read references on.
     * @param array<string, mixed> $filters Any of `host`, `elementType`,
     *                                      `source`, `permanent` and `search`.
     * @return int The number of reference rows.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function rowCount(UrlStatus $status, array $siteIds, array $filters = []): int
    {
        if ($siteIds === []) {
            return 0;
        }

        return (int)$this->_query($status, $siteIds, $filters)->count();
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Adds the reference side filters to a query.
     *
     * `elementType` and `source` are properties of a reference rather than of a
     * URL, so they land on both the read and the count of places, and they have
     * to land on both the same way or the count would promise places the file
     * does not list.
     *
     * @param Query $query The query, filtered in place.
     * @param array<string, mixed> $filters The filters from the request.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _addReferenceFilters(Query $query, array $filters): void
    {
        $elementType = trim((string)($filters['elementType'] ?? ''));

        if ($elementType !== '') {
            $query->andWhere(Db::parseParam('r.elementType', $elementType));
        }

        $source = trim((string)($filters['source'] ?? ''));

        if ($source !== '') {
            $query->andWhere(Db::parseParam('r.source', $source));
        }
    }

    /**
     * The reference rows, a batch at a time.
     *
     * Paged on the reference id rather than on an offset. An export of a big
     * site is a long read, and content saved while it runs rewrites reference
     * rows underneath it; with an offset, rows leaving the set behind the reader
     * pull unseen rows down into positions it has already passed, and those rows
     * never appear in the file. Asking each time for the rows above the highest
     * id already handed out cannot go wrong that way.
     *
     * @param UrlStatus $status The verdict being exported.
     * @param int[] $siteIds The sites to read references on.
     * @param array<string, mixed> $filters The filters from the request.
     * @return Generator<int, array<int, array<string, mixed>>> The batches.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _batches(UrlStatus $status, array $siteIds, array $filters): Generator
    {
        $query = $this->_query($status, $siteIds, $filters);
        $cursor = 0;

        while (true) {
            /** @var array<int, array<string, mixed>> $rows */
            $rows = (clone $query)
                ->andWhere(['>', 'r.id', $cursor])
                ->limit(self::BATCH_SIZE)
                ->all();

            if ($rows === []) {
                return;
            }

            $cursor = max(array_map(static fn(array $row): int => (int)$row['id'], $rows));

            yield $rows;
        }
    }

    /**
     * One cell, safe to open in a spreadsheet.
     *
     * Everything on a row here came out of somebody's content: a URL somebody
     * pasted in, the words they wrapped around it, the title they gave the page.
     * A cell starting with an equals sign is a formula to Excel, LibreOffice and
     * Sheets alike, and a formula in a file an editor was handed to fix links is
     * a way into their machine. A leading apostrophe is the spreadsheets' own
     * answer for "this is text": it is not shown in the cell, and it survives the
     * round trip back out.
     *
     * @param mixed $value The value for the cell.
     * @return string The cell.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $text = (string)$value;

        if ($text === '' || !str_contains(self::_FORMULA_LEADERS, $text[0])) {
            return $text;
        }

        return "'" . $text;
    }

    /**
     * A batch of rows as a piece of CSV text.
     *
     * `fputcsv` rather than a join, because it is the only thing that gets the
     * quoting right without being asked twice: a URL with a comma in its query
     * string, a link text with a quotation mark in it, a page title with a line
     * break. The backslash escape is turned off so the output is the CSV
     * everything else reads, rather than PHP's own dialect of it, and the line
     * ending is the one the format asks for.
     *
     * @param array<int, array<int, string>> $rows The rows.
     * @return string The text.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _chunk(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        foreach ($rows as $row) {
            fputcsv($handle, $row, escape: '', eol: "\r\n");
        }

        rewind($handle);
        $text = (string)stream_get_contents($handle);
        fclose($handle);

        return $text;
    }

    /**
     * A stored date, written for whatever is going to read it.
     *
     * ISO 8601, offset and all. A spreadsheet parses it, a script parses it, and
     * nobody has to guess which way round the day and the month are. The stored
     * strings are UTC with nothing on them to say so, which is what
     * `DateTimeHelper` is for.
     *
     * @param mixed $value The stored value.
     * @return string The date, or an empty string when there is none.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _date(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $date = DateTimeHelper::toDateTime((string)$value);

        return $date instanceof DateTimeInterface ? $date->format(DateTimeInterface::ATOM) : '';
    }

    /**
     * An element and the site it is read on, as one memo key.
     *
     * @param int $elementId The element.
     * @param int $siteId The site it is read on.
     * @return string The memo key.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _elementKey(int $elementId, int $siteId): string
    {
        return $elementId . '-' . $siteId;
    }

    /**
     * Every element a batch of rows has something to say about.
     *
     * Two per row at most, and usually one. The owner is the page the Page
     * column names, for the reason {@see ReportService::references()} gives: a
     * link inside a Matrix block belongs to the block for storage and to the
     * page for editing, and the page is the only one of the two an editor has
     * ever seen. The element the link actually sits on is loaded as well when it
     * is a different one, because that is where the Field column's label comes
     * from: a field layout can override a field's label, and the override is the
     * name the editor is looking for on screen.
     *
     * Loaded by type rather than one at a time. Asking Craft for an element
     * without naming its class costs a lookup query before the read, so a batch
     * of five hundred references was a thousand queries; grouping the ids by the
     * type stored against them turns the whole batch into one query for the
     * types and one per type and site after it. The result is a local, so it
     * goes when the batch does and an export of a hundred thousand references
     * never holds more than a batch of pages at a time.
     *
     * @param array<int, array<string, mixed>> $batch The reference rows.
     * @return array<string, ElementInterface> Element id and site id to the
     *                                         element. A key is simply missing
     *                                         when the element could not be
     *                                         loaded.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _elements(array $batch): array
    {
        $wanted = [];

        foreach ($batch as $row) {
            $siteId = (int)$row['siteId'];

            foreach ([$this->_ownerId($row), (int)$row['elementId']] as $elementId) {
                $wanted[$this->_elementKey($elementId, $siteId)] = ['id' => $elementId, 'siteId' => $siteId];
            }
        }

        if ($wanted === []) {
            return [];
        }

        $types = $this->_elementTypes(array_values(array_map(
            static fn(array $want): int => $want['id'],
            $wanted,
        )));
        $groups = [];

        foreach ($wanted as $want) {
            $type = $types[$want['id']] ?? null;

            if ($type === null) {
                continue;
            }

            $groups[$type][$want['siteId']][] = $want['id'];
        }

        $loaded = [];

        foreach ($groups as $type => $idsBySite) {
            foreach ($idsBySite as $siteId => $ids) {
                // The same criteria Craft's own getElementById() applies, so a
                // row naming an unpublished page still names it: a reference is
                // recorded against whatever holds the link, enabled or not.
                $found = $type::find()
                    ->id(array_values(array_unique($ids)))
                    ->siteId($siteId)
                    ->status(null)
                    ->drafts(null)
                    ->provisionalDrafts(null)
                    ->revisions(null)
                    ->indexBy('id')
                    ->all();

                foreach ($found as $id => $element) {
                    $loaded[$this->_elementKey((int)$id, (int)$siteId)] = $element;
                }
            }
        }

        return $loaded;
    }

    /**
     * The class stored against each of a set of element ids.
     *
     * One query for the lot. A class the installation no longer has is left out
     * rather than guessed at: a reference outlives its element type, so this
     * really happens, and the columns it feeds are left blank instead.
     *
     * @param array<int, int> $ids The element ids.
     * @return array<int, class-string<ElementInterface>> Element id to class.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _elementTypes(array $ids): array
    {
        $rows = (new Query())
            ->select(['id', 'type'])
            ->from([Table::ELEMENTS])
            ->where(['id' => array_values(array_unique($ids))])
            ->all();

        $types = [];

        foreach ($rows as $row) {
            $type = (string)$row['type'];

            if (!class_exists($type) || !is_subclass_of($type, ElementInterface::class)) {
                continue;
            }

            $types[(int)$row['id']] = $type;
        }

        return $types;
    }

    /**
     * The column headings.
     *
     * @return array<int, string> The heading row.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _header(): array
    {
        return [
            Craft::t('link-audit', 'URL'),
            Craft::t('link-audit', 'Verdict'),
            Craft::t('link-audit', 'Reason'),
            Craft::t('link-audit', 'Response Code'),
            Craft::t('link-audit', 'Redirect Code'),
            Craft::t('link-audit', 'Goes To'),
            Craft::t('link-audit', 'Host'),
            Craft::t('link-audit', 'Page'),
            Craft::t('link-audit', 'Page Type'),
            Craft::t('link-audit', 'Site'),
            Craft::t('link-audit', 'Field'),
            Craft::t('link-audit', 'Link Text'),
            Craft::t('link-audit', 'Found Via'),
            Craft::t('link-audit', 'Places Total'),
            Craft::t('link-audit', 'First Seen'),
            Craft::t('link-audit', 'Last Checked'),
        ];
    }

    /**
     * The element a reference row is answerable to.
     *
     * @param array<string, mixed> $row The reference row.
     * @return int The element id.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _ownerId(array $row): int
    {
        return $row['ownerElementId'] !== null
            ? (int)$row['ownerElementId']
            : (int)$row['elementId'];
    }

    /**
     * The element a reference row is answerable to, and the site it is read on.
     *
     * @param array<string, mixed> $row The reference row.
     * @return string The memo key.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _ownerKey(array $row): string
    {
        return $this->_elementKey($this->_ownerId($row), (int)$row['siteId']);
    }

    /**
     * How many places point at each URL in a batch.
     *
     * One grouped query per batch rather than a correlated subquery on every
     * row. The same address turns up on row after row of an export, so counting
     * it per row would run the same count hundreds of times over for one answer.
     *
     * Counted in the same scope the file is read in, sites and reference filters
     * both, so the number agrees with the Places column on the screen the reader
     * exported from.
     *
     * @param array<int, array<string, mixed>> $batch The reference rows.
     * @param int[] $siteIds The sites being read.
     * @param array<string, mixed> $filters The filters from the request.
     * @return array<int, int> URL id to the number of places.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _placeCounts(array $batch, array $siteIds, array $filters): array
    {
        $urlIds = array_values(array_unique(array_map(
            static fn(array $row): int => (int)$row['urlId'],
            $batch,
        )));

        if ($urlIds === []) {
            return [];
        }

        $query = (new Query())
            ->select(['urlId' => 'r.urlId', 'total' => 'COUNT(*)'])
            ->from(['r' => ReferenceRecord::tableName()])
            ->where(['r.urlId' => $urlIds, 'r.siteId' => $siteIds])
            ->groupBy(['r.urlId']);

        $this->_addReferenceFilters($query, $filters);

        $counts = [];

        foreach ($query->all() as $row) {
            $counts[(int)$row['urlId']] = (int)$row['total'];
        }

        return $counts;
    }

    /**
     * The query behind the file.
     *
     * A reference joined to its URL, ordered by the reference id so the cursor
     * has something stable to page on.
     *
     * @param UrlStatus $status The verdict being exported.
     * @param int[] $siteIds The sites to read references on.
     * @param array<string, mixed> $filters The filters from the request.
     * @return Query The query.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _query(UrlStatus $status, array $siteIds, array $filters): Query
    {
        $query = (new Query())
            ->select([
                'id' => 'r.id',
                'urlId' => 'r.urlId',
                'elementId' => 'r.elementId',
                'elementType' => 'r.elementType',
                'ownerElementId' => 'r.ownerElementId',
                'siteId' => 'r.siteId',
                'fieldUid' => 'r.fieldUid',
                'fieldHandle' => 'r.fieldHandle',
                'linkText' => 'r.linkText',
                'source' => 'r.source',
                'url' => 'u.url',
                'host' => 'u.host',
                'status' => 'u.status',
                'reason' => 'u.reason',
                'httpStatus' => 'u.httpStatus',
                'redirectStatus' => 'u.redirectStatus',
                'finalUrl' => 'u.finalUrl',
                'dateFirstSeen' => 'u.dateFirstSeen',
                'dateLastChecked' => 'u.dateLastChecked',
            ])
            ->from(['r' => ReferenceRecord::tableName()])
            ->innerJoin(['u' => UrlRecord::tableName()], '[[u.id]] = [[r.urlId]]')
            ->where(['u.status' => $status->value, 'r.siteId' => $siteIds])
            ->orderBy(['r.id' => SORT_ASC]);

        $host = trim((string)($filters['host'] ?? ''));

        if ($host !== '') {
            $query->andWhere(Db::parseParam('u.host', $host));
        }

        $permanent = trim((string)($filters['permanent'] ?? ''));

        if ($permanent !== '') {
            $query->andWhere(['u.redirectPermanent' => $permanent === '1']);
        }

        // The same match {@see ReportService::_urlQuery()} makes, so a reader
        // who typed something into the table's search box and then pressed
        // Download gets the rows they were looking at rather than the whole
        // list again.
        $search = trim((string)($filters['search'] ?? ''));

        if ($search !== '') {
            $query->andWhere(Db::parseParam('u.url', '*' . $search . '*'));
        }

        $this->_addReferenceFilters($query, $filters);

        return $query;
    }

    /**
     * The element a reference row's field label has to come off.
     *
     * The element the link actually sits on, which is the owner itself unless
     * the link is in something nested.
     *
     * @param array<string, mixed> $row The reference row.
     * @return string The memo key.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _referenceKey(array $row): string
    {
        return $this->_elementKey((int)$row['elementId'], (int)$row['siteId']);
    }

    /**
     * One reference row, in the order the columns are in.
     *
     * The labels are the ones on screen rather than the values in the database,
     * because the file is read by the person who was just looking at the screen:
     * a column saying `unreachable` where the list said "No Answer" is a column
     * they have to translate in their head.
     *
     * @param array<string, mixed> $row The reference row.
     * @param array<string, ElementInterface> $elements The batch's elements.
     * @param array<int, int> $places The batch's place counts.
     * @param array<string, string> $sourceLabels What each source is called.
     * @return array<int, string> The cells.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _row(array $row, array $elements, array $places, array $sourceLabels): array
    {
        $report = LinkAudit::$plugin->getReportService();
        $status = UrlStatus::tryFrom((string)$row['status']) ?? UrlStatus::Pending;
        $element = $elements[$this->_ownerKey($row)] ?? null;
        // The Page column names the owner, because that is the page an editor
        // opens. The Field column has to come off the element the link is
        // actually stored on, because a field layout can override a field's
        // label and the override is the only name that editor has ever seen.
        $fieldElement = $elements[$this->_referenceKey($row)] ?? null;
        $site = Craft::$app->getSites()->getSiteById((int)$row['siteId']);
        $source = (string)$row['source'];

        return array_map($this->_cell(...), [
            $row['url'],
            $status->label(),
            Verdict::reasonLabel($row['reason'] !== null ? (string)$row['reason'] : null),
            $row['httpStatus'],
            $row['redirectStatus'],
            $row['finalUrl'],
            $row['host'],
            // An element that will not load is left blank rather than guessed
            // at. It means the page has gone since the last scan, and its id
            // would be no use to anybody opening this file to fix content.
            $element?->getUiLabel(),
            $report->elementTypeLabel((string)$row['elementType']),
            $site?->name,
            $report->fieldName($row, $fieldElement),
            $row['linkText'],
            $sourceLabels[$source] ?? $source,
            $places[(int)$row['urlId']] ?? 1,
            $this->_date($row['dateFirstSeen']),
            $this->_date($row['dateLastChecked']),
        ]);
    }
}
