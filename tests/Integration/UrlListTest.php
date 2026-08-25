<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\helpers\Db;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\ExtractedLink;
use johnhenry\linkaudit\records\ReferenceRecord;
use johnhenry\linkaudit\records\ScanRecord;
use johnhenry\linkaudit\records\UrlRecord;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// The list screens and the endpoint behind them
//
// The size is the point. A real install has thousands of unique URLs, and a
// table that reads them all into memory to show fifty of them is a page that
// works in development and times out in production, so the paging, the sorting
// and the filtering all happen in the database and are asserted here at a size
// worth asserting.
//
// Helper names carry a `list` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/** Empties the plugin's tables, in foreign key order. */
function listClearTables(): void
{
    $db = Craft::$app->getDb();

    foreach ([ReferenceRecord::tableName(), UrlRecord::tableName(), ScanRecord::tableName()] as $table) {
        $db->createCommand()->delete($table)->execute();
    }
}

/**
 * Inserts a batch of URL rows, each with one reference on the primary site.
 *
 * @param array<int, array{url: string, status?: string, host?: string, isInternal?: bool}> $urls
 */
function listSeed(array $urls, int $elementId): void
{
    $now = Db::prepareDateForDb(new DateTime());
    $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
    $urlRows = [];

    foreach ($urls as $url) {
        $urlRows[] = [
            sha1($url['url']),
            $url['url'],
            $url['host'] ?? (string)parse_url($url['url'], PHP_URL_HOST),
            'https',
            null,
            $url['isInternal'] ?? false,
            $url['status'] ?? UrlStatus::Broken->value,
            404,
            $now,
        ];
    }

    Db::batchInsert(UrlRecord::tableName(), [
        'urlHash',
        'url',
        'host',
        'scheme',
        'siteId',
        'isInternal',
        'status',
        'httpStatus',
        'dateFirstSeen',
    ], $urlRows);

    $ids = (new craft\db\Query())
        ->select(['id'])
        ->from([UrlRecord::tableName()])
        ->column();

    $referenceRows = [];

    foreach ($ids as $id) {
        $referenceRows[] = [
            (int)$id,
            $elementId,
            craft\elements\User::class,
            $siteId,
            ExtractedLink::SOURCE_FIELD,
        ];
    }

    Db::batchInsert(ReferenceRecord::tableName(), [
        'urlId',
        'elementId',
        'elementType',
        'siteId',
        'source',
    ], $referenceRows);
}

/** Asks the table endpoint for a page of rows. */
function listTable(array $params = []): array
{
    $query = http_build_query(array_merge([
        'verdict' => UrlStatus::Broken->value,
        'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
    ], $params));

    return (array)test()->http('get', "actions/link-audit/urls/table?$query")
        ->addHeader('Accept', 'application/json')
        ->send()
        ->getJsonContent()
        ->json();
}

beforeEach(function() {
    $this->actingAs(UserFactory::factory()->admin(true)->create());
    listClearTables();
});

