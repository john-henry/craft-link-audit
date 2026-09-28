<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\linkaudit\records\HostRecord;
use johnhenry\linkaudit\records\IgnoreRecord;
use johnhenry\linkaudit\records\ReferenceRecord;
use johnhenry\linkaudit\records\ScanRecord;
use johnhenry\linkaudit\records\UrlRecord;
use yii\db\ActiveRecord;

// ---------------------------------------------------------------------------
// A record carries no properties of its own: every one is a column, reached
// through Yii's magic getter and described to everything else by an @property
// tag. Nothing enforces that the tags and the table agree, and static analysis
// believes the tags, so a column added without one is invisible to the IDE and
// to PHPStan while reading it works perfectly at runtime. `pagesCrawled` had
// been that way.
//
// The columns Craft's ActiveRecord adds to every table are not documented on
// any of these records, so they are excluded rather than each record having to
// restate them.
// ---------------------------------------------------------------------------

/** @var string[] Columns Craft adds itself, documented on no record. */
const RECORD_IMPLICIT_COLUMNS = ['dateCreated', 'dateUpdated', 'uid'];

/** The @property names a record's docblock declares. */
function documentedColumns(string $class): array
{
    $file = (new ReflectionClass($class))->getFileName();

    preg_match_all('/@property\s+\S+\s+\$(\w+)/', (string)file_get_contents((string)$file), $m);

    return $m[1];
}

/** @var array<string, class-string<ActiveRecord>> Every record the plugin ships. */
function pluginRecords(): array
{
    return [
        'HostRecord' => HostRecord::class,
        'IgnoreRecord' => IgnoreRecord::class,
        'ReferenceRecord' => ReferenceRecord::class,
        'ScanRecord' => ScanRecord::class,
        'UrlRecord' => UrlRecord::class,
    ];
}

it('finds a table for every record', function(string $class) {
    // Guards the checks below: a record whose table cannot be read would make
    // them pass without comparing anything.
    $table = Craft::$app->getDb()->getSchema()->getTableSchema($class::tableName());

    expect($table)->not->toBeNull()
        ->and($table->columns)->not->toBeEmpty();
})->with(pluginRecords());

it('documents every column the table actually has', function(string $class) {
    $table = Craft::$app->getDb()->getSchema()->getTableSchema($class::tableName());
    $undocumented = array_values(array_diff(
        array_keys($table->columns),
        documentedColumns($class),
        RECORD_IMPLICIT_COLUMNS,
    ));

    expect($undocumented)->toBe([], sprintf(
        '%s has columns with no @property tag: %s',
        $class,
        implode(', ', $undocumented),
    ));
})->with(pluginRecords());

it('documents no property the table does not have', function(string $class) {
    // The other direction: a tag left behind by a dropped column reads as a
    // property that is there and is not.
    $table = Craft::$app->getDb()->getSchema()->getTableSchema($class::tableName());
    $orphaned = array_values(array_diff(documentedColumns($class), array_keys($table->columns)));

    expect($orphaned)->toBe([], sprintf(
        '%s documents properties the table does not have: %s',
        $class,
        implode(', ', $orphaned),
    ));
})->with(pluginRecords());
