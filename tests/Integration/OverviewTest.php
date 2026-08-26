<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\helpers\Db;
use johnhenry\linkaudit\enums\ScanMode;
use johnhenry\linkaudit\enums\ScanStatus;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\ExtractedLink;
use johnhenry\linkaudit\records\ReferenceRecord;
use johnhenry\linkaudit\records\ScanRecord;
use johnhenry\linkaudit\records\UrlRecord;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// The overview screen and the counts behind it
//
// The counts are the whole point of the page, and they are the thing that goes
// quietly wrong: every one of them is scoped through the references table
// rather than the URL row, because an external URL's row is global and only a
// reference makes it this site's problem.
//
// Helper names carry an `ov` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/** Inserts a URL row and returns its id. */
function ovUrl(array $attributes = []): int
{
    $url = $attributes['url'] ?? 'https://example.com/' . uniqid('', false);

    Db::insert(UrlRecord::tableName(), array_merge([
        'urlHash' => sha1($url),
        'url' => $url,
        'host' => parse_url($url, PHP_URL_HOST) ?: '',
        'scheme' => 'https',
        'siteId' => null,
        'isInternal' => false,
        'status' => UrlStatus::Broken->value,
        'httpStatus' => 404,
        'dateFirstSeen' => Db::prepareDateForDb(new DateTime()),
    ], $attributes));

    return (int)Craft::$app->getDb()->getLastInsertID(UrlRecord::tableName());
}

/** Points an element at a URL row. */
function ovReference(int $urlId, int $elementId, array $attributes = []): int
{
    Db::insert(ReferenceRecord::tableName(), array_merge([
        'urlId' => $urlId,
        'elementId' => $elementId,
        'elementType' => craft\elements\User::class,
        'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
        'source' => ExtractedLink::SOURCE_FIELD,
    ], $attributes));

    return (int)Craft::$app->getDb()->getLastInsertID(ReferenceRecord::tableName());
}

/** An element id that really exists, so the foreign keys hold. */
function ovElementId(): int
{
    return (int)UserFactory::factory()->create()->id;
}

/** The report service. */
function ovReport(): johnhenry\linkaudit\services\ReportService
{
    return LinkAudit::getInstance()->getReportService();
}

/** Empties the plugin's tables, in foreign key order. */
function ovClearTables(): void
{
    $db = Craft::$app->getDb();

    foreach ([ReferenceRecord::tableName(), UrlRecord::tableName(), ScanRecord::tableName()] as $table) {
        $db->createCommand()->delete($table)->execute();
    }
}

beforeEach(function() {
    $this->actingAs(UserFactory::factory()->admin(true)->create());
    $this->siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;

    // The development database this suite runs against carries whatever the last
    // real scan left behind, and RefreshesDatabase rolls back what a test wrote
    // rather than emptying the tables. Clearing them inside the transaction
    // gives each test a known slate, and the rollback puts the real data back.
    ovClearTables();
});

describe('ReportService::verdictCounts', function() {
    it('counts a URL only once, however many places point at it', function() {
        $urlId = ovUrl();
        ovReference($urlId, ovElementId());
        ovReference($urlId, ovElementId());
        ovReference($urlId, ovElementId());

        expect(ovReport()->verdictCounts($this->siteId)['broken'])->toBe(1);
    });

    it('leaves out a URL nothing on this site points at', function() {
        ovUrl();

        expect(ovReport()->verdictCounts($this->siteId)['broken'])->toBe(0);
    });

    it('counts permanent redirects apart from the rest', function() {
        $permanent = ovUrl([
            'status' => UrlStatus::Redirect->value,
            'httpStatus' => 301,
            'redirectPermanent' => true,
        ]);
        $temporary = ovUrl([
            'status' => UrlStatus::Redirect->value,
            'httpStatus' => 302,
            'redirectPermanent' => false,
        ]);
        ovReference($permanent, ovElementId());
        ovReference($temporary, ovElementId());

        $counts = ovReport()->verdictCounts($this->siteId);

        expect($counts['redirect'])->toBe(2)
            ->and($counts['permanentRedirect'])->toBe(1);
    });

    it('answers zero for every verdict on an empty install', function() {
        $counts = ovReport()->verdictCounts($this->siteId);

        foreach (UrlStatus::cases() as $case) {
            expect($counts[$case->value])->toBe(0);
        }
    });
});

