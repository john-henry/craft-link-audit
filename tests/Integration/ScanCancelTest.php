<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\Queue as QueueHelper;
use craft\queue\jobs\UpdateSearchIndex;
use johnhenry\linkaudit\console\controllers\ScanController as ConsoleScanController;
use johnhenry\linkaudit\controllers\BaseController;
use johnhenry\linkaudit\enums\ScanMode;
use johnhenry\linkaudit\enums\ScanStatus;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\exceptions\ScanInProgressException;
use johnhenry\linkaudit\jobs\ExtractElementLinks;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\Verdict;
use johnhenry\linkaudit\records\ScanRecord;
use johnhenry\linkaudit\records\UrlRecord;
use markhuot\craftpest\factories\User as UserFactory;
use yii\console\ExitCode;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;

// ---------------------------------------------------------------------------
// Cancelling a run
//
// A cancel is two things happening together, and either one on its own is
// worse than useless. Marking the row cancelled while its jobs are still in the
// queue means the next worker carries on writing to a row that says it stopped.
// Releasing the jobs without marking the row leaves the double-start guard shut
// for an hour, against a run nothing is working on.
//
// So every test here holds both halves at once, and the third thing beside
// them: that the verdicts already earned are still there afterwards, because a
// verdict belongs to the URL and not to the run that happened to find it.
//
// Helper names carry a `cancel` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/** A scan row sitting in the given state, without a queue push behind it. */
function cancelRunningScan(ScanStatus $status = ScanStatus::Extracting): ScanRecord
{
    $scan = new ScanRecord([
        'mode' => ScanMode::Full->value,
        'status' => $status->value,
    ]);
    $scan->save(false);

    return $scan;
}

/** Marks every scan the development database left running as finished. */
function cancelSettleRunningScans(): void
{
    Db::update(
        ScanRecord::tableName(),
        ['status' => ScanStatus::Complete->value],
        ['status' => [
            ScanStatus::Queued->value,
            ScanStatus::Extracting->value,
            ScanStatus::Checking->value,
        ]],
    );
}

/** The stored status of one scan row. */
function cancelStatusOf(int $scanId): ?string
{
    $status = (new Query())
        ->select(['status'])
        ->from([ScanRecord::tableName()])
        ->where(['id' => $scanId])
        ->scalar();

    return is_string($status) ? $status : null;
}

/** Whether a queue row is still there. */
function cancelQueueRowExists(string $id): bool
{
    return (new Query())
        ->from(Table::QUEUE)
        ->where(['id' => $id])
        ->exists();
}

/** A non-admin user holding exactly the given plugin permissions. */
function cancelUserWith(array $permissions): craft\elements\User
{
    $user = UserFactory::factory()->create();

    Craft::$app->getUserPermissions()->saveUserPermissions((int)$user->id, array_merge([
        'accesscp',
        'accessplugin-link-audit',
        'editsite:' . Craft::$app->getSites()->getPrimarySite()->uid,
    ], $permissions));

    return $user;
}

describe('ScanService::cancelScan', function() {
    it('marks the running scan cancelled and stamps it finished', function() {
        $scan = cancelRunningScan();

        $cancelled = LinkAudit::getInstance()->getScanService()->cancelScan();

        expect($cancelled)->not->toBeNull()
            ->and((int)$cancelled->id)->toBe((int)$scan->id)
            ->and(cancelStatusOf((int)$scan->id))->toBe(ScanStatus::Cancelled->value);

        $finished = (new Query())
            ->select(['dateFinished'])
            ->from([ScanRecord::tableName()])
            ->where(['id' => $scan->id])
            ->scalar();

        expect($finished)->not->toBeNull();
    });

    it('frees the double-start guard there and then', function() {
        cancelRunningScan();

        // The guard shut, which is what makes the rest of this mean anything.
        expect(fn() => LinkAudit::getInstance()->getScanService()->startScan(ScanMode::Full))
            ->toThrow(ScanInProgressException::class);

        LinkAudit::getInstance()->getScanService()->cancelScan();

        $started = LinkAudit::getInstance()->getScanService()->startScan(ScanMode::Full);

        expect((int)$started->id)->toBeGreaterThan(0)
            ->and($started->status)->toBe(ScanStatus::Queued->value);
    });

    it('releases the plugin\'s queued jobs and leaves everybody else\'s alone', function() {
        cancelRunningScan();

        $ours = (string)QueueHelper::push(new ExtractElementLinks(['elementId' => 1]));
        $theirs = (string)QueueHelper::push(new UpdateSearchIndex([
            'elementType' => Entry::class,
            'elementId' => 1,
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
        ]));

        LinkAudit::getInstance()->getScanService()->cancelScan();

        expect(cancelQueueRowExists($ours))->toBeFalse()
            ->and(cancelQueueRowExists($theirs))->toBeTrue();
    });

    it('leaves the verdicts it had already earned exactly where they are', function() {
        $store = LinkAudit::getInstance()->getUrlStore();
        $urlId = $store->upsert('https://example.com/cancelled-mid-scan', false);

        $store->recordVerdict($urlId, new Verdict(
            status: UrlStatus::Broken,
            httpStatus: 404,
        ));

        cancelRunningScan();
        LinkAudit::getInstance()->getScanService()->cancelScan();

        $status = (new Query())
            ->select(['status'])
            ->from([UrlRecord::tableName()])
            ->where(['id' => $urlId])
            ->scalar();

        expect($status)->toBe(UrlStatus::Broken->value);
    });

    it('says nothing was running when nothing is', function() {
        cancelSettleRunningScans();

        expect(LinkAudit::getInstance()->getScanService()->cancelScan())->toBeNull();
    });

    it('leaves the queue alone when nothing is running', function() {
        cancelSettleRunningScans();

        $ours = (string)QueueHelper::push(new ExtractElementLinks(['elementId' => 1]));

        LinkAudit::getInstance()->getScanService()->cancelScan();

        expect(cancelQueueRowExists($ours))->toBeTrue();
    });
});

