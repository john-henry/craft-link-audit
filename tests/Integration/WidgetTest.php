<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\web\View;
use johnhenry\linkaudit\enums\ScanStatus;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\records\ReferenceRecord;
use johnhenry\linkaudit\records\ScanRecord;
use johnhenry\linkaudit\records\UrlRecord;
use johnhenry\linkaudit\widgets\BrokenLinksWidget;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// The dashboard widget
//
// A link checker only helps if somebody remembers it is there, so the tile is
// the plugin's only claim on the first screen anybody opens in the morning. It
// has three jobs: say the right number, say nothing before there is anything to
// say, and stay off the dashboard of somebody who may not read the reports.
//
// Helper names carry a `widget` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/** Empties the plugin's tables, in foreign key order. */
function widgetClearTables(): void
{
    $db = Craft::$app->getDb();

    foreach ([ReferenceRecord::tableName(), UrlRecord::tableName(), ScanRecord::tableName()] as $table) {
        $db->createCommand()->delete($table)->execute();
    }
}

/** Stores one settled URL, referenced once from the given site. */
function widgetUrl(string $url, UrlStatus $status, int $siteId, bool $permanent = false): void
{
    $db = Craft::$app->getDb();
    $now = Db::prepareDateForDb(new DateTime('now'));

    $db->createCommand()
        ->insert(UrlRecord::tableName(), [
            'urlHash' => sha1($url),
            'url' => $url,
            'host' => (string)parse_url($url, PHP_URL_HOST),
            'scheme' => 'https',
            'isInternal' => false,
            'status' => $status->value,
            'redirectPermanent' => $status === UrlStatus::Redirect ? $permanent : null,
            'dateFirstSeen' => $now,
            'dateLastChecked' => $now,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])
        ->execute();

    $urlId = (int)$db->getLastInsertID();

    $db->createCommand()
        ->insert(ReferenceRecord::tableName(), [
            'urlId' => $urlId,
            'elementId' => Craft::$app->getUser()->getId(),
            'elementType' => User::class,
            'siteId' => $siteId,
            'source' => 'field',
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])
        ->execute();
}

/** Stores one finished scan, so the widget has a date to report. */
function widgetScan(): void
{
    $now = Db::prepareDateForDb(new DateTime('now'));

    Craft::$app->getDb()->createCommand()
        ->insert(ScanRecord::tableName(), [
            'mode' => 'full',
            'status' => ScanStatus::Complete->value,
            'dateStarted' => $now,
            'dateFinished' => $now,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])
        ->execute();
}

/** The widget body, rendered the way the dashboard would render it. */
function widgetBody(): ?string
{
    $view = Craft::$app->getView();
    $mode = $view->getTemplateMode();
    $view->setTemplateMode(View::TEMPLATE_MODE_CP);

    try {
        return (new BrokenLinksWidget())->getBodyHtml();
    } finally {
        $view->setTemplateMode($mode);
    }
}

beforeEach(function() {
    widgetClearTables();
});

describe('BrokenLinksWidget', function() {
    it('says nothing has been scanned before anything has', function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());

        expect(widgetBody())->toContain('Nothing has been scanned yet');
    });

    it('counts the broken links and the ones that moved for good', function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());

        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;

        widgetUrl('https://example.com/widget-broken-one', UrlStatus::Broken, $siteId);
        widgetUrl('https://example.com/widget-broken-two', UrlStatus::Broken, $siteId);
        widgetUrl('https://example.com/widget-moved', UrlStatus::Redirect, $siteId, true);
        widgetUrl('https://example.com/widget-fine', UrlStatus::Ok, $siteId);
        widgetScan();

        $html = widgetBody();

        expect($html)->toContain('>2</a>')
            ->and($html)->toContain('>1</a>')
            ->and($html)->toContain('broken')
            ->and($html)->toContain('Last scanned');
    });

    it('renders nothing for somebody who may not read the reports', function() {
        $this->actingAs(UserFactory::factory()->create());

        widgetScan();

        expect(widgetBody())->toBeNull();
    });

    it('keeps itself out of the widget picker for the same reader', function() {
        $this->actingAs(UserFactory::factory()->create());

        expect(BrokenLinksWidget::isSelectable())->toBeFalse();
    });
});