describe('The table endpoint', function() {
    beforeEach(function() {
        $urls = [];

        for ($i = 1; $i <= 1000; $i++) {
            $urls[] = ['url' => sprintf('https://host%02d.example/page-%04d', $i % 20, $i)];
        }

        $urls[] = ['url' => 'https://needle.example/find-me'];
        $urls[] = ['url' => 'https://host01.example/a-redirect', 'status' => UrlStatus::Redirect->value];

        listSeed($urls, (int)UserFactory::factory()->create()->id);
    });

    it('pages a thousand rows without breaking a sweat', function() {
        $started = microtime(true);
        $json = listTable(['page' => 1, 'per_page' => 50]);
        $elapsed = microtime(true) - $started;

        expect($json['success'])->toBeTrue()
            ->and($json['data'])->toHaveCount(50)
            ->and($json['pagination']['total'])->toBe(1001)
            ->and($elapsed)->toBeLessThan(2.0);
    });

    it('gives a different page of rows for page two', function() {
        $first = listTable(['page' => 1, 'per_page' => 50]);
        $second = listTable(['page' => 2, 'per_page' => 50]);

        $firstIds = array_column($first['data'], 'id');
        $secondIds = array_column($second['data'], 'id');

        expect(array_intersect($firstIds, $secondIds))->toBe([])
            ->and($second['data'])->toHaveCount(50);
    });

    it('labels a stand-in row with its target element rather than its storage key', function() {
        $target = UserFactory::factory()->create();
        listSeed([['url' => 'element:' . $target->id, 'host' => '', 'isInternal' => true]], (int)UserFactory::factory()->create()->id);

        $json = listTable(['search' => 'element:' . $target->id]);
        $expected = Craft::$app->getElements()
            ->getElementById((int)$target->id, null, (int)Craft::$app->getSites()->getPrimarySite()->id)
            ?->getUiLabel();

        expect($json['data'][0]['url']['label'])->toBe($expected)
            ->and($json['data'][0]['url']['full'])->toBe('element:' . $target->id)
            ->and($json['data'][0]['url']['copyable'])->toBeFalse();
    });

    it('sorts by host in both directions', function() {
        $ascending = listTable(['sort' => [['field' => 'host', 'direction' => 'asc']], 'per_page' => 5]);
        $descending = listTable(['sort' => [['field' => 'host', 'direction' => 'desc']], 'per_page' => 5]);

        expect($ascending['data'][0]['host'])->toBe('host00.example')
            ->and($descending['data'][0]['host'])->toBe('needle.example');
    });

    it('filters down to one host', function() {
        $json = listTable(['host' => 'host03.example', 'per_page' => 100]);

        expect($json['pagination']['total'])->toBe(50);

        foreach ($json['data'] as $row) {
            expect($row['host'])->toBe('host03.example');
        }
    });

    it('searches the URL itself', function() {
        $json = listTable(['search' => 'find-me']);

        expect($json['pagination']['total'])->toBe(1)
            ->and($json['data'][0]['url']['full'])->toBe('https://needle.example/find-me');
    });

    it('keeps each verdict to its own screen', function() {
        $broken = listTable();
        $redirects = listTable(['verdict' => UrlStatus::Redirect->value]);

        expect($broken['pagination']['total'])->toBe(1001)
            ->and($redirects['pagination']['total'])->toBe(1);
    });

    it('leaves out a URL nothing on this site points at', function() {
        Craft::$app->getDb()->createCommand()->delete(ReferenceRecord::tableName())->execute();

        expect(listTable()['pagination']['total'])->toBe(0);
    });

    // The endpoint takes the verdict off the query string and falls back to
    // Broken for anything it does not recognise, so a screen asking for a
    // verdict the endpoint will not take reads as an empty list rather than as
    // an error. The No Answer screen is the one that would have gone quiet.
    it('takes unreachable as a verdict in its own right', function() {
        listSeed(
            [['url' => 'https://silent.example/no-answer', 'status' => UrlStatus::Unreachable->value]],
            (int)UserFactory::factory()->create()->id,
        );

        $json = listTable(['verdict' => UrlStatus::Unreachable->value]);

        expect($json['pagination']['total'])->toBe(1)
            ->and($json['data'][0]['url']['full'])->toBe('https://silent.example/no-answer');
    });

    it('carries a link to the detail page on every row', function() {
        $json = listTable(['per_page' => 1]);

        expect($json['data'][0]['url']['detailUrl'])->toContain('link-audit/url')
            ->and($json['data'][0]['url']['detailUrl'])->toContain('hash=');
    });

    it('says which rows may be handed out as a link', function() {
        $json = listTable(['search' => 'find-me']);

        expect($json['data'][0]['url']['openable'])->toBeTrue();
    });

    it('still sorts by the reference count when the reader asks for it', function() {
        $json = listTable([
            'sort' => [['field' => 'refs', 'direction' => 'desc']],
            'per_page' => 5,
        ]);

        $counts = array_column($json['data'], 'refs');
        $sorted = $counts;
        rsort($sorted);

        expect($counts)->toBe($sorted);
    });
});

describe('The row controls', function() {
    beforeEach(function() {
        listSeed([['url' => 'https://example.com/row-controls']], (int)UserFactory::factory()->create()->id);
    });

    it('builds its icon controls the way Craft builds its own', function() {
        // Craft gives `.btn[data-icon]` a 5px trailing margin, to sit an icon
        // off its label, and skips it when the button holds nothing at all. A
        // hidden span inside is still something, so an icon-only button built
        // that way comes out lopsided. The label goes on `aria-label` and the
        // button is left empty, which is what Craft's own copytext button does.
        $this->get('admin/link-audit/broken')
            ->assertOk()
            // Both controls close immediately after their `aria-label`, so
            // neither carries a child node for Craft to space the icon off.
            ->assertSee('aria-label="\' + copyLabel + \'"></button>', false)
            ->assertSee('aria-label="\' + openLabel + \'"></a>', false);
    });

    it('wires a busy state and a notice onto the row actions', function() {
        $this->get('admin/link-audit/broken')
            ->assertOk()
            ->assertSee('spinner spinner-absolute', false)
            ->assertSee("classList.toggle('loading', busy)", false)
            ->assertSee('Craft.cp.displayNotice(outcomeOf(data))', false)
            ->assertSee('Craft.cp.displayError(', false)
            // The page is not thrown away underneath the notice that has just
            // been put on it.
            ->assertSee('table.reload();', false)
            ->assertDontSee('window.location.reload()', false);
    });

    it('gives the rows the same vertical rhythm Craft gives its own tables', function() {
        $this->get('admin/link-audit/broken')
            ->assertOk()
            ->assertSee('#link-audit-table-wrap table.data tbody td { padding-block: var(--m); }', false);
    });
});