describe('link-audit/scan/cancel', function() {
    it('exists, and takes none of the scanning options', function() {
        $controller = new ConsoleScanController('scan', LinkAudit::getInstance());

        expect($controller->hasMethod('actionCancel'))->toBeTrue()
            ->and($controller->options('cancel'))->not->toContain('site')
            ->and($controller->options('cancel'))->not->toContain('force');
    });

    it('cancels the run that is going', function() {
        $scan = cancelRunningScan();

        $controller = new ConsoleScanController('scan', LinkAudit::getInstance());
        $controller->color = false;

        expect($controller->actionCancel())->toBe(ExitCode::OK)
            ->and(cancelStatusOf((int)$scan->id))->toBe(ScanStatus::Cancelled->value);
    });

    it('comes back happy when there was nothing to call off', function() {
        cancelSettleRunningScans();

        $controller = new ConsoleScanController('scan', LinkAudit::getInstance());
        $controller->color = false;

        expect($controller->actionCancel())->toBe(ExitCode::OK);
    });
});

describe('ScansController::actionCancel', function() {
    it('refuses a reader who may not run scans', function() {
        cancelRunningScan();

        $this->actingAs(cancelUserWith([BaseController::PERMISSION_VIEW_REPORTS]));

        expect(fn() => $this->post('actions/link-audit/scans/cancel'))
            ->toThrow(ForbiddenHttpException::class);
    });

    it('refuses a GET', function() {
        $this->actingAs(cancelUserWith([
            BaseController::PERMISSION_VIEW_REPORTS,
            BaseController::PERMISSION_RUN_SCANS,
        ]));

        expect(fn() => $this->get('actions/link-audit/scans/cancel'))
            ->toThrow(MethodNotAllowedHttpException::class);
    });

    it('lets somebody who may run scans stop one', function() {
        $scan = cancelRunningScan();

        $this->actingAs(cancelUserWith([
            BaseController::PERMISSION_VIEW_REPORTS,
            BaseController::PERMISSION_RUN_SCANS,
        ]));

        $this->post('actions/link-audit/scans/cancel', [
            'redirect' => Craft::$app->getSecurity()->hashData('link-audit'),
        ])->assertRedirect();

        expect(cancelStatusOf((int)$scan->id))->toBe(ScanStatus::Cancelled->value);
    });
});

describe('The Overview while a scan is running', function() {
    it('offers the stop button to somebody who may run scans', function() {
        cancelRunningScan();

        $this->actingAs(cancelUserWith([
            BaseController::PERMISSION_VIEW_REPORTS,
            BaseController::PERMISSION_RUN_SCANS,
        ]));

        $this->get('admin/link-audit')
            ->assertOk()
            ->assertSee('Stop this scan');
    });

    it('hides it from a reader who may not', function() {
        cancelRunningScan();

        $this->actingAs(cancelUserWith([BaseController::PERMISSION_VIEW_REPORTS]));

        $this->get('admin/link-audit')
            ->assertOk()
            ->assertDontSee('Stop this scan');
    });
});
