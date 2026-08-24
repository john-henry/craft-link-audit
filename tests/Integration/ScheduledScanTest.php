<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\services\Gc;
use johnhenry\linkaudit\enums\ScanMode;
use johnhenry\linkaudit\enums\ScanStatus;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\records\ScanRecord;

// ---------------------------------------------------------------------------
// The fallback schedule
//
// Every test here starts by emptying the scans table. The schedule is decided
// entirely by what the last scan row says, and a development database carries
// whatever the last real scan left behind.
//
// Helper names carry a `sched` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/** Empties the scans table, so a test decides the whole history itself. */
function schedNoScans(): void
{
    Db::delete(ScanRecord::tableName(), []);
}

/** A scan row with its clock set by hand. */
function schedScan(ScanMode $mode, ScanStatus $status, string $startedAgo, ?string $touchedAgo = null): int
{
    $scan = new ScanRecord([
        'mode' => $mode->value,
        'status' => $status->value,
        'dateStarted' => Db::prepareDateForDb(DateTimeHelper::now()->modify($startedAgo)),
    ]);
    $scan->save(false);

    // Written straight to the row, since saving the record would put the
    // timestamps back to now.
    Db::update(ScanRecord::tableName(), [
        'dateUpdated' => Db::prepareDateForDb(DateTimeHelper::now()->modify($touchedAgo ?? $startedAgo)),
    ], ['id' => $scan->id], [], false);

    return (int)$scan->id;
}

/** How many scan rows there are. */
function schedScanCount(): int
{
    return (int)(new Query())->from([ScanRecord::tableName()])->count();
}

/** Runs garbage collection's event, the way `craft gc/run` does. */
function schedRunGc(): void
{
    Craft::$app->getGc()->trigger(Gc::EVENT_RUN);
}

/** The most recent scan row. */
function schedLatestScan(): ?array
{
    /** @var array<string, mixed>|null $row */
    $row = (new Query())
        ->from([ScanRecord::tableName()])
        ->orderBy(['id' => SORT_DESC])
        ->one() ?: null;

    return $row;
}

beforeEach(function() {
    schedNoScans();

    $settings = LinkAudit::getInstance()->getSettings();
    $settings->scheduledScanEnabled = true;
    $settings->scheduledScanIntervalHours = 24;
});

// ---------------------------------------------------------------------------
// When it queues
// ---------------------------------------------------------------------------

it('queues an incremental scan when nothing has run in longer than the interval', function() {
    schedScan(ScanMode::Full, ScanStatus::Complete, '-30 hours');

    schedRunGc();

    expect(schedScanCount())->toBe(2)
        ->and(schedLatestScan()['mode'])->toBe(ScanMode::Incremental->value)
        ->and(schedLatestScan()['status'])->toBe(ScanStatus::Queued->value);
});

it('queues one on an install that has never scanned', function() {
    schedRunGc();

    expect(schedScanCount())->toBe(1)
        ->and(schedLatestScan()['mode'])->toBe(ScanMode::Incremental->value);
});

it('starts scanning again after a worker died mid scan', function() {
    // Left extracting and untouched for days: whatever was working on this is
    // long gone, and one dead worker should not stop the schedule for good.
    schedScan(ScanMode::Full, ScanStatus::Extracting, '-4 days');

    schedRunGc();

    expect(schedScanCount())->toBe(2);
});

// ---------------------------------------------------------------------------
// When it leaves well alone
// ---------------------------------------------------------------------------

it('queues nothing when the last run is still inside the interval', function() {
    schedScan(ScanMode::Full, ScanStatus::Complete, '-2 hours');

    schedRunGc();

    expect(schedScanCount())->toBe(1);
});

it('queues nothing while a scan is still going', function() {
    // Started days ago, so the interval alone would say another is due. It is
    // still running, which is the whole point of the guard.
    schedScan(ScanMode::Full, ScanStatus::Extracting, '-3 days', '-1 minute');

    schedRunGc();

    expect(schedScanCount())->toBe(1);
});

it('queues nothing while a scan is still waiting for a worker', function() {
    schedScan(ScanMode::Incremental, ScanStatus::Queued, '-3 days', '-1 minute');

    schedRunGc();

    expect(schedScanCount())->toBe(1);
});

it('queues nothing at all when the schedule is switched off', function() {
    LinkAudit::getInstance()->getSettings()->scheduledScanEnabled = false;

    schedRunGc();

    expect(schedScanCount())->toBe(0);
});

it('counts the interval from when the last run started, not when it finished', function() {
    // Started 20 hours ago on a 24 hour interval and took most of the day. The
    // finish was an hour ago, so counting from that would say nothing is due for
    // another day, and every long scan would push its successor a day later
    // again.
    $scanId = schedScan(ScanMode::Full, ScanStatus::Complete, '-20 hours');
    Db::update(ScanRecord::tableName(), [
        'dateFinished' => Db::prepareDateForDb(DateTimeHelper::now()->modify('-1 hour')),
    ], ['id' => $scanId], [], false);

    schedRunGc();

    expect(schedScanCount())->toBe(1);

    LinkAudit::getInstance()->getSettings()->scheduledScanIntervalHours = 12;

    schedRunGc();

    expect(schedScanCount())->toBe(2);
});

it('pays no attention to a check only run', function() {
    // It read no content, so it says nothing about when the content was last
    // looked at.
    schedScan(ScanMode::CheckOnly, ScanStatus::Complete, '-1 hour');

    schedRunGc();

    expect(schedScanCount())->toBe(2)
        ->and(schedLatestScan()['mode'])->toBe(ScanMode::Incremental->value);
});
