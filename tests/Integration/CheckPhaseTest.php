<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use johnhenry\linkaudit\enums\ScanMode;
use johnhenry\linkaudit\enums\ScanStatus;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\jobs\CheckUrls;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\ExtractedLink;
use johnhenry\linkaudit\models\Verdict;
use johnhenry\linkaudit\queue\ChunkedUrlBatcher;
use johnhenry\linkaudit\records\ScanRecord;
use johnhenry\linkaudit\records\UrlRecord;
use johnhenry\linkaudit\services\HttpChecker;
use markhuot\craftpest\factories\Entry as EntryFactory;
use Psr\Http\Message\RequestInterface;
use yii\queue\sync\Queue as SyncQueue;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Points the shared request scheduler at a handler under the test's control,
 * and records every URL it was asked for.
 *
 * The job reaches for the scheduler component rather than being handed one, so
 * this is where a test gets between it and the internet.
 *
 * @param array<int, string> $sent Every request URL, in the order it went out.
 */
function laMockChecker(callable $responder, array &$sent): void
{
    $handler = function(RequestInterface $request) use (&$sent, $responder): PromiseInterface {
        $sent[] = (string)$request->getUri();

        $promise = new Promise(function() use (&$promise, $request, $responder) {
            $result = $responder($request);

            if ($result instanceof Throwable) {
                $promise->reject($result);

                return;
            }

            $promise->resolve($result);
        });

        return $promise;
    };

    LinkAudit::getInstance()->getRequestScheduler()->setChecker(new HttpChecker([
        'client' => new Client(['handler' => HandlerStack::create($handler)]),
    ]));
}

/**
 * An entry in the site's `collection` section, whose `Work` entry type has no
 * URL format on the primary site, so the check phase's recheck path has a real
 * "exists, enabled, no URL" target to re-resolve a relation and a link field
 * stand-in row against.
 */
function laCheckNoUrlEntry(): Entry
{
    $section = Craft::$app->getEntries()->getSectionByHandle('collection');

    if ($section === null) {
        throw new RuntimeException(
            'The collection section is missing. Run `ddev craft project-config/apply`.',
        );
    }

    return EntryFactory::factory()
        ->section($section)
        ->title('Check phase no-URL fixture ' . StringHelper::randomString(10))
        ->create();
}

/** A scan row for the check phase to count against. */
function laCheckScan(): int
{
    $scan = new ScanRecord([
        'mode' => ScanMode::CheckOnly->value,
        'status' => ScanStatus::Queued->value,
    ]);
    $scan->save(false);

    return (int)$scan->id;
}

/**
 * Seeds pending URL rows and returns their ids.
 *
 * The host is a real, resolvable one on purpose: the SSRF guard resolves every
 * name before a request is made, so a made-up domain would come back
 * unreachable without the mocked handler ever being asked. The paths are unique
 * per run so that rows a real scan left in the development database cannot be
 * mistaken for these.
 *
 * @return int[]
 */
function laPendingUrls(int $count): array
{
    $store = LinkAudit::getInstance()->getUrlStore();
    $run = uniqid('', true);
    $ids = [];

    for ($i = 1; $i <= $count; $i++) {
        $ids[] = $store->upsert("https://example.com/pending-$run-$i", false);
    }

    return $ids;
}

/**
 * Parks every URL already stored, so the only rows the check phase finds
 * waiting are the ones the test seeded next.
 */
function laQuiesceUrls(): void
{
    Db::update(UrlRecord::tableName(), [
        'status' => UrlStatus::Ok->value,
        'nextCheckAfter' => Db::prepareDateForDb(DateTimeHelper::now()->modify('+30 days')),
    ], '');
}

/**
 * Runs the check job to completion.
 *
 * The batch runner spawns a copy of itself through the queue, which a test has
 * no worker for, so each round starts a fresh instance carrying only the public
 * properties across. That is exactly what serialising a job to the queue does,
 * and it is the only way to prove the cursor really survives the hand-over.
 *
 * The chain is over when the offset has caught the pinned total, which is the
 * same condition the batch runner spawns on. Every other round has to move the
 * offset: a round that stands still is a round the runner would answer by
 * spawning another copy that stands still too, for ever, and this helper used to
 * absorb exactly that by returning quietly instead.
 *
 * @param callable|null $afterRound Called with the job after each round, for a
 *                                  test that wants to change the pending set
 *                                  mid-chain.
 */
