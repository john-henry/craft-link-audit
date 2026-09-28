<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\db\Query;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\Verdict;
use johnhenry\linkaudit\records\UrlRecord;

// ---------------------------------------------------------------------------
// Two workers writing a verdict for the same URL
//
// The fail count is read, added to, and written back. Nothing claims the row
// first, and more than one thing writes here: a scheduled scan, the rendered
// crawl, and somebody pressing Check again. Two of them landing together on the
// same URL could each write a count worked out from what they read before the
// other wrote, and the URL then crosses into broken a check later than it
// should have.
//
// The write is conditional on the row still holding what was read, and the
// number of rows it touched says whether it did. An expression would be the
// usual answer for a counter, the way ScanService::_increment() moves its own,
// but the status written here is decided by the count it lands on, so the new
// value has to be known in PHP rather than only inside the statement.
//
// The interleaving itself is not reproducible here: a test runs in one process
// and cannot stop the method between its read and its write. What is asserted
// is the shape that makes the interleaving safe, plus the ordinary counting
// behaviour, which TtlTest covers in more depth and which the rewrite had to
// leave alone.
//
// Helper names carry a `race` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/** A verdict saying the URL could not be reached. */
function raceUnreachable(): Verdict
{
    return new Verdict(status: UrlStatus::Unreachable, reason: Verdict::REASON_CONNECT);
}

/** A verdict saying the URL answered. */
function raceOk(): Verdict
{
    return new Verdict(status: UrlStatus::Ok, httpStatus: 200);
}

/** The stored row for a URL. */
function raceRow(int $urlId): array
{
    return (new Query())
        ->select(['failCount', 'status'])
        ->from([UrlRecord::tableName()])
        ->where(['id' => $urlId])
        ->one() ?: [];
}

it('conditions the write on the row still holding what it read', function() {
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/services/UrlStore.php');

    preg_match('/public function recordVerdict\(.*?\n    \}/s', $source, $m);
    $body = $m[0] ?? '';

    expect($body)->not->toBeEmpty();

    // Both read values belong in the condition. The status matters as much as
    // the count: it is what decides whether this write is the one that stamps
    // dateLastBroken, and an ignore recorded in between must not be written
    // over.
    expect($body)->toContain("'failCount' => (int)\$row['failCount']")
        ->and($body)->toContain("'status' => (string)\$row['status']")
        ->and($body)->toContain('Db::update(');
});

it('reads again rather than writing its stale count a second time', function() {
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/services/UrlStore.php');

    preg_match('/public function recordVerdict\(.*?\n    \}/s', $source, $m);
    $body = $m[0] ?? '';

    // The read has to sit inside the loop: retrying with the same stale values
    // would fail the same way for ever.
    $loopAt = strpos($body, 'for ($attempt');
    $readAt = strpos($body, "->select(['failCount', 'status', 'reason'])");

    expect($loopAt)->not->toBeFalse()
        ->and($readAt)->not->toBeFalse()
        ->and($loopAt)->toBeLessThan($readAt);
});

it('still records the verdict when the row keeps moving', function() {
    // A URL nobody can record a verdict for is worse than a count one behind,
    // so the last attempt drops the condition rather than giving up.
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/services/UrlStore.php');

    preg_match('/public function recordVerdict\(.*?\n    \}/s', $source, $m);

    expect($m[0] ?? '')->toContain(": ['id' => \$urlId]");
});

it('counts consecutive failures the same way it always did', function() {
    // The rewrite restructured the method around a loop. This is the contract
    // it had to keep: TtlTest covers the thresholds, this covers the counting.
    $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
    $store = LinkAudit::$plugin->getUrlStore();

    $urlId = $store->upsert('https://example.com/verdict-race', false, $siteId, UrlStatus::Pending);

    $store->recordVerdict($urlId, raceUnreachable());
    expect((int) raceRow($urlId)['failCount'])->toBe(1);

    $store->recordVerdict($urlId, raceUnreachable());
    expect((int) raceRow($urlId)['failCount'])->toBe(2);

    $store->recordVerdict($urlId, raceOk());
    expect((int) raceRow($urlId)['failCount'])->toBe(0);
});