describe('The code column', function() {
    it('carries the redirect code alongside the final one', function() {
        $url = 'https://example.com/moved-for-good';

        listSeed(
            [['url' => $url, 'status' => UrlStatus::Redirect->value]],
            (int)UserFactory::factory()->create()->id,
        );

        Db::update(UrlRecord::tableName(), [
            'httpStatus' => 200,
            'finalUrl' => 'https://example.com/the-new-place',
            'redirectCount' => 1,
            'redirectPermanent' => true,
            'redirectStatus' => 301,
        ], ['urlHash' => sha1($url)]);

        $row = listTable(['verdict' => UrlStatus::Redirect->value])['data'][0];

        expect($row['code']['status'])->toBe(200)
            ->and($row['code']['redirectStatus'])->toBe(301);
    });

    // Every row written before the redirect code was kept carries none, and
    // there is no backfill: it fills itself in on the next check.
    it('leaves the redirect code out when the row never held one', function() {
        listSeed([['url' => 'https://example.com/plain-404']], (int)UserFactory::factory()->create()->id);

        $row = listTable()['data'][0];

        expect($row['code']['status'])->toBe(404)
            ->and($row['code']['redirectStatus'])->toBeNull();
    });

    it('draws both codes in the one cell, and centres the column', function() {
        listSeed(
            [['url' => 'https://example.com/redirect-markup', 'status' => UrlStatus::Redirect->value]],
            (int)UserFactory::factory()->create()->id,
        );

        $this->get('admin/link-audit/redirects')
            ->assertOk()
            ->assertSee('if (!value.redirectStatus) {', false)
            ->assertSee('link-audit-code-pair', false)
            ->assertSee('codeBadge(value.redirectStatus, value.redirectTitle)', false)
            ->assertSee("Craft.t('link-audit', 'Code')", false)
            ->assertSee("titleClass: 'centeralign'", false)
            ->assertSee("dataClass: 'centeralign'", false);
    });
});

describe('The default sort', function() {
    // The reference count is a correlated subquery, so sorting on it costs the
    // whole matching set every time a list is drawn. The last checked date is an
    // indexed column, and it is the more useful answer anyway: the most recently
    // checked URLs are the ones a reader has just made work for themselves.
    it('is the last checked date, newest first', function() {
        listSeed([
            ['url' => 'https://sorted.example/oldest'],
            ['url' => 'https://sorted.example/middle'],
            ['url' => 'https://sorted.example/newest'],
        ], (int)UserFactory::factory()->create()->id);

        $dates = [
            'https://sorted.example/oldest' => '-3 days',
            'https://sorted.example/middle' => '-2 days',
            'https://sorted.example/newest' => '-1 day',
        ];

        foreach ($dates as $url => $modifier) {
            Db::update(UrlRecord::tableName(), [
                'dateLastChecked' => Db::prepareDateForDb((new DateTime())->modify($modifier)),
            ], ['urlHash' => sha1($url)]);
        }

        $rows = LinkAudit::getInstance()->getReportService()->urlTable(
            UrlStatus::Broken,
            (int)Craft::$app->getSites()->getPrimarySite()->id,
        )['rows'];

        expect(array_column($rows, 'url'))->toBe([
            'https://sorted.example/newest',
            'https://sorted.example/middle',
            'https://sorted.example/oldest',
        ]);
    });
});