function laRunCheck(
    int $scanId,
    int $chunkSize = 25,
    int $batchSize = 4,
    ?callable $afterRound = null,
): CheckUrls {
    $queue = new SyncQueue();
    $job = new CheckUrls([
        'batchSize' => $batchSize,
        'chunkSize' => $chunkSize,
        'scanId' => $scanId,
    ]);

    for ($round = 1; $round <= 200; $round++) {
        $before = $job->itemOffset;
        $job->execute($queue);

        if ($job->itemOffset >= (int)$job->totalChunks) {
            return $job;
        }

        expect($job->itemOffset)->toBeGreaterThan($before);

        if ($afterRound !== null) {
            $afterRound($job);
        }

        $job = new CheckUrls([
            'batchIndex' => $job->batchIndex + 1,
            'batchSize' => $job->batchSize,
            'chunkSize' => $job->chunkSize,
            'cursorId' => $job->cursorId,
            'itemOffset' => $job->itemOffset,
            'scanId' => $job->scanId,
            'totalChunks' => $job->totalChunks,
        ]);
    }

    throw new RuntimeException('The check chain ran 200 rounds without finishing.');
}

/** How many finish jobs are sitting in the queue. */
function laQueuedFinaliseJobs(): int
{
    return (int)(new Query())
        ->from([Table::QUEUE])
        ->where(['like', 'job', 'FinaliseScan'])
        ->count();
}

/** A URL row, read back in full. */
function laCheckUrlRow(int $urlId): array
{
    /** @var array<string, mixed> $row */
    $row = (new Query())
        ->from([UrlRecord::tableName()])
        ->where(['id' => $urlId])
        ->one();

    return $row;
}

/** A scan row, read back in full. */
function laCheckScanRow(int $scanId): array
{
    /** @var array<string, mixed> $row */
    $row = (new Query())
        ->from([ScanRecord::tableName()])
        ->where(['id' => $scanId])
        ->one();

    return $row;
}

// The scheduler is a shared component, so a mocked checker left on it would
// follow the suite into the next file.
afterEach(function() {
    LinkAudit::getInstance()->getRequestScheduler()->setChecker(new HttpChecker());
});

// ---------------------------------------------------------------------------
// The batcher
// ---------------------------------------------------------------------------

it('hands out chunks of URLs rather than one URL at a time', function() {
    $ids = laPendingUrls(12);
    $batcher = new ChunkedUrlBatcher(
        LinkAudit::getInstance()->getUrlStore()->pendingQuery()->andWhere(['id' => $ids]),
        5,
    );

    $chunks = iterator_to_array($batcher->getSlice(0, 10), false);

    expect($batcher->count())->toBe(3)
        ->and($chunks)->toHaveCount(3)
        ->and($chunks[0])->toHaveCount(5)
        ->and($chunks[2])->toHaveCount(2)
        ->and($batcher->getCursorId())->toBe(max($ids));
});

it('carries on above the cursor when earlier rows leave the pending set', function() {
    $ids = laPendingUrls(9);
    $store = LinkAudit::getInstance()->getUrlStore();
    $batcher = new ChunkedUrlBatcher($store->pendingQuery()->andWhere(['id' => $ids]), 3);
    $seen = [];

    // Answering each chunk as it is handed over is what shrinks the set the
    // batcher is paging through. Offset paging would slide the rows it has not
    // seen yet down into positions it has already gone past, and they would
    // never be checked at all.
    foreach ($batcher->getSlice(0, 10) as $chunk) {
        foreach ($chunk as $row) {
            $seen[] = (int)$row['id'];
            $store->recordVerdict((int)$row['id'], new Verdict(status: UrlStatus::Ok, httpStatus: 200));
        }
    }

    sort($seen);
    sort($ids);

    expect($seen)->toBe($ids);
});

// ---------------------------------------------------------------------------
// The job
// ---------------------------------------------------------------------------

it('asks every pending URL exactly once, across every chunk', function() {
    $settings = LinkAudit::getInstance()->getSettings();
    $settings->minHostDelayMs = 0;
    $settings->maxConcurrentPerHost = 5;

    laQuiesceUrls();
    $ids = laPendingUrls(120);

    $sent = [];
    laMockChecker(static fn(): Response => new Response(200), $sent);

    $scanId = laCheckScan();
    laRunCheck($scanId, 25);

    $statuses = array_values(array_unique(array_map(
        static fn(int $id): string => (string)laCheckUrlRow($id)['status'],
        $ids,
    )));

    expect($sent)->toHaveCount(120)
        ->and(array_unique($sent))->toHaveCount(120)
        ->and($statuses)->toBe([UrlStatus::Ok->value])
        ->and(laCheckScanRow($scanId)['status'])->toBe(ScanStatus::Checking->value)
        ->and((int)laCheckScanRow($scanId)['urlsChecked'])->toBe(120);
});

