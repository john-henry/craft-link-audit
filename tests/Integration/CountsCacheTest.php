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
// cleared by tag whenever something moves. Every writer clears them; the orphan
// sweep did not, and it moves one of them.
//
// It is not obvious that it does, which is why it was missed: the counts only
// see URLs that something on the site points at, and an orphan by definition is
// pointed at by nothing. The exception is the dismissed count, which counts an
// ignore whose URL row has gone as well as one whose URL is still referenced.
// Pruning an ignored orphan therefore moves it from uncounted to counted.
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

    $before = $reports->cachedVerdictCounts($siteId);

    // Nothing was backdated, so the grace period holds everything back.
    LinkAudit::$plugin->getScanService()->pruneOrphanUrls();

    expect($reports->cachedVerdictCounts($siteId))->toBe($before);
});
