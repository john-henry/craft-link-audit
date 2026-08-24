<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\db\Query;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\linkaudit\enums\ScanMode;
use johnhenry\linkaudit\enums\ScanStatus;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\Verdict;
use johnhenry\linkaudit\records\ReferenceRecord;
use johnhenry\linkaudit\records\ScanRecord;
use johnhenry\linkaudit\records\UrlRecord;
use markhuot\craftpest\factories\Entry as EntryFactory;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** A scan row in a given mode, already marked as running. */
function laFinaliseScanRow(ScanMode $mode, ?int $siteId = null): int
{
    $scan = new ScanRecord([
        'siteId' => $siteId,
        'mode' => $mode->value,
        'status' => ScanStatus::Checking->value,
    ]);
    $scan->save(false);

    return (int)$scan->id;
}

/** A real element id, so the references table's foreign key is satisfied. */
function laFinaliseElementId(): int
{
    return (int)UserFactory::factory()->create()->id;
}

/** One fixture entry carrying the given rich text. */
function laFinaliseEntry(string $html): craft\elements\Entry
{
    $section = Craft::$app->getEntries()->getSectionByHandle('laFixture');

    if ($section === null) {
        throw new RuntimeException(
            'The laFixture test section is missing. Run `ddev craft project-config/apply`.',
        );
    }

    $slug = 'la-finalise-' . StringHelper::toLowerCase(StringHelper::randomString(12));

    return EntryFactory::factory()
        ->section($section)
        ->title('LA finalise ' . $slug)
        ->slug($slug)
        ->set('laBody', $html)
        ->create();
}

/** Drags a URL row's first seen date past the pruning grace window. */
function laAgeUrl(int $urlId): void
{
    Db::update(UrlRecord::tableName(), [
        'dateFirstSeen' => Db::prepareDateForDb(DateTimeHelper::now()->modify('-2 hours')),
    ], ['id' => $urlId]);
}

/** Whether a URL row is still there. */
function laUrlExists(int $urlId): bool
{
    return (new Query())
        ->from([UrlRecord::tableName()])
        ->where(['id' => $urlId])
        ->exists();
}

/** How many reference rows point at an element. */
function laFinaliseReferenceCount(int $elementId): int
{
    return (int)(new Query())
        ->from([ReferenceRecord::tableName()])
        ->where(['elementId' => $elementId])
        ->count();
}

/**
 * Empties the URL and reference tables, in foreign key order.
 *
 * For the tests that assert an exact count. This suite runs against a
 * development database carrying whatever the last real scan left behind, and the
 * broken count is a count of everything the installation is still holding.
 */
function laFinaliseClearUrls(): void
{
    $db = Craft::$app->getDb();

    foreach ([ReferenceRecord::tableName(), UrlRecord::tableName()] as $table) {
        $db->createCommand()->delete($table)->execute();
    }
}

/** A scan row, read back in full. */
function laFinaliseScanRead(int $scanId): array
{
    /** @var array<string, mixed> $row */
    $row = (new Query())
        ->from([ScanRecord::tableName()])
        ->where(['id' => $scanId])
        ->one();

    return $row;
}

// ---------------------------------------------------------------------------
// Orphans
// ---------------------------------------------------------------------------

it('deletes the URL row left behind when an author removes the last link to it', function() {
    $service = LinkAudit::getInstance()->getScanService();
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $entry = laFinaliseEntry('<p><a href="https://example.com/about-to-go">Going</a></p>');

    $scanId = laFinaliseScanRow(ScanMode::Single, $siteId);
    $service->extractElement((int)$entry->id, null, $siteId, $scanId);

    $urlId = (int)(new Query())
        ->select(['urlId'])
        ->from([ReferenceRecord::tableName()])
        ->where(['elementId' => $entry->id])
        ->scalar();

    expect($urlId)->toBeGreaterThan(0);

    // The author takes the link back out and the entry is read again.
    $entry->setFieldValue('laBody', '<p>No links here any more.</p>');
    Craft::$app->getElements()->saveElement($entry);

    $rescanId = laFinaliseScanRow(ScanMode::Single, $siteId);
    $service->extractElement((int)$entry->id, null, $siteId, $rescanId);

    expect(laFinaliseReferenceCount((int)$entry->id))->toBe(0)
        // Orphaned but still there, until the scan is closed out.
        ->and(laUrlExists($urlId))->toBeTrue();

    // Old enough to be past the grace window, which is what tells a genuine
    // orphan from a row whose reference is still being written.
    laAgeUrl($urlId);

    $service->finalise($rescanId);

    expect(laUrlExists($urlId))->toBeFalse();
});

