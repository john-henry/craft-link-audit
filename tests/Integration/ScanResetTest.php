<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Queue as QueueHelper;
use craft\queue\jobs\UpdateSearchIndex;
use johnhenry\linkaudit\console\controllers\ScanController as ConsoleScanController;
use johnhenry\linkaudit\enums\ScanMode;
use johnhenry\linkaudit\enums\ScanStatus;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\helpers\UrlNormaliser;
use johnhenry\linkaudit\jobs\ExtractElementLinks;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\records\HostRecord;
use johnhenry\linkaudit\records\IgnoreRecord;
use johnhenry\linkaudit\records\ReferenceRecord;
use johnhenry\linkaudit\records\ScanRecord;
use johnhenry\linkaudit\records\UrlRecord;
use markhuot\craftpest\factories\User as UserFactory;
use yii\console\ExitCode;

// ---------------------------------------------------------------------------
// Starting over
//
// A reset is for the install whose settings have moved far enough that the
// stored findings are answering a question nobody is asking any more. Four
// tables go and one stays, and the one that stays is the whole reason this is
// not simply an uninstall and a reinstall: an ignore is an editorial decision
// about an address, not a scan result, and somebody who dismissed two hundred
// URLs is not doing that again because the scan set changed.
//
// So the test worth having here is not that the tables are empty. It is that a
// URL somebody dismissed comes back ignored the moment it is found again,
// through the same upsert a real scan uses rather than through a flag set by
// hand.
//
// Helper names carry a `reset` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/** How many rows a table is carrying. */
function resetRowCount(string $table): int
{
    return (int)(new Query())->from([$table])->count();
}

/** A scan row sitting mid-run, without a queue push behind it. */
function resetRunningScan(): ScanRecord
{
    $scan = new ScanRecord([
        'mode' => ScanMode::Full->value,
        'status' => ScanStatus::Extracting->value,
    ]);
    $scan->save(false);

    return $scan;
}

/** A URL with one reference on the primary site, and a host row beside it. */
function resetSeed(string $url): int
{
    $store = LinkAudit::getInstance()->getUrlStore();
    $urlId = $store->upsert($url, false);

    $store->replaceReferencesFor(
        (int)UserFactory::factory()->create()->id,
        (int)Craft::$app->getSites()->getPrimarySite()->id,
        [['urlId' => $urlId, 'elementType' => User::class]],
    );

    LinkAudit::getInstance()->getHostState()->recordSuccess((string)UrlNormaliser::hostOf($url), 200);

    return $urlId;
}

/** The stored verdict of one URL row. */
function resetStatusOf(int $urlId): ?string
{
    $status = (new Query())
        ->select(['status'])
        ->from([UrlRecord::tableName()])
        ->where(['id' => $urlId])
        ->scalar();

    return is_string($status) ? $status : null;
}

/** Whether a queue row is still there. */
function resetQueueRowExists(string $id): bool
{
    return (new Query())
        ->from(Table::QUEUE)
        ->where(['id' => $id])
        ->exists();
}

describe('ScanService::resetAll', function() {
    it('empties the four result tables', function() {
        resetSeed('https://example.com/reset-me');
        resetRunningScan();

        $removed = LinkAudit::getInstance()->getScanService()->resetAll();

        expect($removed['references'])->toBeGreaterThan(0)
            ->and($removed['urls'])->toBeGreaterThan(0)
            ->and($removed['scans'])->toBeGreaterThan(0)
            ->and(resetRowCount(ReferenceRecord::tableName()))->toBe(0)
            ->and(resetRowCount(UrlRecord::tableName()))->toBe(0)
            ->and(resetRowCount(ScanRecord::tableName()))->toBe(0)
            ->and(resetRowCount(HostRecord::tableName()))->toBe(0);
    });

    it('leaves the ignore decisions standing', function() {
        $url = 'https://example.com/reset-but-still-ignored';
        resetSeed($url);

        $hash = UrlNormaliser::hash($url);
        LinkAudit::getInstance()->getIgnoreService()->ignoreUrl($hash, 'Not worth chasing.');

        $before = resetRowCount(IgnoreRecord::tableName());

        expect($before)->toBeGreaterThan(0);

        LinkAudit::getInstance()->getScanService()->resetAll();

        expect(resetRowCount(IgnoreRecord::tableName()))->toBe($before)
            ->and((new Query())
                ->from([IgnoreRecord::tableName()])
                ->where(['urlHash' => $hash])
                ->exists())->toBeTrue();
    });

    it('brings a dismissed URL back ignored the next time it is found', function() {
        $url = 'https://example.com/reset-reborn-ignored';
        resetSeed($url);

        $hash = UrlNormaliser::hash($url);
        LinkAudit::getInstance()->getIgnoreService()->ignoreUrl($hash);

        LinkAudit::getInstance()->getScanService()->resetAll();

        // The same call extraction makes, rather than a status set by hand:
        // what is under test is that the upsert asks the ignores table about a
        // URL it has never seen before.
        $rebornId = LinkAudit::getInstance()->getUrlStore()->upsert($url, false);

        expect(resetStatusOf($rebornId))->toBe(UrlStatus::Ignored->value);
    });

    it('calls off anything running, jobs and all', function() {
        resetRunningScan();

        $ours = (string)QueueHelper::push(new ExtractElementLinks(['elementId' => 1]));
        $theirs = (string)QueueHelper::push(new UpdateSearchIndex([
            'elementType' => Entry::class,
            'elementId' => 1,
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
        ]));

        LinkAudit::getInstance()->getScanService()->resetAll();

        expect(resetQueueRowExists($ours))->toBeFalse()
            ->and(resetQueueRowExists($theirs))->toBeTrue();
    });
});

describe('link-audit/scan/reset', function() {
    it('takes a force option, so a script never sits waiting on a prompt', function() {
        $controller = new ConsoleScanController('scan', LinkAudit::getInstance());

        expect($controller->hasMethod('actionReset'))->toBeTrue()
            ->and($controller->options('reset'))->toContain('force');
    });

    it('empties the tables when it is forced', function() {
        resetSeed('https://example.com/reset-from-the-console');

        $controller = new ConsoleScanController('scan', LinkAudit::getInstance());
        $controller->color = false;
        $controller->force = true;

        expect($controller->actionReset())->toBe(ExitCode::OK)
            ->and(resetRowCount(UrlRecord::tableName()))->toBe(0)
            ->and(resetRowCount(ReferenceRecord::tableName()))->toBe(0);
    });

    it('has no control panel surface at all', function() {
        $controllers = (array)glob(dirname(__DIR__, 2) . '/src/controllers/*.php');

        foreach ($controllers as $path) {
            expect([basename((string)$path), str_contains(
                (string)file_get_contents((string)$path),
                'resetAll',
            )])->toBe([basename((string)$path), false]);
        }
    });
});
