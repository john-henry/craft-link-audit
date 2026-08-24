<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use craft\helpers\Queue as QueueHelper;
use craft\helpers\StringHelper;
use craft\queue\jobs\UpdateSearchIndex;
use craft\widgets\RecentEntries;
use johnhenry\linkaudit\jobs\ExtractElementLinks;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\services\TourService;
use johnhenry\linkaudit\services\UninstallService;
use johnhenry\linkaudit\widgets\BrokenLinksWidget;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Uninstall cleanup
//
// Dropping the tables is Craft's job and it does it. What it cannot know is
// which queued job, which user preference and which dashboard tile were ours,
// and every one of those outlives the plugin if nobody takes it out: a queued
// job most loudly, since the next worker to reach it fatals on a class that is
// no longer on disk.
//
// Every test here proves two things at once, and the second is the one worth
// having: that ours went, and that the neighbour's beside it stayed. This code
// writes to three tables Craft and every other plugin share.
//
// The uninstall itself is not run. Craft wraps it in its own transaction and
// commits it, which would take the plugin off the development database the
// suite runs against; the live round trip is where that is proven. What runs
// here is the cleanup the uninstall hook calls, plus the hook itself.
//
// Helper names carry an `uninstall` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/** The uninstall cleanup service. */
function uninstallService(): UninstallService
{
    return LinkAudit::getInstance()->getUninstallService();
}

/** The preferences held against a user, read past the service's memo. */
function uninstallStoredPreferences(int $userId): array
{
    $stored = (new Query())
        ->select(['preferences'])
        ->from(Table::USERPREFERENCES)
        ->where(['userId' => $userId])
        ->scalar();

    if (!is_string($stored)) {
        return [];
    }

    $decoded = json_decode($stored, true);

    return is_array($decoded) ? $decoded : [];
}

/** Puts a dashboard tile of the given type on a user's dashboard. */
function uninstallWidget(int $userId, string $type): int
{
    $now = Db::prepareDateForDb(new DateTime('now'));

    Craft::$app->getDb()->createCommand()
        ->insert(Table::WIDGETS, [
            'userId' => $userId,
            'type' => $type,
            'sortOrder' => 1,
            'colspan' => 1,
            'settings' => '{}',
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])
        ->execute();

    return (int)Craft::$app->getDb()->getLastInsertID(Table::WIDGETS);
}

/** Whether a queue row is still there. */
function uninstallQueueRowExists(string $id): bool
{
    return (new Query())
        ->from(Table::QUEUE)
        ->where(['id' => $id])
        ->exists();
}

describe('UninstallService::releaseQueuedJobs', function() {
    it('releases the plugin\'s jobs and leaves everybody else\'s alone', function() {
        $ours = (string)QueueHelper::push(new ExtractElementLinks(['elementId' => 1]));
        $theirs = (string)QueueHelper::push(new UpdateSearchIndex([
            'elementType' => craft\elements\Entry::class,
            'elementId' => 1,
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
        ]));

        expect(uninstallQueueRowExists($ours))->toBeTrue()
            ->and(uninstallQueueRowExists($theirs))->toBeTrue();

        expect(uninstallService()->releaseQueuedJobs())->toBeGreaterThanOrEqual(1);

        expect(uninstallQueueRowExists($ours))->toBeFalse()
            ->and(uninstallQueueRowExists($theirs))->toBeTrue();
    });

    it('has nothing to say about an empty queue', function() {
        Craft::$app->getDb()->createCommand()->delete(Table::QUEUE)->execute();

        expect(uninstallService()->releaseQueuedJobs())->toBe(0);
    });
});

describe('UninstallService::forgetUserPreferences', function() {
    it('takes the plugin\'s keys off a user and leaves the rest', function() {
        $user = UserFactory::factory()->create();

        Craft::$app->getUsers()->saveUserPreferences($user, ['locale' => 'en-GB']);
        LinkAudit::getInstance()->getTourService()->markSeen($user);

        expect(uninstallStoredPreferences((int)$user->id))
            ->toHaveKey(TourService::PREFERENCE_OVERVIEW_SEEN);

        expect(uninstallService()->forgetUserPreferences())->toBeGreaterThanOrEqual(1);

        expect(uninstallStoredPreferences((int)$user->id))
            ->not->toHaveKey(TourService::PREFERENCE_OVERVIEW_SEEN)
            ->and(uninstallStoredPreferences((int)$user->id)['locale'])->toBe('en-GB');
    });

    it('leaves a user who never met the tour untouched', function() {
        $user = UserFactory::factory()->create();

        Craft::$app->getUsers()->saveUserPreferences($user, ['locale' => 'en-IE']);

        uninstallService()->forgetUserPreferences();

        expect(uninstallStoredPreferences((int)$user->id))->toBe(['locale' => 'en-IE']);
    });
});

describe('UninstallService::forgetDashboardWidgets', function() {
    it('removes the plugin\'s tiles and leaves Craft\'s own', function() {
        $user = UserFactory::factory()->create();

        $ours = uninstallWidget((int)$user->id, BrokenLinksWidget::class);
        $theirs = uninstallWidget((int)$user->id, RecentEntries::class);

        expect(uninstallService()->forgetDashboardWidgets())->toBeGreaterThanOrEqual(1);

        expect((new Query())->from(Table::WIDGETS)->where(['id' => $ours])->exists())->toBeFalse()
            ->and((new Query())->from(Table::WIDGETS)->where(['id' => $theirs])->exists())->toBeTrue();
    });
});

describe('UninstallService::clearAll', function() {
    it('throws away the cached counts the navigation badges read', function() {
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $key = ['link-audit', 'verdict-counts', $siteId];

        LinkAudit::getInstance()->getReportService()->cachedVerdictCounts($siteId);

        expect(Craft::$app->getCache()->get($key))->toBeArray();

        uninstallService()->clearAll();

        expect(Craft::$app->getCache()->get($key))->toBeFalse();
    });
});

describe('The uninstall hook', function() {
    // The plugin's own hook rather than the service behind it, because the wiring
    // is the half of this that can silently stop being true: a cleanup nothing
    // calls is a cleanup that does not happen.
    it('runs the cleanup when Craft calls it', function() {
        $user = UserFactory::factory()->create();

        LinkAudit::getInstance()->getTourService()->markSeen($user);
        $widgetId = uninstallWidget((int)$user->id, BrokenLinksWidget::class);
        $jobId = (string)QueueHelper::push(new ExtractElementLinks(['elementId' => 1]));

        $hook = new ReflectionMethod(LinkAudit::class, 'beforeUninstall');
        $hook->invoke(LinkAudit::getInstance());

        expect(uninstallQueueRowExists($jobId))->toBeFalse()
            ->and((new Query())->from(Table::WIDGETS)->where(['id' => $widgetId])->exists())->toBeFalse()
            ->and(uninstallStoredPreferences((int)$user->id))
            ->not->toHaveKey(TourService::PREFERENCE_OVERVIEW_SEEN);
    });
});
