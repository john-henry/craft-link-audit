<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\db\Query;
use craft\db\QueryBatcher;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use johnhenry\linkaudit\enums\ScanMode;
use johnhenry\linkaudit\enums\ScanStatus;
use johnhenry\linkaudit\jobs\CrawlPages;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\ExtractedLink;
use johnhenry\linkaudit\records\ReferenceRecord;
use johnhenry\linkaudit\records\ScanRecord;
use johnhenry\linkaudit\records\UrlRecord;
use markhuot\craftpest\factories\Entry as EntryFactory;
use Psr\Http\Message\RequestInterface;
use yii\queue\sync\Queue as SyncQueue;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** The prefix every page these tests create sits under. */
const LA_CRAWL_SLUG_PREFIX = 'la-crawl-';

/**
 * Fixture pages, each with a URI of its own so there is something to fetch.
 *
 * The section, its entry types and its fields live in project config rather than
 * being provisioned here: creating a section auto-commits the transaction
 * RefreshesDatabase relies on.
 *
 * @return Entry[]
 */
function laCrawlPages(int $count, string $html = ''): array
{
    $section = Craft::$app->getEntries()->getSectionByHandle('laFixture');

    if ($section === null) {
        throw new RuntimeException(
            'The laFixture test section is missing. Run `ddev craft project-config/apply`.',
        );
    }

    $entries = [];

    for ($i = 0; $i < $count; $i++) {
        $slug = LA_CRAWL_SLUG_PREFIX . StringHelper::toLowerCase(StringHelper::randomString(12));
        $entries[] = EntryFactory::factory()
            ->section($section)
            ->title('LA crawl ' . $slug)
            ->slug($slug)
            ->set('laBody', $html)
            ->create();
    }

    return $entries;
}

/**
 * Points the crawler at a handler under the test's control, and records every
 * page it asked for.
 *
 * @param array<int, string> $sent Every request URL, in the order it went out.
 */
function laCrawlMock(callable $responder, array &$sent): void
{
    $handler = static function(RequestInterface $request) use (&$sent, $responder): PromiseInterface {
        $sent[] = (string)$request->getUri();

        return Create::promiseFor($responder($request));
    };

    LinkAudit::getInstance()->getPageCrawler()->setClient(new Client([
        'handler' => HandlerStack::create($handler),
    ]));
}

/**
 * The reference rows recorded against a page, of one source.
 *
 * @return array<int, array<string, mixed>>
 */
function laCrawlRefs(int $elementId, string $source = ExtractedLink::SOURCE_RENDERED): array
{
    return (new Query())
        ->select(['r.*', 'url' => 'u.url'])
        ->from(['r' => ReferenceRecord::tableName()])
        ->innerJoin(['u' => UrlRecord::tableName()], '[[u.id]] = [[r.urlId]]')
        ->where(['r.elementId' => $elementId, 'r.source' => $source])
        ->orderBy(['u.url' => SORT_ASC])
        ->all();
}

/** A scan row for the crawl to count against. */
function laCrawlScan(): int
{
    $scan = new ScanRecord([
        'mode' => ScanMode::Full->value,
        'status' => ScanStatus::Queued->value,
    ]);
    $scan->save(false);

    return (int)$scan->id;
}

/** A scan row, read back in full. */
function laCrawlScanRow(int $scanId): array
{
    /** @var array<string, mixed> $row */
    $row = (new Query())
        ->from([ScanRecord::tableName()])
        ->where(['id' => $scanId])
        ->one();

    return $row;
}

/** The site ids a crawl covers in these tests: the primary one alone. */
function laCrawlSiteIds(): array
{
    return [Craft::$app->getSites()->getPrimarySite()->id];
}

/** Every queued job's description, oldest first. */
function laQueuedDescriptions(): array
{
    return (new Query())
        ->select(['description'])
        ->from(['{{%queue}}'])
        ->orderBy(['id' => SORT_ASC])
        ->column();
}

/**
 * Runs the crawl job to completion.
 *
 * The batch runner spawns a copy of itself through the queue, which a test has
 * no worker for, so the loop here does the same thing in the same order.
 */
