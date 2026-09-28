<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\linkaudit\jobs\CheckUrls;
use johnhenry\linkaudit\LinkAudit;

// ---------------------------------------------------------------------------
// How long the check step says it needs
//
// Craft hands a job that outlives its time to run to the next worker from the
// start, so the step would make the same requests again and never finish. Each
// chunk stops starting requests once the scheduler's run time is up, so the
// step needs that run time, the waiting budget and the slowest request still
// in flight (a HEAD and a GET, a timeout per redirect hop), per chunk.
//
// Helper names carry a `ttr` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/** The time to run a freshly built check job asks for, under the given settings. */
function ttrFor(int $timeout, int $maxRedirects = 5): int
{
    $settings = LinkAudit::$plugin->getSettings();
    $settings->timeout = $timeout;
    $settings->maxRedirects = $maxRedirects;

    return (int) (new CheckUrls())->ttr;
}

it('asks for longer when each request may take longer', function() {
    expect(ttrFor(60))->toBeGreaterThan(ttrFor(20));
});

it('asks for longer when more redirects are followed', function() {
    expect(ttrFor(20, 10))->toBeGreaterThan(ttrFor(20, 0));
});

it('covers every chunk running to its deadline with a slow request still in flight', function() {
    $scheduler = LinkAudit::$plugin->getRequestScheduler();
    $job = new CheckUrls();
    $slowest = 2 * (5 + 1) * 300;
    $perChunk = $scheduler->maxRunSeconds + $scheduler->maxYieldSeconds + $slowest;

    expect(ttrFor(300, 5))->toBeGreaterThanOrEqual((int) ($job->batchSize * $perChunk));
});

it('never asks for less than the queue would have given it anyway', function() {
    $queueDefault = (int) Craft::$app->getQueue()->ttr;

    expect(ttrFor(1, 0))->toBeGreaterThanOrEqual($queueDefault);
});

it('leaves a time to run it was handed alone', function() {
    // A job configured with one explicitly, or carrying one through
    // serialisation, keeps it.
    expect((new CheckUrls(['ttr' => 1234]))->ttr)->toBe(1234);
});
