<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\helpers\Db;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\helpers\UrlNormaliser;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\records\UrlRecord;

// ---------------------------------------------------------------------------
// The cached verdict counts
//
// The badges beside the nav read a cached set of counts, held for a minute and
// cleared by tag whenever something moves, the orphan sweep included.
//
// The counts only see URLs that something on the site points at, and an orphan
// is pointed at by nothing. The exception is the dismissed count, which counts
// an ignore whose URL row has gone as well as one whose URL is still
// referenced, so pruning an ignored orphan moves it.
//
// Helper names carry a `counts` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/** An ignored URL that nothing points at, old enough for the orphan sweep. */
function countsOrphanedIgnoredUrl(): string
{
    $url = 'https://example.com/orphan-' . bin2hex(random_bytes(6));
    $store = LinkAudit::$plugin->getUrlStore();

    $urlId = $store->upsert($url, false, null, UrlStatus::Pending);
    LinkAudit::$plugin->getIgnoreService()->ignoreUrl(UrlNormaliser::hash($url));

    // The sweep leaves anything seen in the last hour alone, so this one has to
    // look older than that.
    Db::update(UrlRecord::tableName(), [
        'dateFirstSeen' => Db::prepareDateForDb(new DateTime('-2 days')),
    ], ['id' => $urlId]);

    return $url;
}

it('does not serve a count the orphan sweep has just made wrong', function() {
    $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
    $reports = LinkAudit::$plugin->getReportService();

    countsOrphanedIgnoredUrl();

    // Warm the cache, so what follows is served from it unless something clears
    // it. Without the clear this is the number still on screen a minute later.
    $before = $reports->cachedVerdictCounts($siteId)['dismissed'] ?? 0;

    LinkAudit::$plugin->getScanService()->pruneOrphanUrls();

    expect($reports->cachedVerdictCounts($siteId)['dismissed'] ?? 0)
        ->not->toBe($before);
});

it('leaves the counts alone when the sweep finds nothing to remove', function() {
    $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
    $reports = LinkAudit::$plugin->getReportService();

    // Clear whatever orphans the test database already holds, so the sweep
    // below has nothing to find.
    LinkAudit::$plugin->getScanService()->pruneOrphanUrls();
    $reports->invalidateCounts();

    $before = $reports->cachedVerdictCounts($siteId);

    LinkAudit::$plugin->getScanService()->pruneOrphanUrls();

    expect($reports->cachedVerdictCounts($siteId))->toBe($before);
});
