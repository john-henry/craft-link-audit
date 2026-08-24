<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\helpers\Db;
use johnhenry\linkaudit\assets\tour\TourAsset;
use johnhenry\linkaudit\controllers\BaseController;
use johnhenry\linkaudit\enums\ScanMode;
use johnhenry\linkaudit\enums\ScanStatus;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\records\ScanRecord;
use johnhenry\linkaudit\services\TourService;
use markhuot\craftpest\factories\User as UserFactory;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;

// ---------------------------------------------------------------------------
// The Overview tour
//
// Two things are worth proving and neither of them is the tooltip. That the
// tour runs itself exactly once for a given reader, and that the endpoint
// recording it is behind the same permission the reports are: it writes to a
// user's own preferences, so anybody who can reach it can write to their own.
//
// What it looks like on screen is browser territory and is left there.
//
// Helper names carry a `tour` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/**
 * A scan row, so the Overview has the panes the tour is about.
 *
 * The tour only opens itself on an install that has something to be shown, so a
 * screen with nothing on it is its own case and the rest of these need a run on
 * the board.
 */
function tourScan(ScanStatus $status = ScanStatus::Complete): void
{
    Db::insert(ScanRecord::tableName(), [
        'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
        'mode' => ScanMode::Full->value,
        'status' => $status->value,
        'dateStarted' => Db::prepareDateForDb(new DateTime('-1 minute')),
        'dateFinished' => $status === ScanStatus::Complete
            ? Db::prepareDateForDb(new DateTime())
            : null,
    ]);
}

/** The tour service. */
function tourService(): TourService
{
    return LinkAudit::getInstance()->getTourService();
}

/**
 * A reader holding the given plugin permissions, acting from here on.
 *
 * Craft's own gates come as standard: a user Craft would not let into the
 * control panel at all proves nothing about a permission of ours.
 */
function tourReader(array $permissions = [BaseController::PERMISSION_VIEW_REPORTS]): craft\elements\User
{
    $user = UserFactory::factory()->create();

    Craft::$app->getUserPermissions()->saveUserPermissions((int)$user->id, array_merge([
        'accesscp',
        'accessplugin-link-audit',
        'editsite:' . Craft::$app->getSites()->getPrimarySite()->uid,
    ], $permissions));

    test()->actingAs($user);

    return $user;
}

// Whatever the developer's own database is carrying, these tests decide for
// themselves whether the install has ever been scanned. Cleared inside the
// test's own transaction, so it comes back on rollback.
beforeEach(function() {
    Craft::$app->getDb()->createCommand()->delete(ScanRecord::tableName())->execute();
});

describe('TourService', function() {
    it('has not been seen by somebody who has never opened it', function() {
        expect(tourService()->hasSeen(UserFactory::factory()->create()))->toBeFalse();
    });

    it('remembers a reader who has seen it', function() {
        $user = UserFactory::factory()->create();

        tourService()->markSeen($user);

        expect(tourService()->hasSeen($user))->toBeTrue();
    });

    it('keeps one reader\'s answer to itself', function() {
        $seen = UserFactory::factory()->create();
        $fresh = UserFactory::factory()->create();

        tourService()->markSeen($seen);

        expect(tourService()->hasSeen($seen))->toBeTrue()
            ->and(tourService()->hasSeen($fresh))->toBeFalse();
    });

    // Preferences are one flat map shared by Craft and every plugin on the
    // install, so writing ours must not take anybody else's with it.
    it('leaves the reader\'s other preferences where they are', function() {
        $user = UserFactory::factory()->create();

        Craft::$app->getUsers()->saveUserPreferences($user, ['locale' => 'en-GB']);
        tourService()->markSeen($user);

        expect(Craft::$app->getUsers()->getUserPreference((int)$user->id, 'locale'))->toBe('en-GB')
            ->and(tourService()->hasSeen($user))->toBeTrue();
    });

    it('gives every step a selector, a title and a sentence', function() {
        $steps = tourService()->stepsForOverview();

        expect($steps)->toHaveCount(6);

        foreach ($steps as $step) {
            expect($step['selector'])->not->toBe('')
                ->and($step['title'])->not->toBe('')
                ->and($step['text'])->not->toBe('');
        }
    });

    // A tour is a guide to what this reader can do, so a reader who may not run
    // a scan is not walked past the buttons and told about them.
    it('leaves the scan step out for a reader who may not run scans', function() {
        $selectors = array_column(tourService()->stepsForOverview(false), 'selector');

        expect($selectors)->toHaveCount(5)
            ->and($selectors)->not->toContain('#link-audit-scan-actions')
            ->and($selectors)->toContain('.link-audit-tiles')
            ->and($selectors)->toContain('#link-audit-tour-start');
    });
});