it('leaves a URL row alone while its references are still being written', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $service = LinkAudit::getInstance()->getScanService();

    // Exactly what extraction leaves behind between storing a URL and inserting
    // the reference rows that point at it. A scan finishing in that window used
    // to delete the row out from under the insert, and the foreign key error
    // that followed took the extracting job down with it.
    $freshId = $store->upsert('https://example.com/still-being-written', false);
    $oldId = $store->upsert('https://example.com/genuinely-orphaned', false);
    laAgeUrl($oldId);

    $service->finalise((int)laFinaliseScanRow(ScanMode::Full));

    expect(laUrlExists($freshId))->toBeTrue()
        ->and(laUrlExists($oldId))->toBeFalse();
});

it('leaves an orphaned URL alone when the setting says to keep it', function() {
    $service = LinkAudit::getInstance()->getScanService();
    LinkAudit::getInstance()->getSettings()->pruneOrphanUrls = false;

    $urlId = LinkAudit::getInstance()->getUrlStore()->upsert('https://example.com/kept-orphan', false);
    $scanId = laFinaliseScanRow(ScanMode::Full);

    $service->finalise($scanId);

    expect(laUrlExists($urlId))->toBeTrue();
});

it('keeps a URL something still points at', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $elementId = laFinaliseElementId();
    $urlId = $store->upsert('https://example.com/still-linked', false);
    $scanId = laFinaliseScanRow(ScanMode::Full, $siteId);

    $store->replaceReferencesFor($elementId, $siteId, [
        ['urlId' => $urlId, 'elementType' => User::class, 'scanId' => $scanId],
    ]);

    LinkAudit::getInstance()->getScanService()->finalise($scanId);

    expect(laUrlExists($urlId))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Stale references
// ---------------------------------------------------------------------------

it('clears out references from a run before this one, once a full run has been', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $service = LinkAudit::getInstance()->getScanService();
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;

    $oldScanId = laFinaliseScanRow(ScanMode::Full, $siteId);
    $staleElementId = laFinaliseElementId();
    $urlId = $store->upsert('https://example.com/stale-reference', false);

    $store->replaceReferencesFor($staleElementId, $siteId, [
        ['urlId' => $urlId, 'elementType' => User::class, 'scanId' => $oldScanId],
    ]);

    $newScanId = laFinaliseScanRow(ScanMode::Full, $siteId);
    $freshElementId = laFinaliseElementId();
    $store->replaceReferencesFor($freshElementId, $siteId, [
        ['urlId' => $urlId, 'elementType' => User::class, 'scanId' => $newScanId],
    ]);

    $service->finalise($newScanId);

    expect(laFinaliseReferenceCount($staleElementId))->toBe(0)
        ->and(laFinaliseReferenceCount($freshElementId))->toBe(1);
});

it('does not prune what an incremental run never looked at', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $service = LinkAudit::getInstance()->getScanService();
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;

    $oldScanId = laFinaliseScanRow(ScanMode::Full, $siteId);
    $untouchedElementId = laFinaliseElementId();
    $urlId = $store->upsert('https://example.com/untouched', false);

    $store->replaceReferencesFor($untouchedElementId, $siteId, [
        ['urlId' => $urlId, 'elementType' => User::class, 'scanId' => $oldScanId],
    ]);

    $incrementalId = laFinaliseScanRow(ScanMode::Incremental, $siteId);
    $editedElementId = laFinaliseElementId();
    $store->replaceReferencesFor($editedElementId, $siteId, [
        ['urlId' => $urlId, 'elementType' => User::class, 'scanId' => $incrementalId],
    ]);

    $service->finalise($incrementalId);

    expect(laFinaliseReferenceCount($untouchedElementId))->toBe(1)
        ->and(laFinaliseReferenceCount($editedElementId))->toBe(1);
});

it('only prunes the site a site scoped run covered', function() {
    $sites = Craft::$app->getSites()->getAllSites();

    if (count($sites) < 2) {
        $this->markTestSkipped('Needs a second site to have another site to leave alone.');
    }

    $store = LinkAudit::getInstance()->getUrlStore();
    $service = LinkAudit::getInstance()->getScanService();
    $scannedSiteId = (int)$sites[0]->id;
    $otherSiteId = (int)$sites[1]->id;

    $oldScanId = laFinaliseScanRow(ScanMode::Full, $scannedSiteId);
    $elementId = laFinaliseElementId();
    $urlId = $store->upsert('https://example.com/other-site', false);

    $store->replaceReferencesFor($elementId, $otherSiteId, [
        ['urlId' => $urlId, 'elementType' => User::class, 'scanId' => $oldScanId],
    ]);

    $newScanId = laFinaliseScanRow(ScanMode::Full, $scannedSiteId);
    $service->finalise($newScanId);

    expect(laFinaliseReferenceCount($elementId))->toBe(1);
});

