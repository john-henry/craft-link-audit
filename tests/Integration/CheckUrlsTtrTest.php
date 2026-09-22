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
// start. For this step that means a hundred URLs asked a second time, and a
// third, and never finishing: the one thing the request scheduler exists to
// avoid, and the reason it bounds its own waiting. Nothing was bounding the
// requests, and the queue's default five minutes is only right for the default
// settings.
//
// A hundred URLs at one request at a time with a five minute timeout is what
// the settings screen allows, and that is eight hours of work handed to a job
// reserved for five minutes.
//
// Helper names carry a `ttr` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/** The time to run a freshly built check job asks for, under the given settings. */
function ttrFor(int $concurrency, int $timeout): int
{
    $settings = LinkAudit::$plugin->getSettings();
    $settings->concurrency = $concurrency;
    $settings->timeout = $timeout;

    return (int) (new CheckUrls())->ttr;
}

it('asks for longer when each request may take longer', function() {
    expect(ttrFor(10, 60))->toBeGreaterThan(ttrFor(10, 20));
});

it('asks for longer when fewer requests run at once', function() {
    expect(ttrFor(1, 60))->toBeGreaterThan(ttrFor(10, 60));
});

it('covers the worst the settings allow', function() {
    // A hundred URLs, one at a time, each allowed five minutes.
    $urls = (new CheckUrls())->batchSize * (new CheckUrls())->chunkSize;

    expect(ttrFor(1, 300))->toBeGreaterThanOrEqual($urls * 300);
});

it('never asks for less than the queue would have given it anyway', function() {
    $queueDefault = (int) Craft::$app->getQueue()->ttr;

    // The fastest settings the screen allows must not tighten the deadline.
    expect(ttrFor(50, 1))->toBeGreaterThanOrEqual($queueDefault);
});

it('leaves a time to run it was handed alone', function() {
    // A job configured with one explicitly, or carrying one through
    // serialisation, keeps it.
    expect((new CheckUrls(['ttr' => 1234]))->ttr)->toBe(1234);
});
