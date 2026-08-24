<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\db\Query;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\SettingsModel;
use johnhenry\linkaudit\records\HostRecord;
use johnhenry\linkaudit\records\IgnoreRecord;
use johnhenry\linkaudit\records\ReferenceRecord;
use johnhenry\linkaudit\records\ScanRecord;
use johnhenry\linkaudit\records\UrlRecord;

it('creates every table the plugin needs', function() {
    $db = Craft::$app->getDb();

    expect($db->tableExists(UrlRecord::tableName()))->toBeTrue()
        ->and($db->tableExists(ReferenceRecord::tableName()))->toBeTrue()
        ->and($db->tableExists(ScanRecord::tableName()))->toBeTrue()
        ->and($db->tableExists(IgnoreRecord::tableName()))->toBeTrue()
        ->and($db->tableExists(HostRecord::tableName()))->toBeTrue();
});

// Asserted with a select rather than by reading the table schema: a schema read
// goes through Yii's file cache, whose suppressed filemtime() stat on a cold
// cache PHPUnit reports as a test warning. Naming the columns proves the same
// thing, since the database refuses a select on a column that is not there.
it('gives the URLs table the columns the verdict cache depends on', function() {
    $rows = (new Query())
        ->select(['urlHash', 'status', 'nextCheckAfter', 'failCount', 'siteId', 'isInternal'])
        ->from(UrlRecord::tableName())
        ->limit(1)
        ->all();

    expect($rows)->toBeArray();
});

it('reads its settings model with the documented defaults', function() {
    $settings = LinkAudit::getInstance()->getSettings();

    expect($settings)->toBeInstanceOf(SettingsModel::class)
        ->and($settings->concurrency)->toBe(10)
        ->and($settings->maxConcurrentPerHost)->toBe(2)
        ->and($settings->scanNavigationNodes)->toBeTrue()
        ->and($settings->renderedCrawlEnabled)->toBeFalse()
        ->and($settings->checkAnchorFragments)->toBeFalse()
        ->and($settings->maxPagesToCrawl)->toBe(500)
        ->and($settings->validate())->toBeTrue();
});
