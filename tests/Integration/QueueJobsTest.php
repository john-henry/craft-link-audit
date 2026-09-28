<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\linkaudit\helpers\QueueJobs;
use johnhenry\linkaudit\jobs\CheckUrls;
use johnhenry\linkaudit\jobs\CrawlPages;
use johnhenry\linkaudit\jobs\ExtractElementLinks;
use johnhenry\linkaudit\jobs\ExtractLinks;
use johnhenry\linkaudit\jobs\ExtractNavigation;
use johnhenry\linkaudit\jobs\FinaliseScan;

// ---------------------------------------------------------------------------
// Finding this plugin's own queued jobs
//
// They are found by looking for the plugin's namespace inside the serialised
// bytes of each queue row, because that is the only thing the queue table holds
// that says whose job it is. Cancelling a scan and uninstalling the plugin both
// depend on it.
//
// The weakness is the obvious one, and the helper's own docblock names it: the
// namespace is written out as a string, so moving a job class out from under it
// would find nothing at all. Nothing would break loudly. Cancel would report
// that it cancelled nothing, uninstall would leave every job sitting there, and
// neither would say why.
//
// Helper names carry a `queueJobs` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/** The namespace the helper searches the serialised bytes for. */
function queueJobsNamespace(): string
{
    return (string) (new ReflectionClass(QueueJobs::class))->getConstant('_NAMESPACE');
}

it('searches for a namespace every one of the plugin\'s jobs is actually under', function() {
    $namespace = queueJobsNamespace();

    expect($namespace)->not->toBe('');

    foreach ([
        CheckUrls::class,
        CrawlPages::class,
        ExtractElementLinks::class,
        ExtractLinks::class,
        ExtractNavigation::class,
        FinaliseScan::class,
    ] as $job) {
        expect($job)->toStartWith($namespace);
    }
});

it('finds that namespace in the bytes a queued job is actually stored as', function() {
    // The class name has to survive serialisation in a form the search can see:
    // a match against the source is not a match against what lands in the row.
    $serialised = serialize(new CheckUrls(['scanId' => 1]));

    expect($serialised)->toContain(queueJobsNamespace());
});

it('does not match a job belonging to something else', function() {
    // Specific enough that the sweep cannot take another plugin's work with it,
    // which matters most on uninstall.
    $serialised = serialize(new stdClass());

    expect($serialised)->not->toContain(queueJobsNamespace());
});

it('counts without throwing on whatever queue this install runs', function() {
    // A queue that is not the database one is skipped rather than erroring, so
    // this has to come back with a number either way.
    expect(QueueJobs::count())->toBeInt();
});