it('counts the broken ones onto the scan as it goes', function() {
    LinkAudit::getInstance()->getSettings()->minHostDelayMs = 0;

    laQuiesceUrls();
    $ids = laPendingUrls(6);
    $sent = [];
    laMockChecker(static fn(): Response => new Response(404), $sent);

    $scanId = laCheckScan();
    laRunCheck($scanId, 3);

    expect((int)laCheckScanRow($scanId)['urlsBroken'])->toBe(6)
        ->and((int)laCheckScanRow($scanId)['urlsChecked'])->toBe(6)
        ->and(laCheckUrlRow($ids[0])['status'])->toBe(UrlStatus::Broken->value);
});

it('leaves a URL the host asked to be left alone still pending, and does not ask again', function() {
    $settings = LinkAudit::getInstance()->getSettings();
    $settings->minHostDelayMs = 0;
    $settings->retryCount = 0;
    // One at a time, so the host's answer lands before a second request to it
    // has already gone out.
    $settings->maxConcurrentPerHost = 1;

    laQuiesceUrls();
    $ids = laPendingUrls(4);
    $sent = [];
    laMockChecker(static fn(): Response => new Response(429, ['Retry-After' => '600']), $sent);

    $scanId = laCheckScan();
    laRunCheck($scanId, 2);

    $row = laCheckUrlRow($ids[0]);
    $nextCheck = DateTimeHelper::toDateTime((string)$row['nextCheckAfter']);

    expect($row['status'])->toBe(UrlStatus::Pending->value)
        ->and($row['dateLastChecked'])->toBeNull()
        ->and($nextCheck)->not->toBeFalse()
        ->and($nextCheck->getTimestamp())->toBeGreaterThan(DateTimeHelper::now()->getTimestamp())
        // The host spoke once for all four, and the run went on past them rather
        // than coming back round to the same rows.
        ->and($sent)->toHaveCount(1)
        ->and((int)laCheckScanRow($scanId)['urlsChecked'])->toBe(0);
});

it('answers an internal URL out of the database rather than over HTTP', function() {
    LinkAudit::getInstance()->getSettings()->minHostDelayMs = 0;

    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $baseUrl = rtrim((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), '/');
    $urlId = LinkAudit::getInstance()->getUrlStore()->upsert(
        $baseUrl . '/nothing-answers-to-this-address',
        true,
        $siteId,
    );

    $sent = [];
    laMockChecker(static fn(): Response => new Response(200), $sent);

    $checked = LinkAudit::getInstance()->getScanService()->checkChunk([laCheckUrlRow($urlId)]);

    // Left pending it would be offered on every pass for ever and settled by
    // none of them, which is what a real scan turned up.
    expect($sent)->toBe([])
        ->and($checked)->toBe(1)
        ->and(laCheckUrlRow($urlId)['status'])->toBe(UrlStatus::Broken->value);
});

it('checks a file-shaped internal URL over HTTP instead of by database lookup', function() {
    LinkAudit::getInstance()->getSettings()->minHostDelayMs = 0;

    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $baseUrl = rtrim((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), '/');
    $urlId = LinkAudit::getInstance()->getUrlStore()->upsert(
        $baseUrl . '/assets/volumes/site/_440xAUTO_crop_center-center_80_none/report.jpg',
        true,
        $siteId,
    );

    $sent = [];
    laMockChecker(static fn(): Response => new Response(200), $sent);

    $checked = LinkAudit::getInstance()->getScanService()->checkChunk([laCheckUrlRow($urlId)]);

    expect($sent)->toHaveCount(1)
        ->and($checked)->toBe(1)
        ->and(laCheckUrlRow($urlId)['status'])->toBe(UrlStatus::Ok->value);
});

it('calls a file-shaped internal URL broken over its HTTP answer, not a database lookup', function() {
    LinkAudit::getInstance()->getSettings()->minHostDelayMs = 0;

    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $baseUrl = rtrim((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), '/');
    $urlId = LinkAudit::getInstance()->getUrlStore()->upsert(
        $baseUrl . '/assets/volumes/site/_440xAUTO_crop_center-center_80_none/missing.jpg',
        true,
        $siteId,
    );

    $sent = [];
    laMockChecker(static fn(): Response => new Response(404), $sent);

    LinkAudit::getInstance()->getScanService()->checkChunk([laCheckUrlRow($urlId)]);

    expect($sent)->toHaveCount(1)
        ->and(laCheckUrlRow($urlId)['status'])->toBe(UrlStatus::Broken->value)
        ->and(laCheckUrlRow($urlId)['reason'])->toBe(Verdict::REASON_HTTP);
});