function laRunCrawl(int $scanId, int $batchSize = 20): void
{
    $job = new CrawlPages([
        'batchSize' => $batchSize,
        'scanId' => $scanId,
        'siteIds' => laCrawlSiteIds(),
    ]);

    $total = (int)LinkAudit::getInstance()->getPageCrawler()->pageQuery(laCrawlSiteIds())->count();
    $queue = new SyncQueue();

    do {
        $job->execute($queue);
        $job->batchIndex++;
    } while ($job->itemOffset < $total);
}

beforeEach(function() {
    $settings = LinkAudit::getInstance()->getSettings();
    $settings->renderedCrawlEnabled = true;
    $settings->minHostDelayMs = 0;
    // The development database is full of real pages, and a crawl covers the
    // site rather than a list handed to it. Everything outside this file's own
    // fixtures is excluded, which is the same gate a real install uses to keep
    // checkout and print views out of a scan.
    $settings->excludedUriPatterns = [
        [
            'uriPattern' => '^(?!la-fixture/' . LA_CRAWL_SLUG_PREFIX . ')',
            'siteId' => '',
            'enabled' => true,
        ],
    ];
});

// The crawler is a shared component: a mocked client or a remembered page left
// on it would follow the suite into the next file.
afterEach(function() {
    $crawler = LinkAudit::getInstance()->getPageCrawler();
    $crawler->setClient(new Client());
    $crawler->flush();
});

// ---------------------------------------------------------------------------
// What the crawl finds
// ---------------------------------------------------------------------------

it('records the links a template puts on the page and nobody typed', function() {
    $pages = laCrawlPages(2);
    $sent = [];

    laCrawlMock(static fn(): Response => new Response(200, ['Content-Type' => 'text/html'], <<<'HTML'
        <html><body>
            <footer><a href="https://example.com/hard-coded-footer">Our terms</a></footer>
        </body></html>
        HTML), $sent);

    $scanId = laCrawlScan();
    laRunCrawl($scanId);

    $first = laCrawlRefs((int)$pages[0]->id);
    $second = laCrawlRefs((int)$pages[1]->id);

    expect($sent)->toHaveCount(2)
        ->and($first)->toHaveCount(1)
        ->and($second)->toHaveCount(1)
        ->and($first[0]['url'])->toBe('https://example.com/hard-coded-footer')
        ->and($first[0]['linkText'])->toBe('Our terms')
        ->and($first[0]['fieldHandle'])->toBeNull()
        ->and($first[0]['fieldUid'])->toBeNull()
        ->and((int)$first[0]['scanId'])->toBe($scanId)
        // The dedupe pillar: the same footer on both pages is one URL row.
        ->and((int)$first[0]['urlId'])->toBe((int)$second[0]['urlId'])
        ->and((int)laCrawlScanRow($scanId)['pagesCrawled'])->toBe(2);
});

it('leaves a link the page already accounts for to the field that holds it', function() {
    $shared = 'https://example.com/typed-into-the-body';
    $page = laCrawlPages(1, '<p><a href="' . $shared . '">Typed</a></p>')[0];

    // The field phase first, exactly as a scan runs them.
    LinkAudit::getInstance()->getScanService()->extractElement(
        (int)$page->id,
        null,
        Craft::$app->getSites()->getPrimarySite()->id,
    );

    $sent = [];
    laCrawlMock(static fn(): Response => new Response(200, ['Content-Type' => 'text/html'], <<<HTML
        <html><body>
            <p><a href="$shared">Typed</a></p>
            <footer><a href="https://example.com/only-in-the-template">Terms</a></footer>
        </body></html>
        HTML), $sent);

    laRunCrawl(laCrawlScan());

    $rendered = laCrawlRefs((int)$page->id);
    $fields = laCrawlRefs((int)$page->id, ExtractedLink::SOURCE_FIELD);

    expect($rendered)->toHaveCount(1)
        ->and($rendered[0]['url'])->toBe('https://example.com/only-in-the-template')
        // Field attribution names the field an author has to open, so it wins.
        ->and($fields)->toHaveCount(1)
        ->and($fields[0]['url'])->toBe($shared);
});

it('rebuilds a page\'s rendered rows rather than piling them up', function() {
    $page = laCrawlPages(1)[0];
    $sent = [];

    laCrawlMock(static fn(): Response => new Response(200, ['Content-Type' => 'text/html'], <<<'HTML'
        <html><body><a href="https://example.com/still-here">Still here</a></body></html>
        HTML), $sent);

    laRunCrawl(laCrawlScan());
    laRunCrawl(laCrawlScan());

    expect($sent)->toHaveCount(2)
        ->and(laCrawlRefs((int)$page->id))->toHaveCount(1);
});

