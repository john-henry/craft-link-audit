<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use GuzzleHttp\Psr7\Response;
use johnhenry\linkaudit\enums\ScanMode;
use johnhenry\linkaudit\enums\ScanStatus;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\helpers\HtmlParser;
use johnhenry\linkaudit\helpers\UrlNormaliser;
use johnhenry\linkaudit\jobs\CheckUrls;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\Verdict;
use johnhenry\linkaudit\queue\ChunkedUrlBatcher;
use johnhenry\linkaudit\queue\ElementKeysetBatcher;
use johnhenry\linkaudit\records\ReferenceRecord;
use johnhenry\linkaudit\records\ScanRecord;
use johnhenry\linkaudit\records\UrlRecord;

// ---------------------------------------------------------------------------
// The report tells the truth about the content
//
// A link removed from a block has to leave the report, a link saved during a
// scan has to survive it, a setting switched back has to take effect, and the
// scan has to read every element even when some vanish mid-run. Each of these
// pins one way the report could drift from the content it describes.
//
// Helper names carry a `ri` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/** How many reference rows point at a URL. */
function riReferenceCount(string $url): int
{
    return (int)(new Query())
        ->from(['r' => ReferenceRecord::tableName()])
        ->innerJoin(['u' => UrlRecord::tableName()], '[[u.id]] = [[r.urlId]]')
        ->where(['u.urlHash' => UrlNormaliser::hash($url)])
        ->count();
}

/** The primary site's id. */
function riSiteId(): int
{
    return (int)Craft::$app->getSites()->getPrimarySite()->id;
}

/** Reads one entry the way a scan does. */
function riExtract(Entry $entry, ?int $scanId = null): void
{
    LinkAudit::getInstance()->getScanService()->extractElement((int)$entry->id, Entry::class, riSiteId(), $scanId);
}

/** A scan row with the given mode, status and start time. */
function riScan(ScanMode $mode, ScanStatus $status, ?DateTime $started = null, ?int $siteId = null): int
{
    $scan = new ScanRecord([
        'siteId' => $siteId,
        'mode' => $mode->value,
        'status' => $status->value,
        'dateStarted' => $started !== null ? Db::prepareDateForDb($started) : null,
    ]);
    $scan->save(false);

    return (int)$scan->id;
}

it('drops a link removed from inside a Matrix block', function() {
    $entry = laEntry();
    $entry->setFieldValue('laBlocks', [
        'new1' => ['type' => 'laBlock', 'fields' => ['laBody' => '<p><a href="https://example.com/block-gone">Gone</a></p>']],
    ]);
    Craft::$app->getElements()->saveElement($entry);
    riExtract($entry);

    expect(riReferenceCount('https://example.com/block-gone'))->toBe(1);

    $block = $entry->getFieldValue('laBlocks')->one();
    $block->setFieldValue('laBody', '<p>No link any more.</p>');
    Craft::$app->getElements()->saveElement($block);

    riExtract(Craft::$app->getEntries()->getEntryById((int)$entry->id));

    expect(riReferenceCount('https://example.com/block-gone'))->toBe(0);
});

it('keeps links saved while a full scan was running', function() {
    $service = LinkAudit::getInstance()->getScanService();
    $scanId = riScan(ScanMode::Full, ScanStatus::Checking, new DateTime('-1 hour'));

    // Saved mid-scan: written with no scan id, after the scan started.
    $entry = laEntry();
    $entry->setFieldValue('laBody', '<p><a href="https://example.com/saved-mid-scan">Mid-scan</a></p>');
    Craft::$app->getElements()->saveElement($entry);
    riExtract($entry);

    $service->finalise($scanId, notify: false);

    expect(riReferenceCount('https://example.com/saved-mid-scan'))->toBe(1);
});

it('lets links ignored by a setting come back once the setting changes', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $bySetting = $store->upsert('https://example.com/by-setting-' . bin2hex(random_bytes(4)), true, riSiteId());
    $byPerson = $store->upsert('https://example.com/by-person-' . bin2hex(random_bytes(4)), true, riSiteId());

    $store->recordVerdict($bySetting, new Verdict(status: UrlStatus::Ignored, reason: Verdict::REASON_SETTING));
    $store->recordVerdict($byPerson, new Verdict(status: UrlStatus::Ignored, reason: Verdict::REASON_IGNORED));

    // A later verdict lands on the setting's row, but never on a person's.
    $store->recordVerdict($bySetting, new Verdict(status: UrlStatus::Ok));
    $store->recordVerdict($byPerson, new Verdict(status: UrlStatus::Ok));

    $status = static fn(int $id): string => (string)(new Query())
        ->select(['status'])->from([UrlRecord::tableName()])->where(['id' => $id])->scalar();

    expect($status($bySetting))->toBe(UrlStatus::Ok->value)
        ->and($status($byPerson))->toBe(UrlStatus::Ignored->value);
});

