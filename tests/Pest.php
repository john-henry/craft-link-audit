<?php

/**
 * Integration tests require a running Craft context.
 * Run from the parent Craft project:
 *
 *   vendor/bin/pest plugins/craft-link-audit/tests \
 *     --test-directory=plugins/craft-link-audit/tests
 *
 * or, the way you should:
 *
 *   ddev exec composer test:la
 */

// Apply TestCase + RefreshesDatabase to every Integration test.
// RefreshesDatabase wraps each test in a DB transaction that rolls back on
// teardown, keeping the database clean between tests.
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\SettingsModel;
use johnhenry\linkaudit\records\HostRecord;
use markhuot\craftpest\test\RefreshesDatabase;
use markhuot\craftpest\test\TestCase;

uses(
    TestCase::class,
    RefreshesDatabase::class,
)->in('Integration');

// RefreshesDatabase wraps each test in a transaction it rolls back on teardown,
// and that binding only engages when this file is loaded at all, which only
// happens when the suite is run with --test-directory pointing here. Without an
// open transaction every test would COMMIT to the dev database, so fail loudly
// before one can write.
//
// Scoped to the whole suite rather than to Integration, so a test added anywhere
// under this directory is covered. The root tests/Pest.php carries the same
// guard for the run this file is not loaded in at all, which is the run that
// caused the trouble.
uses()->beforeEach(function() {
    if (Craft::$app->getDb()->getTransaction() === null) {
        throw new RuntimeException(
            'No open database transaction: RefreshesDatabase did not bind, so tests would '
            . 'commit to the dev database. Run the suite via `composer test:la`.'
        );
    }
})->in(__DIR__);

// Craft's Sites service memoises editable site ids for the whole process, and
// RefreshesDatabase only rolls back the database, not in-memory service memos.
// Without this, one file computing editable sites under a given identity leaves
// a stale (often empty) memo that survives into the next file, so a later
// actingAs() is ignored and per-site permission checks wrongly refuse. In
// production every request is a fresh process, so this only bites the shared
// test process; refreshing before each test recomputes against its identity.
uses()->beforeEach(function() {
    Craft::$app->getSites()->refreshSites();

    // Plugin settings are a process-level singleton that RefreshesDatabase does
    // not roll back, and one that also loads from the dev project config. Put
    // every one of them back to the model's own default before each test, so
    // neither the developer's dev config nor a settings test's save can change
    // what the next test is scanning, checking or reporting.
    $settings = LinkAudit::getInstance()->getSettings();

    foreach ((new SettingsModel())->getAttributes() as $name => $value) {
        $settings->$name = $value;
    }

    // The host state service memoises the rows it reads, and that memo is not a
    // database write, so RefreshesDatabase cannot roll it back. Left alone, one
    // test's 429 would still be blocking a host in the next.
    LinkAudit::getInstance()->getHostState()->flush();

    // The rows behind that memo are cleared too, inside the test's own
    // transaction so the developer's data comes back on rollback. A real scan of
    // the dev site leaves durable politeness behind it, and a host row carrying a
    // ten minute learned delay does not fail the suite: it makes the scheduler
    // honour it, one request every ten minutes, which reads as a hang rather than
    // as a failure and costs a very long afternoon to find.
    Craft::$app->getDb()->createCommand()->delete(HostRecord::tableName())->execute();

    // Same story for the ignore service, which holds the ignored hashes: one
    // test's dismissal would still be quieting a URL in the next.
    LinkAudit::getInstance()->getIgnoreService()->flush();
})->in('Integration');