it('keeps a page\'s findings when the page will not answer', function() {
    $page = laCrawlPages(1)[0];
    $sent = [];

    laCrawlMock(static fn(): Response => new Response(200, ['Content-Type' => 'text/html'], <<<'HTML'
        <html><body><a href="https://example.com/found-while-it-worked">Found</a></body></html>
        HTML), $sent);

    laRunCrawl(laCrawlScan());
    laCrawlMock(static fn(): Response => new Response(500), $sent);

    $scanId = laCrawlScan();
    laRunCrawl($scanId);

    // A bad deploy is no reason to rub out what was true yesterday.
    expect(laCrawlRefs((int)$page->id))->toHaveCount(1)
        ->and((int)laCrawlScanRow($scanId)['pagesCrawled'])->toBe(0);
});

// ---------------------------------------------------------------------------
// The page cap
// ---------------------------------------------------------------------------

it('stops at the page cap and says how much it left out', function() {
    laCrawlPages(3);

    $crawler = LinkAudit::getInstance()->getPageCrawler();
    $siteIds = laCrawlSiteIds();
    $uncapped = (int)$crawler->uncappedPageQuery($siteIds)->count();

    expect($uncapped)->toBeGreaterThan(3, 'This test needs more pages than the cap it sets.');

    LinkAudit::getInstance()->getSettings()->maxPagesToCrawl = 2;

    // Through the batcher, because that is how the job counts: a plain count
    // pays no attention to the query's own limit.
    expect((new QueryBatcher($crawler->pageQuery($siteIds)))->count())->toBe(2)
        ->and($crawler->reportCappedPages($siteIds))->toBe($uncapped - 2);
});

// ---------------------------------------------------------------------------
// The chain
// ---------------------------------------------------------------------------

it('only puts the crawl in the chain when it has been asked for', function() {
    $service = LinkAudit::getInstance()->getScanService();
    $scanId = laCrawlScan();

    $service->continueAfterExtraction($scanId, laCrawlSiteIds());

    LinkAudit::getInstance()->getSettings()->renderedCrawlEnabled = false;
    $service->continueAfterExtraction($scanId, laCrawlSiteIds());

    $queued = laQueuedDescriptions();

    // The batch runner appends its own "batch 1 of n" to a description, so the
    // phase is what is asserted rather than the whole string.
    expect($queued)->toHaveCount(2)
        ->and($queued[0])->toContain('Reading the links your templates put on the page')
        ->and($queued[1])->toContain('Checking the links found in your content');
});

// ---------------------------------------------------------------------------
// Pruning
// ---------------------------------------------------------------------------

it('does not prune the rendered rows of a page this run never reached', function() {
    $page = laCrawlPages(1)[0];
    $sent = [];

    laCrawlMock(static fn(): Response => new Response(200, ['Content-Type' => 'text/html'], <<<'HTML'
        <html><body><a href="https://example.com/beyond-the-cap">Beyond the cap</a></body></html>
        HTML), $sent);

    laRunCrawl(laCrawlScan());

    // A later run that stopped short of this page, so its rows carry an older
    // scan id than the one finishing. Pruning them would empty the report a page
    // at a time on any site bigger than the cap.
    LinkAudit::getInstance()->getScanService()->finalise(laCrawlScan());

    expect(laCrawlRefs((int)$page->id))->toHaveCount(1);
});

it('clears what the crawl found once the crawl is switched off', function() {
    $page = laCrawlPages(1)[0];
    $sent = [];

    laCrawlMock(static fn(): Response => new Response(200, ['Content-Type' => 'text/html'], <<<'HTML'
        <html><body><a href="https://example.com/template-link">Template</a></body></html>
        HTML), $sent);

    laRunCrawl(laCrawlScan());

    expect(laCrawlRefs((int)$page->id))->toHaveCount(1);

    LinkAudit::getInstance()->getSettings()->renderedCrawlEnabled = false;
    LinkAudit::getInstance()->getScanService()->finalise(laCrawlScan());

    expect(laCrawlRefs((int)$page->id))->toBe([]);
});
