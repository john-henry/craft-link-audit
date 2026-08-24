<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\db\Query;
use craft\elements\Address;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\linkaudit\enums\ScanMode;
use johnhenry\linkaudit\helpers\ScannableElementTypes;
use johnhenry\linkaudit\enums\ScanStatus;
use johnhenry\linkaudit\exceptions\ScanInProgressException;
use johnhenry\linkaudit\jobs\ExtractLinks;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\records\ReferenceRecord;
use johnhenry\linkaudit\records\ScanRecord;
use johnhenry\linkaudit\records\UrlRecord;
use markhuot\craftpest\factories\Entry as EntryFactory;
use yii\queue\sync\Queue as SyncQueue;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * A batch of fixture entries, each carrying the same rich text.
 *
 * The section, its entry types and its fields live in project config rather than
 * being provisioned here: creating a section auto-commits the transaction
 * RefreshesDatabase relies on.
 *
 * @return Entry[]
 */
function laScanEntries(int $count, string $html): array
{
    $section = Craft::$app->getEntries()->getSectionByHandle('laFixture');

    if ($section === null) {
        throw new RuntimeException(
            'The laFixture test section is missing. Run `ddev craft project-config/apply`.',
        );
    }

    $entries = [];

    for ($i = 0; $i < $count; $i++) {
        $slug = 'la-scan-' . StringHelper::toLowerCase(StringHelper::randomString(12));
        $entries[] = EntryFactory::factory()
            ->section($section)
            ->title('LA scan ' . $slug)
            ->slug($slug)
            ->set('laBody', $html)
            ->create();
    }

    return $entries;
}

/** The element ids of a set of entries. */
function laIds(array $entries): array
{
    return array_map(static fn(Entry $entry): int => (int)$entry->id, $entries);
}

/**
 * A scan row, without the queue push {@see ScanService::startScan()} would do.
 *
 * The jobs are driven by hand in these tests so the paging is what is under
 * test, rather than whether a worker happened to pick the next batch up.
 */
function laScanRecordFor(ScanMode $mode): ScanRecord
{
    $scan = new ScanRecord([
        'mode' => $mode->value,
        'status' => ScanStatus::Queued->value,
    ]);
    $scan->save(false);

    return $scan;
}

/**
 * Runs the extract job to completion against a known set of elements.
 *
 * The batch runner spawns a copy of itself through the queue, which a test has
 * no worker for, so the loop here does the same thing in the same order. The
 * queue handed in is a sync one purely so the spawned copies have somewhere
 * harmless to go.
 */
function laRunExtract(int $scanId, array $elementIds, ?string $since = null, int $batchSize = 100): void
{
    $siteIds = [Craft::$app->getSites()->getPrimarySite()->id];
    $service = LinkAudit::getInstance()->getScanService();

    $total = (int)$service
        ->elementQuery($siteIds, $since !== null ? DateTimeHelper::toDateTime($since) : null, $elementIds)
        ->count();

    $job = new ExtractLinks([
        'batchSize' => $batchSize,
        'elementIds' => $elementIds,
        'scanId' => $scanId,
        'since' => $since,
        'siteIds' => $siteIds,
    ]);

    $queue = new SyncQueue();

    do {
        $job->execute($queue);
        $job->batchIndex++;
    } while ($job->itemOffset < $total);
}

/** Every distinct element a scan recorded a reference against. */
function laScannedElementIds(int $scanId): array
{
    $ids = array_map('intval', (new Query())
        ->select(['elementId'])
        ->distinct()
        ->from([ReferenceRecord::tableName()])
        ->where(['scanId' => $scanId])
        ->column());

    sort($ids);

    return $ids;
}

/** How many reference rows a scan recorded. */
function laScanReferenceCount(int $scanId): int
{
    return (int)(new Query())
        ->from([ReferenceRecord::tableName()])
        ->where(['scanId' => $scanId])
        ->count();
}

/** A scan row, read back in full. */
function laScanRow(int $scanId): array
{
    /** @var array<string, mixed> $row */
    $row = (new Query())
        ->from([ScanRecord::tableName()])
        ->where(['id' => $scanId])
        ->one();

    return $row;
}

