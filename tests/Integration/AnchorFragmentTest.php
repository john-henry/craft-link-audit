<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\Verdict;
use johnhenry\linkaudit\records\ReferenceRecord;
use johnhenry\linkaudit\records\UrlRecord;
use markhuot\craftpest\factories\Entry as EntryFactory;
use Psr\Http\Message\RequestInterface;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** A fixture page, with a URI and a URL of its own. */
function laFragmentPage(string $html = ''): Entry
{
    $section = Craft::$app->getEntries()->getSectionByHandle('laFixture');

    if ($section === null) {
        throw new RuntimeException(
            'The laFixture test section is missing. Run `ddev craft project-config/apply`.',
        );
    }

    $slug = 'la-frag-' . StringHelper::toLowerCase(StringHelper::randomString(12));

    return EntryFactory::factory()
        ->section($section)
        ->title('LA fragment ' . $slug)
        ->slug($slug)
        ->set('laBody', $html)
        ->create();
}

/**
 * Points the crawler at a handler under the test's control.
 *
 * @param array<int, string> $sent Every request URL, in the order it went out.
 */
function laFragmentMock(callable $responder, array &$sent): void
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
 * The URL rows an element's field references point at.
 *
 * @return array<int, array<string, mixed>>
 */
function laFragmentUrls(int $elementId): array
{
    return (new Query())
        ->select(['u.*'])
        ->from(['r' => ReferenceRecord::tableName()])
        ->innerJoin(['u' => UrlRecord::tableName()], '[[u.id]] = [[r.urlId]]')
        ->where(['r.elementId' => $elementId])
        ->orderBy(['u.url' => SORT_ASC])
        ->all();
}

/** Reads one element's links, the way the extract phase does. */
function laFragmentExtract(Entry $entry): void
{
    LinkAudit::getInstance()->getScanService()->extractElement(
        (int)$entry->id,
        null,
        Craft::$app->getSites()->getPrimarySite()->id,
    );
}

/** Settles one URL row, the way the check phase does, and hands it back. */
function laFragmentCheck(array $row): array
{
    LinkAudit::getInstance()->getScanService()->checkChunk([$row]);

    /** @var array<string, mixed> $checked */
    $checked = (new Query())
        ->from([UrlRecord::tableName()])
        ->where(['id' => $row['id']])
        ->one();

    return $checked;
}

beforeEach(function() {
    $settings = LinkAudit::getInstance()->getSettings();
    $settings->renderedCrawlEnabled = true;
    $settings->checkAnchorFragments = true;
    $settings->minHostDelayMs = 0;
});

afterEach(function() {
    $crawler = LinkAudit::getInstance()->getPageCrawler();
    $crawler->setClient(new Client());
    $crawler->flush();
});

// ---------------------------------------------------------------------------
// Anchors that are there, and anchors that are not
// ---------------------------------------------------------------------------

it('calls a link to an anchor that is on the page ok', function() {
    $target = laFragmentPage();
    $source = laFragmentPage('<p><a href="' . $target->getUrl() . '#team">Meet the team</a></p>');

    laFragmentExtract($source);

    $urls = laFragmentUrls((int)$source->id);
    $sent = [];

    laFragmentMock(static fn(): Response => new Response(200, ['Content-Type' => 'text/html'], <<<'HTML'
        <html><body><h2 id="team">The team</h2></body></html>
        HTML), $sent);

    expect($urls)->toHaveCount(1)
        ->and($urls[0]['url'])->toBe($target->getUrl() . '#team')
        // Left for the check phase: only the page's own HTML can answer it, and
        // extraction is no time to be fetching pages.
        ->and($urls[0]['status'])->toBe(UrlStatus::Pending->value);

    $checked = laFragmentCheck($urls[0]);

    expect($checked['status'])->toBe(UrlStatus::Ok->value)
        // Fetched without the fragment: a browser never sends one either.
        ->and($sent)->toBe([$target->getUrl()]);
});

it('calls a link to an anchor that is not on the page broken, and names it', function() {
    $target = laFragmentPage();
    $source = laFragmentPage('<p><a href="' . $target->getUrl() . '#pricing">Pricing</a></p>');

    laFragmentExtract($source);

    $urls = laFragmentUrls((int)$source->id);
    $sent = [];

    laFragmentMock(static fn(): Response => new Response(200, ['Content-Type' => 'text/html'], <<<'HTML'
        <html><body><h2 id="team">The team</h2></body></html>
        HTML), $sent);

    $checked = laFragmentCheck($urls[0]);

    expect($checked['status'])->toBe(UrlStatus::Broken->value)
        ->and($checked['reason'])->toBe(Verdict::REASON_FRAGMENT)
        ->and($checked['message'])->toContain('pricing')
        ->and($sent)->toHaveCount(1);
});