describe('ReportService::topHosts', function() {
    it('puts the worst host first', function() {
        foreach (['https://bad.example/a', 'https://bad.example/b', 'https://ok.example/c'] as $url) {
            ovReference(ovUrl(['url' => $url, 'host' => parse_url($url, PHP_URL_HOST)]), ovElementId());
        }

        $hosts = ovReport()->topHosts($this->siteId);

        expect($hosts[0]['host'])->toBe('bad.example')
            ->and($hosts[0]['total'])->toBe(2)
            ->and($hosts[1]['host'])->toBe('ok.example');
    });
});

describe('ReportService::topPages', function() {
    it('counts a nested link against the page that owns it', function() {
        $owner = ovElementId();
        $block = ovElementId();

        ovReference(ovUrl(), $block, ['ownerElementId' => $owner]);
        ovReference(ovUrl(), $owner);

        $pages = ovReport()->topPages($this->siteId);

        expect($pages)->toHaveCount(1)
            ->and($pages[0]['elementId'])->toBe($owner)
            ->and($pages[0]['total'])->toBe(2);
    });
});

describe('ReportService::internalBrokenCount', function() {
    it('counts the broken URLs pointing at this installation, and no others', function() {
        ovReference(ovUrl(['isInternal' => true]), ovElementId());
        ovReference(ovUrl(['isInternal' => false]), ovElementId());
        ovUrl(['isInternal' => true]);

        expect(ovReport()->internalBrokenCount($this->siteId))->toBe(1);
    });
});

describe('ReportService::recentlyBrokenCount', function() {
    it('counts what was working lately, off when it last worked rather than when it last failed', function() {
        // Broke this week: counted.
        ovReference(ovUrl(['dateLastOk' => Db::prepareDateForDb(new DateTime('-2 days'))]), ovElementId());
        // Broken for a month: not fresh, whatever its recent recheck stamped.
        ovReference(ovUrl([
            'dateLastOk' => Db::prepareDateForDb(new DateTime('-30 days')),
            'dateLastBroken' => Db::prepareDateForDb(new DateTime()),
        ]), ovElementId());
        // Never known to work: old rot, not a regression.
        ovReference(ovUrl(), ovElementId());

        expect(ovReport()->recentlyBrokenCount($this->siteId, new DateTime('-7 days')))->toBe(1);
    });
});

describe('ReportService::firstSeenBrokenCount', function() {
    it('counts what the report only just met, and leaves the old rot out', function() {
        // First seen an hour ago: new to the report.
        ovReference(ovUrl(['dateFirstSeen' => Db::prepareDateForDb(new DateTime('-1 hour'))]), ovElementId());
        // Known for a month: whatever it is, it is not news.
        ovReference(ovUrl(['dateFirstSeen' => Db::prepareDateForDb(new DateTime('-30 days'))]), ovElementId());
        // New but working: nothing to start on.
        ovReference(ovUrl([
            'status' => UrlStatus::Ok->value,
            'dateFirstSeen' => Db::prepareDateForDb(new DateTime('-1 hour')),
        ]), ovElementId());

        expect(ovReport()->firstSeenBrokenCount($this->siteId, new DateTime('-1 day')))->toBe(1);
    });
});