// ---------------------------------------------------------------------------
// Totals
// ---------------------------------------------------------------------------

it('closes the scan out with its totals recounted', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $service = LinkAudit::getInstance()->getScanService();
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $scanId = laFinaliseScanRow(ScanMode::Full, $siteId);

    $okId = $store->upsert('https://example.com/totals-ok', false);
    $brokenId = $store->upsert('https://example.com/totals-broken', false);
    $store->recordVerdict($okId, new Verdict(status: UrlStatus::Ok, httpStatus: 200));
    $store->recordVerdict($brokenId, new Verdict(status: UrlStatus::Broken, httpStatus: 404));

    $store->replaceReferencesFor(laFinaliseElementId(), $siteId, [
        ['urlId' => $okId, 'elementType' => User::class, 'scanId' => $scanId],
        ['urlId' => $brokenId, 'elementType' => User::class, 'scanId' => $scanId],
    ]);

    $service->finalise($scanId);

    $row = laFinaliseScanRead($scanId);

    expect($row['status'])->toBe(ScanStatus::Complete->value)
        ->and($row['dateFinished'])->not->toBeNull()
        ->and((int)$row['urlsTotal'])->toBe(2)
        ->and((int)$row['urlsBroken'])->toBeGreaterThanOrEqual(1);
});

it('counts only its own site as broken when it covered one site', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $service = LinkAudit::getInstance()->getScanService();
    $sites = Craft::$app->getSites()->getAllSites();

    if (count($sites) < 2) {
        test()->markTestSkipped('This one needs a second site.');
    }

    laFinaliseClearUrls();

    $scannedSiteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
    $otherSiteId = (int)$sites[1]->id;
    $elementId = laFinaliseElementId();

    $mineId = $store->upsert('https://example.com/broken-on-my-site', false);
    $theirsId = $store->upsert('https://example.com/broken-on-the-other-site', false);
    $store->recordVerdict($mineId, new Verdict(status: UrlStatus::Broken, httpStatus: 404));
    $store->recordVerdict($theirsId, new Verdict(status: UrlStatus::Broken, httpStatus: 404));

    $scanId = laFinaliseScanRow(ScanMode::CheckOnly, $scannedSiteId);

    $store->replaceReferencesFor($elementId, $scannedSiteId, [
        ['urlId' => $mineId, 'elementType' => User::class, 'scanId' => $scanId],
    ]);
    $store->replaceReferencesFor($elementId, $otherSiteId, [
        ['urlId' => $theirsId, 'elementType' => User::class, 'scanId' => $scanId],
    ]);

    $service->finalise($scanId);

    // One, not two: the other site's broken link is not this scan's to report.
    expect((int)laFinaliseScanRead($scanId)['urlsBroken'])->toBe(1);
});

it('counts every site as broken when it covered every site', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $service = LinkAudit::getInstance()->getScanService();
    $sites = Craft::$app->getSites()->getAllSites();

    if (count($sites) < 2) {
        test()->markTestSkipped('This one needs a second site.');
    }

    laFinaliseClearUrls();

    $elementId = laFinaliseElementId();
    $mineId = $store->upsert('https://example.com/all-sites-one', false);
    $theirsId = $store->upsert('https://example.com/all-sites-two', false);
    $store->recordVerdict($mineId, new Verdict(status: UrlStatus::Broken, httpStatus: 404));
    $store->recordVerdict($theirsId, new Verdict(status: UrlStatus::Broken, httpStatus: 404));

    $scanId = laFinaliseScanRow(ScanMode::CheckOnly, null);

    $store->replaceReferencesFor($elementId, (int)Craft::$app->getSites()->getPrimarySite()->id, [
        ['urlId' => $mineId, 'elementType' => User::class, 'scanId' => $scanId],
    ]);
    $store->replaceReferencesFor($elementId, (int)$sites[1]->id, [
        ['urlId' => $theirsId, 'elementType' => User::class, 'scanId' => $scanId],
    ]);

    $service->finalise($scanId);

    expect((int)laFinaliseScanRead($scanId)['urlsBroken'])->toBe(2);
});

it('says so and carries on when the scan it was asked to close is gone', function() {
    LinkAudit::getInstance()->getScanService()->finalise(999999);
})->throwsNoExceptions();