it('reads the older name spelling of an anchor as well as the id', function() {
    $target = laFragmentPage();
    $source = laFragmentPage('<p><a href="' . $target->getUrl() . '#old-style">Old style</a></p>');

    laFragmentExtract($source);

    $sent = [];
    laFragmentMock(static fn(): Response => new Response(200, ['Content-Type' => 'text/html'], <<<'HTML'
        <html><body><a name="old-style"></a></body></html>
        HTML), $sent);

    expect(laFragmentCheck(laFragmentUrls((int)$source->id)[0])['status'])->toBe(UrlStatus::Ok->value);
});

it('answers a link to the top of a page without looking for anything', function() {
    $target = laFragmentPage();
    $source = laFragmentPage('<p><a href="' . $target->getUrl() . '#top">Back to top</a></p>');

    laFragmentExtract($source);

    $sent = [];
    laFragmentMock(static fn(): Response => new Response(200, ['Content-Type' => 'text/html'], <<<'HTML'
        <html><body><p>Nothing on this page carries a name at all.</p></body></html>
        HTML), $sent);

    expect(laFragmentCheck(laFragmentUrls((int)$source->id)[0])['status'])->toBe(UrlStatus::Ok->value);
});

it('does not call a link broken because the page would not answer', function() {
    $target = laFragmentPage();
    $source = laFragmentPage('<p><a href="' . $target->getUrl() . '#team">Meet the team</a></p>');

    laFragmentExtract($source);

    $sent = [];
    laFragmentMock(static fn(): Response => new Response(500), $sent);

    // The database says the page is there. A server having a bad minute is this
    // installation's problem, not the link's.
    expect(laFragmentCheck(laFragmentUrls((int)$source->id)[0])['status'])->toBe(UrlStatus::Ok->value);
});

it('asks the page once however many links point at different anchors on it', function() {
    $target = laFragmentPage();
    $source = laFragmentPage(
        '<p><a href="' . $target->getUrl() . '#team">Team</a>'
        . '<a href="' . $target->getUrl() . '#pricing">Pricing</a></p>',
    );

    laFragmentExtract($source);

    $sent = [];
    laFragmentMock(static fn(): Response => new Response(200, ['Content-Type' => 'text/html'], <<<'HTML'
        <html><body><h2 id="team">Team</h2><h2 id="pricing">Pricing</h2></body></html>
        HTML), $sent);

    $urls = laFragmentUrls((int)$source->id);

    LinkAudit::getInstance()->getScanService()->checkChunk($urls);

    // Two questions about one page, so two rows and one request between them.
    expect($urls)->toHaveCount(2)
        ->and($sent)->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// What is deliberately left alone
// ---------------------------------------------------------------------------

it('still skips a link that is nothing but a fragment', function() {
    $source = laFragmentPage('<p><a href="#section-two">Further down</a></p>');

    laFragmentExtract($source);

    expect(laFragmentUrls((int)$source->id))->toBe([]);
});

it('still takes the fragment off somebody else\'s URL', function() {
    $source = laFragmentPage('<p><a href="https://example.com/handbook#chapter-3">Chapter three</a></p>');

    laFragmentExtract($source);

    $urls = laFragmentUrls((int)$source->id);

    // Nobody can cheaply say what ids another site's pages carry, so the
    // fragment comes off and the page is checked on its own.
    expect($urls)->toHaveCount(1)
        ->and($urls[0]['url'])->toBe('https://example.com/handbook');
});

it('leaves the fragment off altogether when nobody asked for it to be checked', function() {
    LinkAudit::getInstance()->getSettings()->checkAnchorFragments = false;

    $target = laFragmentPage();
    $source = laFragmentPage('<p><a href="' . $target->getUrl() . '#team">Meet the team</a></p>');

    laFragmentExtract($source);

    $urls = laFragmentUrls((int)$source->id);

    expect($urls)->toHaveCount(1)
        ->and($urls[0]['url'])->toBe($target->getUrl())
        // Settled as it was stored, the way every internal link was before.
        ->and($urls[0]['status'])->toBe(UrlStatus::Ok->value);
});

it('leaves the fragment off when the crawl it rides on is switched off', function() {
    LinkAudit::getInstance()->getSettings()->renderedCrawlEnabled = false;

    $target = laFragmentPage();
    $source = laFragmentPage('<p><a href="' . $target->getUrl() . '#team">Meet the team</a></p>');

    laFragmentExtract($source);

    $urls = laFragmentUrls((int)$source->id);

    expect($urls)->toHaveCount(1)
        ->and($urls[0]['url'])->toBe($target->getUrl());
});

it('does not treat a scroll-to-text directive as an anchor', function() {
    $target = laFragmentPage();
    $source = laFragmentPage(
        '<p><a href="' . $target->getUrl() . '#:~:text=the%20team">Straight to it</a></p>',
    );

    laFragmentExtract($source);

    $urls = laFragmentUrls((int)$source->id);

    // It tells the browser to go looking for words. No element carries it as a
    // name, so checking it as one would report a link that works perfectly well.
    expect($urls)->toHaveCount(1)
        ->and($urls[0]['url'])->toBe($target->getUrl());
});