it('puts setting-ignored links back in the queue when settings are saved', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $urlId = $store->upsert('https://example.com/released-' . bin2hex(random_bytes(4)), true, riSiteId());
    $store->recordVerdict($urlId, new Verdict(status: UrlStatus::Ignored, reason: Verdict::REASON_SETTING));

    expect($store->releaseSettingIgnores())->toBeGreaterThanOrEqual(1)
        ->and((string)(new Query())->select(['status'])->from([UrlRecord::tableName()])->where(['id' => $urlId])->scalar())
        ->toBe(UrlStatus::Pending->value);
});

it('reads anchor names that look like numbers as names', function() {
    $document = HtmlParser::document('<html><body><h2 id="2024">Then</h2></body></html>');

    expect(HtmlParser::anchorNames($document))->toBe(['2024']);
});

it('leaves an excluded field unread', function() {
    $entry = laEntry();
    $entry->setFieldValue('laBody', '<p><a href="https://example.com/legacy">Legacy</a></p>');

    LinkAudit::getInstance()->getSettings()->excludedFieldUids = [
        Craft::$app->getFields()->getFieldByHandle('laBody')->uid,
    ];

    expect(laLinksIn(laExtract($entry), 'laBody'))->toBe([]);
});

it('leaves everything inside an excluded Matrix field unread', function() {
    $entry = laEntry();
    $entry->setFieldValue('laBlocks', [
        'new1' => ['type' => 'laBlock', 'fields' => ['laBody' => '<p><a href="https://example.com/in-legacy-block">Old</a></p>']],
    ]);
    Craft::$app->getElements()->saveElement($entry);

    LinkAudit::getInstance()->getSettings()->excludedFieldUids = [
        Craft::$app->getFields()->getFieldByHandle('laBlocks')->uid,
    ];

    expect(laUrls(laExtract($entry)))->not->toContain('https://example.com/in-legacy-block');
});

it('resolves a relative link against the page it sits on', function() {
    $entry = laEntry();
    $entry->setFieldValue('laBody', '<p><a href="sibling-page">Sibling</a></p>');
    Craft::$app->getElements()->saveElement($entry);

    $pageUrl = (string)$entry->getUrl();
    $expected = substr($pageUrl, 0, (int)strrpos($pageUrl, '/') + 1) . 'sibling-page';

    expect(laUrls(laExtract($entry)))->toBe([UrlNormaliser::normalise($expected)]);
});

it('does not count a scheduled entry as a live page', function() {
    $target = laEntry();
    $target->postDate = new DateTime('+1 month');
    Craft::$app->getElements()->saveElement($target);

    $resolver = LinkAudit::getInstance()->getInternalResolver();

    $relation = $resolver->resolveElement((int)$target->id, Entry::class, riSiteId(), isRelation: true);

    expect($relation?->status)->toBe(UrlStatus::Broken)
        ->and($relation?->message)->toContain('not live')
        // The address isn't answered from the database, so the server is asked.
        ->and($resolver->resolveUrl((string)UrlNormaliser::normalise((string)$target->getUrl()), riSiteId()))->toBeNull();
});

it('counts the incremental cut-off per site', function() {
    Db::delete(ScanRecord::tableName());

    $sites = Craft::$app->getSites()->getAllSiteIds();
    $started = new DateTime('-1 day');
    riScan(ScanMode::Full, ScanStatus::Complete, $started, $sites[0]);

    $service = LinkAudit::getInstance()->getScanService();

    expect($service->lastCompletedScanStart([$sites[0]]))->not->toBeNull();

    if (count($sites) > 1) {
        expect($service->lastCompletedScanStart($sites))->toBeNull();
    }
});

it('does not skip an element when one before the cursor disappears', function() {
    $entries = [laEntry(), laEntry(), laEntry()];
    $ids = array_map(static fn(Entry $e): int => (int)$e->id, $entries);
    $service = LinkAudit::getInstance()->getScanService();

    $first = new ElementKeysetBatcher($service->elementQuery([riSiteId()], null, $ids));
    $seen = array_map(static fn(array $row): int => (int)$row['elementId'], iterator_to_array($first->getSlice(0, 2), false));
    [$cursorSite, $cursorElement] = $first->getCursor();

    Craft::$app->getElements()->deleteElement($entries[0]);

    $second = new ElementKeysetBatcher($service->elementQuery([riSiteId()], null, $ids), $cursorSite, $cursorElement);
    $rest = array_map(static fn(array $row): int => (int)$row['elementId'], iterator_to_array($second->getSlice(2, 2), false));

    expect(array_merge($seen, $rest))->toBe($ids);
});