describe('ReportService::topBrokenByPlaces', function() {
    it('puts the URL sitting in the most places first, with its count', function() {
        $everywhere = ovUrl(['url' => 'https://bad.example/everywhere']);
        ovReference($everywhere, ovElementId());
        ovReference($everywhere, ovElementId());
        ovReference($everywhere, ovElementId());
        ovReference(ovUrl(['url' => 'https://bad.example/once']), ovElementId());
        ovReference(ovUrl(['url' => 'https://fine.example/', 'status' => UrlStatus::Ok->value]), ovElementId());

        $top = ovReport()->topBrokenByPlaces($this->siteId);

        expect($top)->toHaveCount(2)
            ->and($top[0]['url'])->toBe('https://bad.example/everywhere')
            ->and($top[0]['label'])->toBe('https://bad.example/everywhere')
            ->and($top[0]['places'])->toBe(3)
            ->and($top[1]['places'])->toBe(1);
    });

    it('names a stand-in row by its target element rather than its storage key', function() {
        $target = UserFactory::factory()->create();
        ovReference(ovUrl(['url' => 'element:' . $target->id, 'host' => '']), ovElementId());

        $top = ovReport()->topBrokenByPlaces($this->siteId);
        $expected = Craft::$app->getElements()
            ->getElementById((int)$target->id, null, $this->siteId)
            ?->getUiLabel();

        expect($top[0]['label'])->toBe($expected)
            ->and($top[0]['label'])->not->toContain('element:');
    });
});

describe('The overview screen', function() {
    it('renders with the counts on it', function() {
        $urlId = ovUrl();
        ovReference($urlId, ovElementId());

        Db::insert(ScanRecord::tableName(), [
            'siteId' => $this->siteId,
            'mode' => ScanMode::Full->value,
            'status' => ScanStatus::Complete->value,
            'elementsScanned' => 12,
            'urlsTotal' => 4,
            'urlsChecked' => 4,
            'urlsBroken' => 1,
            'dateStarted' => Db::prepareDateForDb(new DateTime('-2 minutes')),
            'dateFinished' => Db::prepareDateForDb(new DateTime()),
        ]);

        $this->get('admin/link-audit')
            ->assertOk()
            ->assertSee('Hosts giving the most trouble')
            ->assertSee('2 minutes');
    });

    it('offers a starting point while anything is broken, and not otherwise', function() {
        $urlId = ovUrl(['isInternal' => true]);
        ovReference($urlId, ovElementId());

        Db::insert(ScanRecord::tableName(), [
            'siteId' => $this->siteId,
            'mode' => ScanMode::Full->value,
            'status' => ScanStatus::Complete->value,
            'dateStarted' => Db::prepareDateForDb(new DateTime('-1 minute')),
            'dateFinished' => Db::prepareDateForDb(new DateTime()),
        ]);

        $this->get('admin/link-audit')
            ->assertOk()
            ->assertSee('id="link-audit-start"', false)
            ->assertSee('point at your own sites')
            ->assertSee('internal=1', false);

        ovClearTables();
        ovReference(ovUrl(['status' => UrlStatus::Ok->value]), ovElementId());

        Db::insert(ScanRecord::tableName(), [
            'siteId' => $this->siteId,
            'mode' => ScanMode::Full->value,
            'status' => ScanStatus::Complete->value,
            'dateStarted' => Db::prepareDateForDb(new DateTime('-1 minute')),
            'dateFinished' => Db::prepareDateForDb(new DateTime()),
        ]);

        $this->get('admin/link-audit')
            ->assertOk()
            ->assertDontSee('id="link-audit-start"');
    });

    // Every other count on the row is a link to the list behind it, and a number
    // an editor cannot click is a number they cannot do anything about.
    it('sends the No Answer count to its own list', function() {
        ovReference(ovUrl(['status' => UrlStatus::Unreachable->value]), ovElementId());

        Db::insert(ScanRecord::tableName(), [
            'siteId' => $this->siteId,
            'mode' => ScanMode::Full->value,
            'status' => ScanStatus::Complete->value,
            'dateStarted' => Db::prepareDateForDb(new DateTime('-1 minute')),
            'dateFinished' => Db::prepareDateForDb(new DateTime()),
        ]);

        $this->get('admin/link-audit')
            ->assertOk()
            ->assertSee('link-audit/no-answer', false)
            ->assertSee('The pages these links point at have moved for good');
    });

    it('says so plainly when nothing has been scanned', function() {
        $this->get('admin/link-audit')
            ->assertOk()
            ->assertSee('Nothing has been scanned yet');
    });
});