describe('ReportService::urlCount', function() {
    it('agrees with what the table endpoint pages by', function() {
        $urls = [];

        for ($i = 1; $i <= 40; $i++) {
            $urls[] = ['url' => sprintf('https://counted%02d.example/page', $i)];
        }

        $urls[] = ['url' => 'https://counted00.example/a-redirect', 'status' => UrlStatus::Redirect->value];

        listSeed($urls, (int)UserFactory::factory()->create()->id);

        $report = LinkAudit::getInstance()->getReportService();
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;

        expect($report->urlCount(UrlStatus::Broken, $siteId))->toBe(40)
            ->and($report->urlCount(UrlStatus::Broken, $siteId))
            ->toBe($report->urlTable(UrlStatus::Broken, $siteId, [], 1, 10)['total'])
            ->and($report->urlCount(UrlStatus::Redirect, $siteId))->toBe(1);
    });

    it('counts what the filters leave, not what the table holds', function() {
        listSeed([
            ['url' => 'https://filtered.example/one'],
            ['url' => 'https://filtered.example/two'],
            ['url' => 'https://elsewhere.example/three'],
        ], (int)UserFactory::factory()->create()->id);

        $report = LinkAudit::getInstance()->getReportService();
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;

        expect($report->urlCount(UrlStatus::Broken, $siteId, ['host' => 'filtered.example']))->toBe(2);
    });

    it('splits your own sites from other websites when asked to', function() {
        listSeed([
            ['url' => 'https://this-site.example/own-page', 'isInternal' => true],
            ['url' => 'https://elsewhere.example/theirs'],
        ], (int)UserFactory::factory()->create()->id);

        $report = LinkAudit::getInstance()->getReportService();
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;

        expect($report->urlCount(UrlStatus::Broken, $siteId, ['internal' => '1']))->toBe(1)
            ->and($report->urlCount(UrlStatus::Broken, $siteId, ['internal' => '0']))->toBe(1)
            ->and($report->urlCount(UrlStatus::Broken, $siteId))->toBe(2)
            ->and($report->urlTable(UrlStatus::Broken, $siteId, ['internal' => '1'], 1, 10)['rows'][0]['url'])
            ->toBe('https://this-site.example/own-page');
    });

    it('counts nothing when nothing on this site points at any of it', function() {
        listSeed([['url' => 'https://orphaned.example/one']], (int)UserFactory::factory()->create()->id);

        Craft::$app->getDb()->createCommand()->delete(ReferenceRecord::tableName())->execute();

        expect(LinkAudit::getInstance()->getReportService()->urlCount(
            UrlStatus::Broken,
            (int)Craft::$app->getSites()->getPrimarySite()->id,
        ))->toBe(0);
    });
});

describe('The list screens', function() {
    it('renders the broken list with its explanation', function() {
        listSeed([['url' => 'https://example.com/gone']], (int)UserFactory::factory()->create()->id);

        $this->get('admin/link-audit/broken')
            ->assertOk()
            ->assertSee('Each one is a link somebody clicks and lands nowhere');
    });

    it('renders the redirects list', function() {
        listSeed(
            [['url' => 'https://example.com/moved', 'status' => UrlStatus::Redirect->value]],
            (int)UserFactory::factory()->create()->id,
        );

        $this->get('admin/link-audit/redirects')
            ->assertOk()
            ->assertSee('your content is still carrying the old address');
    });

    it('explains what unverifiable means rather than calling it broken', function() {
        listSeed(
            [['url' => 'https://example.com/blocked', 'status' => UrlStatus::Blocked->value]],
            (int)UserFactory::factory()->create()->id,
        );

        $this->get('admin/link-audit/blocked')
            ->assertOk()
            ->assertSee('These are not broken');
    });

    it('gives the unreachable ones a screen of their own', function() {
        listSeed(
            [['url' => 'https://example.com/no-answer', 'status' => UrlStatus::Unreachable->value]],
            (int)UserFactory::factory()->create()->id,
        );

        $this->get('admin/link-audit/no-answer')
            ->assertOk()
            ->assertSee('These did not answer when they were asked');
    });

    it('shows an empty state rather than an empty table', function() {
        $this->get('admin/link-audit/broken')
            ->assertOk()
            ->assertSee('Nothing broken on this site');
    });
});

describe('The ignored screen', function() {
    it('shows an empty state when nothing has been ignored', function() {
        Craft::$app->getDb()->createCommand()
            ->delete(johnhenry\linkaudit\records\IgnoreRecord::tableName())
            ->execute();

        $this->get('admin/link-audit/ignored')
            ->assertOk()
            ->assertSee('Nothing has been ignored');
    });

    it('lists an ignored URL with its note and a way back', function() {
        $store = LinkAudit::getInstance()->getUrlStore();
        $url = 'https://example.com/list-me-as-ignored';
        $urlId = $store->upsert($url, false);

        $store->replaceReferencesFor(
            (int)UserFactory::factory()->create()->id,
            (int)Craft::$app->getSites()->getPrimarySite()->id,
            [['urlId' => $urlId, 'elementType' => craft\elements\User::class]],
        );

        LinkAudit::getInstance()->getIgnoreService()->ignoreUrl(
            johnhenry\linkaudit\helpers\UrlNormaliser::hash($url),
            'The client knows about this one.',
        );

        $this->get('admin/link-audit/ignored')
            ->assertOk()
            ->assertSee($url)
            ->assertSee('The client knows about this one.')
            ->assertSee('Restore');
    });
});
