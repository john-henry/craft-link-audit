<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\linkaudit\jobs\CrawlPages;
use johnhenry\linkaudit\LinkAudit;

// ---------------------------------------------------------------------------
// How long the crawl step says it needs
//
// The check step was given an honest reservation already. This one fetches
// twenty pages one after another, each bounded by the request timeout with the
// politeness delay between them, and asked the queue for nothing: it took the
// default five minutes, which twenty pages at the default twenty seconds
// already goes past.
//
// A job that outlives its reservation is handed to the next worker and begun
// again from the top, so a site slow enough to need the time had the same
// twenty pages crawled over and over while the scan sat still.
//
// Helper names carry a `crawlTtr` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/** The time to run a freshly built crawl job asks for, under the given settings. */
function crawlTtrFor(int $timeout, int $minHostDelayMs = 250): int
{
    $settings = LinkAudit::$plugin->getSettings();
    $settings->timeout = $timeout;
    $settings->minHostDelayMs = $minHostDelayMs;

    return (int)(new CrawlPages())->ttr;
}

beforeEach(function() {
    $settings = LinkAudit::$plugin->getSettings();
    $this->crawlTtrStored = [$settings->timeout, $settings->minHostDelayMs];
});

afterEach(function() {
    $settings = LinkAudit::$plugin->getSettings();
    [$settings->timeout, $settings->minHostDelayMs] = $this->crawlTtrStored;
});

it('asks for longer when each page may take longer', function() {
    expect(crawlTtrFor(60))->toBeGreaterThan(crawlTtrFor(20));
});

it('counts the politeness delay between pages as well', function() {
    expect(crawlTtrFor(20, 2000))->toBeGreaterThan(crawlTtrFor(20, 0));
});

it('covers a whole batch at the settings it was given', function() {
    $batch = (new CrawlPages())->batchSize;

    expect(crawlTtrFor(60))->toBeGreaterThanOrEqual($batch * 60);
});

it('asks for more than the queue would give it by default', function() {
    // The whole point: twenty pages at the default timeout cannot fit in the
    // five minutes Craft reserves, so the default was guaranteed to lapse on
    // any site slow enough to need the time.
    $queueTtr = (int)Craft::$app->getQueue()->ttr;

    expect(crawlTtrFor(20))->toBeGreaterThan($queueTtr);
});

it('never asks for less than the queue is configured to', function() {
    // An install that raised the queue's own ttr did it for a reason.
    expect(crawlTtrFor(1, 0))->toBeGreaterThanOrEqual((int)Craft::$app->getQueue()->ttr);
});