/**
 * Drags an element's edit date to a known moment on both of its tables.
 *
 * The timestamp is written rather than touched, which is why the helper's own
 * update must be told to leave `dateUpdated` alone.
 */
function laTouchElement(int $elementId, DateTimeInterface $when): void
{
    laTouchElementRow($elementId, $when);
    laTouchSiteRow($elementId, $when);
}

/** Drags the element row's edit date alone, leaving every site row where it is. */
function laTouchElementRow(int $elementId, DateTimeInterface $when): void
{
    Db::update('{{%elements}}', ['dateUpdated' => Db::prepareDateForDb($when)], ['id' => $elementId], [], false);
}

/** Drags a site row's edit date alone, leaving the element row where it is. */
function laTouchSiteRow(int $elementId, DateTimeInterface $when, ?int $siteId = null): void
{
    $condition = ['elementId' => $elementId];

    if ($siteId !== null) {
        $condition['siteId'] = $siteId;
    }

    Db::update('{{%elements_sites}}', ['dateUpdated' => Db::prepareDateForDb($when)], $condition, [], false);
}

// ---------------------------------------------------------------------------
// Batched extraction
// ---------------------------------------------------------------------------

it('reads every element across the batches, none skipped and none read twice', function() {
    $ids = laIds(laScanEntries(250, '<p><a href="https://example.com/batched-scan">A link</a></p>'));
    $scanId = (int)laScanRecordFor(ScanMode::Full)->id;

    laRunExtract($scanId, $ids);

    sort($ids);

    expect(laScannedElementIds($scanId))->toBe($ids)
        // One reference per element: read twice and this would be five hundred.
        ->and(laScanReferenceCount($scanId))->toBe(250)
        ->and((int)laScanRow($scanId)['elementsScanned'])->toBe(250)
        ->and(laScanRow($scanId)['status'])->toBe(ScanStatus::Extracting->value)
        ->and(laScanRow($scanId)['dateStarted'])->not->toBeNull();
});

it('stores the shared link once however many entries carry it', function() {
    $ids = laIds(laScanEntries(12, '<p><a href="https://example.com/one-shared-link">Shared</a></p>'));
    $scanId = (int)laScanRecordFor(ScanMode::Full)->id;

    laRunExtract($scanId, $ids, null, 5);

    $urlIds = (new Query())
        ->select(['urlId'])
        ->distinct()
        ->from([ReferenceRecord::tableName()])
        ->where(['scanId' => $scanId])
        ->column();

    expect($urlIds)->toHaveCount(1)
        ->and(laScanReferenceCount($scanId))->toBe(12);
});

// ---------------------------------------------------------------------------
// Incremental
// ---------------------------------------------------------------------------

it('reads only the elements edited since the last completed run', function() {
    $ids = laIds(laScanEntries(8, '<p><a href="https://example.com/incremental">A link</a></p>'));

    $since = DateTimeHelper::now();
    $touched = array_slice($ids, 0, 5);

    foreach (array_slice($ids, 5) as $id) {
        laTouchElement($id, (clone $since)->modify('-1 hour'));
    }

    foreach ($touched as $id) {
        laTouchElement($id, (clone $since)->modify('+5 seconds'));
    }

    $scanId = (int)laScanRecordFor(ScanMode::Incremental)->id;
    laRunExtract($scanId, $ids, $since->format(DATE_ATOM));

    sort($touched);

    expect(laScannedElementIds($scanId))->toBe($touched)
        ->and((int)laScanRow($scanId)['elementsScanned'])->toBe(5);
});