describe('TourAsset', function() {
    // Asserted on the bundle rather than on the rendered page. Craft registers
    // an asset bundle once per View, and Pest runs the whole suite in one
    // process against one View, so whether the tag appears in a given response
    // depends on which test rendered the Overview first.
    it('carries the vendored library, and the files are really there', function() {
        $bundle = Craft::$app->getView()->registerAssetBundle(TourAsset::class);

        expect($bundle->js)->toContain('driver.js.iife.js')
            ->and($bundle->css)->toContain('driver.css')
            ->and(file_exists($bundle->sourcePath . '/driver.js.iife.js'))->toBeTrue()
            ->and(file_exists($bundle->sourcePath . '/driver.css'))->toBeTrue()
            // The licence note travels with the files it is about.
            ->and(file_exists($bundle->sourcePath . '/LICENSE.md'))->toBeTrue();
    });
});

describe('The Overview', function() {
    it('carries the tour, its steps and the word to start it for a fresh reader', function() {
        tourReader();
        tourScan();

        $this->get('admin/link-audit')
            ->assertOk()
            ->assertSee('window.LinkAuditTour')
            ->assertSee('"autoStart":true', false)
            ->assertSee('link-audit\/tour\/seen', false)
            ->assertSee('Take the tour')
            ->assertSee('Where things stand');
    });

    // The counts, the last run and the two tables are what the tour is about,
    // and none of them are on the page until something has been scanned. Opened
    // there, it would walk a reader past the one button that is on the screen
    // and then mark itself seen, so the reader who most needed it would never be
    // offered it again.
    it('does not open itself on an install that has never been scanned', function() {
        tourReader();

        $this->get('admin/link-audit')
            ->assertOk()
            ->assertSee('"autoStart":false', false)
            // Still there to be asked for, which is the whole point of leaving
            // it alone rather than taking it away.
            ->assertSee('Take the tour');
    });

    // A scan that is still running has put the progress pane and the tiles on
    // the page, so there is something to be shown round.
    it('opens itself while the first scan is still running', function() {
        tourReader();
        tourScan(ScanStatus::Checking);

        $this->get('admin/link-audit')
            ->assertOk()
            ->assertSee('"autoStart":true', false);
    });

    it('leaves the tour alone for a reader who has had it', function() {
        $user = tourReader();
        tourScan();
        tourService()->markSeen($user);

        $this->get('admin/link-audit')
            ->assertOk()
            // The steps and the link stay: the tour is still there to be asked
            // for, it just does not open itself.
            ->assertSee('Take the tour')
            ->assertSee('"autoStart":false', false);
    });
});

describe('TourController::actionSeen', function() {
    it('records the tour as seen', function() {
        $user = tourReader();

        expect(tourService()->hasSeen($user))->toBeFalse();

        $this->postJson('actions/link-audit/tour/seen')->assertOk();

        expect(tourService()->hasSeen($user))->toBeTrue();
    });

    it('turns the autostart off for the next page load', function() {
        tourReader();
        tourScan();

        $this->postJson('actions/link-audit/tour/seen')->assertOk();

        $this->get('admin/link-audit')
            ->assertOk()
            ->assertSee('"autoStart":false', false);
    });

    it('refuses somebody who may not read the reports', function() {
        tourReader([]);

        expect(fn() => $this->postJson('actions/link-audit/tour/seen'))
            ->toThrow(ForbiddenHttpException::class);
    });

    it('refuses a GET', function() {
        tourReader();

        expect(fn() => $this->get('actions/link-audit/tour/seen'))
            ->toThrow(MethodNotAllowedHttpException::class);
    });

    it('refuses a caller that will not take JSON', function() {
        tourReader();

        expect(fn() => $this->post('actions/link-audit/tour/seen'))
            ->toThrow(BadRequestHttpException::class);
    });
});
