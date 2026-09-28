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

// ---------------------------------------------------------------------------
// safeUp() and safeDown() have to cover the same tables
//
// A table added to the install and not to the teardown is left behind when the
// plugin is uninstalled: rows, foreign keys and all, with nothing in the
// control panel offering to remove them and no error to say so. Nothing else
// here would notice, because everything else asserts what install creates.
//
// Read out of the migration rather than by running it, since running safeDown
// against the test database would take the tables every other test needs.
// ---------------------------------------------------------------------------

/** The migration's source. */
function installSource(): string
{
    return (string)file_get_contents(dirname(__DIR__, 2) . '/src/migrations/Install.php');
}

it('creates a table for every record the plugin ships', function() {
    $source = installSource();

    preg_match_all('/private function _create\w+Table\(\): void\s*\{\s*\$table = (\w+)::tableName\(\);/', $source, $created);
    preg_match_all('/private function _create(\w+)Table\(\): void/', $source, $methods);

    // Every create method must be called from safeUp, or the table is declared
    // and never made.
    preg_match('/public function safeUp\(\): bool\s*\{(.*?)\n    \}/s', $source, $up);

    $uncalled = array_values(array_filter(
        $methods[1],
        static fn(string $name): bool => !str_contains($up[1], "_create{$name}Table()"),
    ));

    expect($created[1])->not->toBeEmpty()
        ->and($uncalled)->toBe([]);
});

it('drops every table it creates', function() {
    $source = installSource();

    preg_match_all('/\$table = (\w+)::tableName\(\);/', $source, $created);
    preg_match_all('/dropTableIfExists\((\w+)::tableName\(\)\)/', $source, $dropped);

    $createdSet = array_unique($created[1]);
    $droppedSet = array_unique($dropped[1]);
    $leftBehind = array_values(array_diff($createdSet, $droppedSet));

    expect($createdSet)->not->toBeEmpty()
        ->and($leftBehind)->toBe([], sprintf(
            'These tables are created and never dropped: %s',
            implode(', ', $leftBehind),
        ));
});

it('drops nothing it does not create', function() {
    // The other direction: a drop left behind by a table that was removed
    // would fail the uninstall on a name that is no longer there.
    $source = installSource();

    preg_match_all('/\$table = (\w+)::tableName\(\);/', $source, $created);
    preg_match_all('/dropTableIfExists\((\w+)::tableName\(\)\)/', $source, $dropped);

    $orphaned = array_values(array_diff(array_unique($dropped[1]), array_unique($created[1])));

    expect($orphaned)->toBe([]);
});