it('catches an edit made on one site and nowhere else', function() {
    // The reason the cut-off reads both tables. An author editing the Spoke &
    // Chain copy of an entry moves that site's row and nothing else: the element
    // row still says it was last touched whenever the entry itself changed, so a
    // filter on that alone would walk straight past the edit.
    $sites = LinkAudit::getInstance()->getScanService()->siteIds();

    expect($sites)->toHaveCount(2, 'This test needs the second test site.');

    $ids = laIds(laScanEntries(2, '<p><a href="https://example.com/one-site-edit">A link</a></p>'));
    $since = DateTimeHelper::now();

    foreach ($ids as $id) {
        laTouchElement($id, (clone $since)->modify('-1 hour'));
    }

    laTouchSiteRow($ids[0], (clone $since)->modify('+5 seconds'), $sites[1]);

    $rows = LinkAudit::getInstance()->getScanService()
        ->elementQuery($sites, $since, $ids)
        ->all();

    expect($rows)->toHaveCount(1)
        ->and((int)$rows[0]['elementId'])->toBe($ids[0])
        ->and((int)$rows[0]['siteId'])->toBe($sites[1]);
});

it('catches an edit that moved the element row and no site row', function() {
    $sites = LinkAudit::getInstance()->getScanService()->siteIds();
    $ids = laIds(laScanEntries(2, '<p><a href="https://example.com/element-row-edit">A link</a></p>'));
    $since = DateTimeHelper::now();

    foreach ($ids as $id) {
        laTouchElement($id, (clone $since)->modify('-1 hour'));
    }

    laTouchElementRow($ids[0], (clone $since)->modify('+5 seconds'));

    $rows = LinkAudit::getInstance()->getScanService()
        ->elementQuery($sites, $since, $ids)
        ->all();

    // Both of that element's site rows, since the element itself changed and
    // there is no telling which site's content moved with it.
    expect($rows)->toHaveCount(count($sites))
        ->and(array_unique(array_map(static fn(array $row): int => (int)$row['elementId'], $rows)))
        ->toBe([$ids[0]]);
});

it('takes the incremental cut-off from when the last full run started', function() {
    // Whatever the development database has been through, the cut-off has to
    // come from the row this test controls.
    Db::delete(ScanRecord::tableName(), []);

    $started = DateTimeHelper::now()->modify('-2 hours');
    $scan = laScanRecordFor(ScanMode::Full);
    $scan->status = ScanStatus::Complete->value;
    $scan->dateStarted = Db::prepareDateForDb($started);
    $scan->dateFinished = Db::prepareDateForDb(DateTimeHelper::now()->modify('-1 hour'));
    $scan->save(false);

    $cutOff = LinkAudit::getInstance()->getScanService()->lastCompletedScanStart();

    expect($cutOff)->not->toBeNull()
        ->and(abs($cutOff->getTimestamp() - $started->getTimestamp()))->toBeLessThan(2);
});

it('has no cut-off at all until a run has completed', function() {
    Db::delete(ScanRecord::tableName(), []);

    laScanRecordFor(ScanMode::Full);

    expect(LinkAudit::getInstance()->getScanService()->lastCompletedScanStart())->toBeNull();
});

// ---------------------------------------------------------------------------
// Exclusions
// ---------------------------------------------------------------------------

it('leaves out an element whose URI matches an excluded pattern', function() {
    $entries = laScanEntries(2, '<p><a href="https://example.com/excluded-check">A link</a></p>');
    $ids = laIds($entries);

    LinkAudit::getInstance()->getSettings()->excludedUriPatterns = [
        [
            'uriPattern' => '^' . preg_quote((string)$entries[0]->uri, '~') . '$',
            'siteId' => '',
            'enabled' => true,
        ],
    ];

    $scanId = (int)laScanRecordFor(ScanMode::Full)->id;
    laRunExtract($scanId, $ids);

    expect(laScannedElementIds($scanId))->toBe([$ids[1]])
        ->and((int)laScanRow($scanId)['elementsScanned'])->toBe(1);
});

it('only applies a site scoped exclusion to the site it names', function() {
    $service = LinkAudit::getInstance()->getScanService();
    $settings = LinkAudit::getInstance()->getSettings();
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;

    $settings->excludedUriPatterns = [
        ['uriPattern' => '^secret', 'siteId' => $siteId + 1000, 'enabled' => true],
    ];

    expect($service->isUriExcluded('secret/page', $siteId))->toBeFalse();

    $settings->excludedUriPatterns = [
        ['uriPattern' => '^secret', 'siteId' => $siteId, 'enabled' => true],
    ];

    expect($service->isUriExcluded('secret/page', $siteId))->toBeTrue()
        ->and($service->isUriExcluded('public/page', $siteId))->toBeFalse();
});