it('brings links to a deleted page forward for a check', function() {
    $target = laEntry();
    $url = (string)UrlNormaliser::normalise((string)$target->getUrl());
    $store = LinkAudit::getInstance()->getUrlStore();
    $urlId = $store->upsert($url, true, riSiteId());
    $store->recordVerdict($urlId, new Verdict(status: UrlStatus::Ok));

    Craft::$app->getElements()->deleteElement($target);

    $next = (new Query())->select(['nextCheckAfter'])->from([UrlRecord::tableName()])->where(['id' => $urlId])->scalar();

    expect(DateTimeHelper::toDateTime($next) <= DateTimeHelper::now())->toBeTrue();
});

it('checks only the page\'s own URLs when one page is rechecked', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $mine = $store->upsert('https://example.com/mine-' . bin2hex(random_bytes(4)), false);
    $store->upsert('https://example.com/someone-elses-' . bin2hex(random_bytes(4)), false);

    $job = new CheckUrls(['urlIds' => [$mine], 'notify' => false]);
    $batcher = (new ReflectionMethod($job, 'loadData'))->invoke($job);
    assert($batcher instanceof ChunkedUrlBatcher);

    $rows = array_merge([], ...iterator_to_array($batcher->getSlice(0, 10), false));

    expect(array_map(static fn(array $row): int => (int)$row['id'], $rows))->toBe([$mine]);
});

it('stops every running scan, not just the newest', function() {
    $older = riScan(ScanMode::Full, ScanStatus::Checking, new DateTime('-10 minutes'));
    $newer = riScan(ScanMode::CheckOnly, ScanStatus::Checking, new DateTime('-1 minute'));

    LinkAudit::getInstance()->getScanService()->cancelScan();

    $status = static fn(int $id): string => (string)(new Query())
        ->select(['status'])->from([ScanRecord::tableName()])->where(['id' => $id])->scalar();

    expect($status($older))->toBe(ScanStatus::Cancelled->value)
        ->and($status($newer))->toBe(ScanStatus::Cancelled->value);
});

it('marks a scan failed only while it is still running', function() {
    $service = LinkAudit::getInstance()->getScanService();
    $running = riScan(ScanMode::Full, ScanStatus::Extracting, new DateTime());
    $done = riScan(ScanMode::Full, ScanStatus::Complete, new DateTime('-1 day'));

    $service->markFailed($running);
    $service->markFailed($done);

    expect($service->getScan($running)['status'])->toBe(ScanStatus::Failed->value)
        ->and($service->getScan($done)['status'])->toBe(ScanStatus::Complete->value);
});

it('stops polling a scan whose worker died', function() {
    $scanId = riScan(ScanMode::Full, ScanStatus::Checking, new DateTime('-3 hours'));
    Db::update(ScanRecord::tableName(), [
        'dateUpdated' => Db::prepareDateForDb(new DateTime('-2 hours')),
    ], ['id' => $scanId], updateTimestamp: false);

    expect(LinkAudit::getInstance()->getReportService()->runningScan()['id'] ?? null)->not->toBe($scanId);
});

it('treats a login wall as unverifiable rather than broken', function() {
    $verdict = linkAuditChecker([new Response(401), new Response(401)])->check('https://example.com/members');

    expect($verdict->status)->toBe(UrlStatus::Blocked);
});

it('confirms a HEAD 404 with a GET before calling the link broken', function() {
    $verdict = linkAuditChecker([new Response(404), new Response(200)])->check('https://example.com/head-hater');

    expect($verdict->status)->toBe(UrlStatus::Ok)
        ->and($verdict->method)->toBe('get');
});

it('still reads an excluded URI pattern saved with a site id', function() {
    $settings = LinkAudit::getInstance()->getSettings();
    $settings->excludedUriPatterns = [
        ['enabled' => true, 'siteId' => (string)riSiteId(), 'uriPattern' => '^legacy-row'],
    ];

    $scans = LinkAudit::getInstance()->getScanService();

    expect($scans->isUriExcluded('legacy-row/page', riSiteId()))->toBeTrue()
        ->and($scans->isUriExcluded('other/page', riSiteId()))->toBeFalse();
});