it('answers a stored element link by looking the element up', function() {
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $urlId = LinkAudit::getInstance()->getUrlStore()->upsert(
        ExtractedLink::syntheticUrl(999999),
        true,
        $siteId,
    );

    $sent = [];
    laMockChecker(static fn(): Response => new Response(200), $sent);

    LinkAudit::getInstance()->getScanService()->checkChunk([laCheckUrlRow($urlId)]);

    expect($sent)->toBe([])
        ->and(laCheckUrlRow($urlId)['status'])->toBe(UrlStatus::Broken->value)
        ->and(laCheckUrlRow($urlId)['reason'])->toBe(Verdict::REASON_NO_ELEMENT);
});

it('recognises a stand-in scheme for an ordinary, small element id, not just a six-figure one', function() {
    // PHP's own URL parser reads a bare "element:1206" as a host and a port
    // rather than a scheme and a path, since 1206 fits in one: this is every
    // element id under 65536, so this is the ordinary case, not the edge one.
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $urlId = LinkAudit::getInstance()->getUrlStore()->upsert(
        ExtractedLink::syntheticUrl(1206),
        true,
        $siteId,
    );

    $sent = [];
    laMockChecker(static fn(): Response => new Response(200), $sent);

    LinkAudit::getInstance()->getScanService()->checkChunk([laCheckUrlRow($urlId)]);

    expect($sent)->toBe([])
        ->and(laCheckUrlRow($urlId)['scheme'])->toBe(ExtractedLink::SYNTHETIC_SCHEME)
        ->and(laCheckUrlRow($urlId)['host'])->toBe('')
        ->and(laCheckUrlRow($urlId)['status'])->toBe(UrlStatus::Broken->value)
        ->and(laCheckUrlRow($urlId)['reason'])->toBe(Verdict::REASON_NO_ELEMENT);
});

it('checks a relation stand-in and a link stand-in for the same URL-less target independently', function() {
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $target = laCheckNoUrlEntry();
    $store = LinkAudit::getInstance()->getUrlStore();

    $relationUrlId = $store->upsert(ExtractedLink::relationSyntheticUrl($target->id), true, $siteId);
    $elementUrlId = $store->upsert(ExtractedLink::syntheticUrl($target->id), true, $siteId);

    // The whole point: a relation and a link field pointing at the same
    // URL-less element must not be able to fight over one row's verdict, so
    // they were never put in the same one.
    expect($relationUrlId)->not->toBe($elementUrlId);

    $sent = [];
    laMockChecker(static fn(): Response => new Response(200), $sent);

    LinkAudit::getInstance()->getScanService()->checkChunk([
        laCheckUrlRow($relationUrlId),
        laCheckUrlRow($elementUrlId),
    ]);

    // Nothing here is an HTTP question, whichever row it landed on.
    expect($sent)->toBe([])
        ->and(laCheckUrlRow($relationUrlId)['status'])->toBe(UrlStatus::Ok->value)
        ->and(laCheckUrlRow($elementUrlId)['status'])->toBe(UrlStatus::Broken->value)
        ->and(laCheckUrlRow($elementUrlId)['reason'])->toBe(Verdict::REASON_NO_ELEMENT);
});

it('stops rather than spawning for ever when the pending set shrinks under it', function() {
    $settings = LinkAudit::getInstance()->getSettings();
    $settings->minHostDelayMs = 0;

    laQuiesceUrls();
    laPendingUrls(12);

    $sent = [];
    laMockChecker(static fn(): Response => new Response(200), $sent);

    $scanId = laCheckScan();
    $finaliseJobs = laQueuedFinaliseJobs();
    $settled = false;

    // Two URLs to a chunk and one chunk to a batch, so the total is pinned at
    // six and the first round accounts for one of them. Everything above the
    // cursor is then settled from outside, the way a concurrent scan, an
    // on-save reread or an author ignoring a URL would settle it. The pinned
    // total still promises five chunks that no longer exist, and every copy the
    // runner spawns to fetch them comes back empty.
    $job = laRunCheck($scanId, 2, 1, function() use (&$settled): void {
        if ($settled) {
            return;
        }

        $settled = true;
        laQuiesceUrls();
    });

    expect($job->totalChunks)->toBe(6)
        ->and($job->itemOffset)->toBeGreaterThanOrEqual(6)
        ->and($sent)->toHaveCount(2)
        // The chain reached its end, so the scan was handed on to be finished
        // rather than left sitting in the check phase for good.
        ->and(laQueuedFinaliseJobs())->toBe($finaliseJobs + 1);
});

it('stops without a fuss when there is nothing waiting to be checked', function() {
    laQuiesceUrls();

    $sent = [];
    laMockChecker(static fn(): Response => new Response(200), $sent);

    $job = laRunCheck(laCheckScan());

    expect($sent)->toBe([])
        ->and($job->itemOffset)->toBe(0)
        ->and($job->totalChunks)->toBe(0);
});