it('treats an empty pattern as the homepage and nothing else', function() {
    $service = LinkAudit::getInstance()->getScanService();
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;

    LinkAudit::getInstance()->getSettings()->excludedUriPatterns = [
        ['uriPattern' => '', 'siteId' => '', 'enabled' => true],
    ];

    expect($service->isUriExcluded(null, $siteId))->toBeTrue()
        ->and($service->isUriExcluded('__home__', $siteId))->toBeTrue()
        ->and($service->isUriExcluded('about-us', $siteId))->toBeFalse();
});

// ---------------------------------------------------------------------------
// The element query
// ---------------------------------------------------------------------------

it('offers an element with no public URL at all', function() {
    $elementId = laIds(laScanEntries(1, '<p><a href="https://example.com/no-uri">A link</a></p>'))[0];

    Db::update('{{%elements_sites}}', ['uri' => null], ['elementId' => $elementId], [], false);

    $rows = LinkAudit::getInstance()->getScanService()
        ->elementQuery([Craft::$app->getSites()->getPrimarySite()->id], null, [$elementId])
        ->all();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['uri'])->toBeNull();
});

it('keeps one site\'s references safe from a tag pinned at it read on another site', function() {
    $target = laScanEntries(1, '<p>Just a page to point at.</p>')[0];
    $entry = laScanEntries(1, sprintf(
        '<p><a href="https://example.com/first">One</a></p>'
        . '<p><a href="https://example.com/second">Two</a></p>'
        . '<p><a href="{entry:%d@1:url}">Pinned at site one</a></p>',
        (int)$target->id,
    ))[0];

    LinkAudit::getInstance()->getScanService()->refreshElement((int)$entry->id);

    $bySite = (new Query())
        ->select(['refs' => 'COUNT(*)', 'siteId' => 'siteId'])
        ->from(ReferenceRecord::tableName())
        ->where(['elementId' => (int)$entry->id])
        ->groupBy('siteId')
        ->indexBy('siteId')
        ->column();

    // Every site the entry lives on carries all three references. Before the
    // grouping fix, whichever site was extracted LAST would file the pinned
    // tag's reference under site one, and the replace call carrying that lone
    // row wiped everything site one had.
    expect($bySite)->not->toBeEmpty()
        ->and(array_values(array_unique(array_map('intval', $bySite))))->toBe([3]);
});

it('leaves nested elements to the walker that owns them', function() {
    $entry = laScanEntries(1, '<p><a href="https://example.com/owner">Owner</a></p>')[0];
    $entry->setFieldValue('laBlocks', [
        'new1' => [
            'type' => 'laBlock',
            'fields' => [
                'laBody' => '<p><a href="https://example.com/nested">Nested</a></p>',
            ],
        ],
    ]);
    Craft::$app->getElements()->saveElement($entry);

    $service = LinkAudit::getInstance()->getScanService();
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $scanId = (int)laScanRecordFor(ScanMode::Full)->id;

    $service->extractElement((int)$entry->id, null, $siteId, $scanId);

    $nestedIds = array_values(array_diff(laScannedElementIds($scanId), [(int)$entry->id]));

    expect($nestedIds)->toHaveCount(1)
        // The nested entry has reference rows of its own, but the scan query
        // must not offer it up in its own right or two batches would rebuild
        // the same rows.
        ->and($service->elementQuery([$siteId], null, $nestedIds)->all())->toBe([]);
});

// ---------------------------------------------------------------------------
// What a scan reads out of the box
// ---------------------------------------------------------------------------

it('reads content by default and leaves the sheer volume of Commerce alone', function() {
    $native = ScannableElementTypes::native();
    $offered = array_keys(ScannableElementTypes::all());

    expect($native)->toContain(Entry::class)
        ->toContain(Category::class)
        ->toContain(Tag::class)
        ->toContain(GlobalSet::class)
        // Offered in the settings for anybody who wants them, but not read
        // unless they are asked for.
        ->and($native)->not->toContain(User::class)
        ->and($native)->not->toContain(Address::class)
        ->and($offered)->toContain(User::class)
        // Assets are binary files, so they are not offered at all.
        ->and($offered)->not->toContain(Asset::class);
});

it('reads Commerce products but not every order the shop has ever taken', function() {
    if (!class_exists(Order::class)) {
        $this->markTestSkipped('Needs Commerce installed to have orders to leave alone.');
    }

    $native = ScannableElementTypes::native();

    expect(ScannableElementTypes::all())->toHaveKey(Order::class)
        ->and($native)->toContain(Product::class)
        ->and($native)->toContain(Variant::class)
        // Every abandoned cart on the site, read for links nobody ever typed.
        ->and($native)->not->toContain(Order::class);
});

// ---------------------------------------------------------------------------
// Awkward hrefs
// ---------------------------------------------------------------------------

it('leaves out a host too long to be a host, and keeps reading the element', function() {
    $host = str_repeat('averylongsegment.', 20) . 'example.com';

    $entry = laScanEntries(1, sprintf(
        '<p><a href="https://%s/page">Too long</a> <a href="https://example.com/fine">Fine</a></p>',
        $host,
    ))[0];

    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $scanId = (int)laScanRecordFor(ScanMode::Full)->id;

    $found = LinkAudit::getInstance()->getScanService()
        ->extractElement((int)$entry->id, null, $siteId, $scanId);

    $hosts = (new Query())
        ->select(['u.host'])
        ->from(['r' => ReferenceRecord::tableName()])
        ->innerJoin(['u' => UrlRecord::tableName()], '[[u.id]] = [[r.urlId]]')
        ->where(['r.elementId' => $entry->id])
        ->column();

    expect(strlen($host))->toBeGreaterThan(253)
        ->and($found)->toBe(1)
        ->and($hosts)->toBe(['example.com']);
});

// ---------------------------------------------------------------------------
// One run at a time
// ---------------------------------------------------------------------------

it('refuses a second run that reads content while one is already going', function() {
    Db::delete(ScanRecord::tableName(), []);

    $service = LinkAudit::getInstance()->getScanService();
    $first = $service->startScan(ScanMode::Full);

    expect(fn() => $service->startScan(ScanMode::Full))
        ->toThrow(ScanInProgressException::class)
        ->and(fn() => $service->startScan(ScanMode::Incremental))
        ->toThrow(ScanInProgressException::class);

    try {
        $service->startScan(ScanMode::Full);
    } catch (ScanInProgressException $e) {
        // The refusal names the run to wait for.
        expect($e->scanId)->toBe((int)$first->id);
    }

    expect((int)(new Query())->from([ScanRecord::tableName()])->count())->toBe(1);
});

it('still lets a check only run through while a scan is going', function() {
    Db::delete(ScanRecord::tableName(), []);

    $service = LinkAudit::getInstance()->getScanService();
    $service->startScan(ScanMode::Full);
    $checkOnly = $service->startScan(ScanMode::CheckOnly);

    expect((int)$checkOnly->id)->toBeGreaterThan(0)
        ->and((int)(new Query())->from([ScanRecord::tableName()])->count())->toBe(2);
});

it('starts a fresh run once the one before went quiet long enough to be abandoned', function() {
    Db::delete(ScanRecord::tableName(), []);

    $service = LinkAudit::getInstance()->getScanService();
    $abandoned = $service->startScan(ScanMode::Full);

    Db::update(ScanRecord::tableName(), [
        'dateUpdated' => Db::prepareDateForDb(DateTimeHelper::now()->modify('-3 hours')),
    ], ['id' => $abandoned->id], [], false);

    expect((int)$service->startScan(ScanMode::Full)->id)->toBeGreaterThan((int)$abandoned->id);
});
