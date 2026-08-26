<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\helpers\ProjectConfig;
use johnhenry\linkaudit\controllers\BaseController;
use johnhenry\linkaudit\LinkAudit;
use markhuot\craftpest\factories\User as UserFactory;
use yii\web\ForbiddenHttpException;

// ---------------------------------------------------------------------------
// The tabbed settings screen
//
// The test that earns its keep here is the first one. A tabbed settings page
// that hands the posted body straight to savePluginSettings() drops everything
// the form did not carry, so saving the HTTP tab quietly wipes the notification
// settings and nobody finds out until an email stops arriving. Every save is
// asserted against what actually landed in project config, not against the
// in-memory model the controller just wrote to.
//
// Helper names carry a `settings` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/**
 * What is stored for the plugin, straight out of project config.
 *
 * Unpacked on the way out: project config stores an associative array as a list
 * of key and value pairs so the YAML keeps a stable order, and the settings
 * model is handed the unpacked version.
 */
function settingsStored(): array
{
    return ProjectConfig::unpackAssociativeArrays(
        (array)Craft::$app->getProjectConfig()->get('plugins.link-audit.settings'),
    );
}

/** Writes settings the way anything outside the control panel would. */
function settingsWrite(array $values): void
{
    $settings = LinkAudit::getInstance()->getSettings();

    foreach ($values as $name => $value) {
        $settings->$name = $value;
    }

    Craft::$app->getPlugins()->savePluginSettings(LinkAudit::getInstance(), $settings->toArray());
}

/** A signed redirect back to a settings tab, as the form posts one. */
function settingsRedirect(string $tab): string
{
    return Craft::$app->getSecurity()->hashData("link-audit/settings/$tab");
}

beforeEach(function() {
    // Project config writes its YAML at the end of a request, which a test does
    // not finish, but the flag is not worth trusting: these tests save settings
    // over and over, and none of that belongs in the repository.
    Craft::$app->getProjectConfig()->writeYamlAutomatically = false;

    // These test the save path, which needs admin changes allowed. Both flags
    // are forced on here so the suite passes whatever CRAFT_ALLOW_ADMIN_CHANGES
    // the environment carries, since a developer often runs the tests on an
    // install pinned read-only to try the read-only screens: the general
    // config gate, and the project config service, which the env puts into
    // read-only at boot and a save would otherwise be refused by.
    Craft::$app->getConfig()->getGeneral()->allowAdminChanges = true;
    Craft::$app->getProjectConfig()->readOnly = false;

    $this->actingAs(UserFactory::factory()->admin(true)->create());
});

describe('Saving one tab', function() {
    it('leaves every other tab exactly as it was', function() {
        settingsWrite([
            'scanOnSave' => false,
            'scanPlainTextUrls' => true,
            'notifyEmailRecipients' => 'reports@example.com',
            'notifyBrokenThreshold' => 7,
            'okTtlDays' => 45,
            'excludedUriPatterns' => [
                ['enabled' => true, 'siteId' => '', 'uriPattern' => '^checkout'],
            ],
        ]);

        $this->post('actions/link-audit/settings/save-http', [
            'settings' => [
                'concurrency' => '25',
                'maxConcurrentPerHost' => '3',
                'minHostDelayMs' => '400',
                'connectTimeout' => '15',
                'timeout' => '30',
                'maxRedirects' => '4',
                'retryCount' => '1',
                'brokenAfterFailures' => '5',
                'brokenAfterDnsFailures' => '2',
                'userAgent' => '',
                'verifySsl' => '1',
                'proxy' => '',
            ],
            'redirect' => settingsRedirect('http'),
        ])->assertRedirect();

        $stored = settingsStored();

        expect($stored['concurrency'])->toBe(25)
            ->and($stored['scanOnSave'])->toBeFalse()
            ->and($stored['scanPlainTextUrls'])->toBeTrue()
            ->and($stored['notifyEmailRecipients'])->toBe('reports@example.com')
            ->and($stored['notifyBrokenThreshold'])->toBe(7)
            ->and($stored['okTtlDays'])->toBe(45)
            ->and($stored['excludedUriPatterns'])->toBe([
                ['enabled' => true, 'siteId' => '', 'uriPattern' => '^checkout'],
            ]);
    });

    it('stores numbers as numbers, whatever the form posted', function() {
        $this->post('actions/link-audit/settings/save-http', [
            'settings' => [
                'concurrency' => '25',
                'timeout' => '30',
                'verifySsl' => '',
            ],
            'redirect' => settingsRedirect('http'),
        ])->assertRedirect();

        $stored = settingsStored();

        expect($stored['concurrency'])->toBe(25)
            ->and($stored['timeout'])->toBe(30)
            ->and($stored['verifySsl'])->toBeFalse();
    });

    it('keeps the stored number when a box is cleared, and takes a deliberate zero', function() {
        settingsWrite(['okTtlDays' => 30]);

        // Backspacing a value out and saving is an accident, not a request
        // for zero: zero here means recheck every working link on every scan.
        $this->post('actions/link-audit/settings/save-caching', [
            'settings' => ['okTtlDays' => ''],
            'redirect' => settingsRedirect('caching'),
        ])->assertRedirect();

        expect(settingsStored()['okTtlDays'])->toBe(30);

        // A typed zero is a decision, and it lands.
        $this->post('actions/link-audit/settings/save-caching', [
            'settings' => ['okTtlDays' => '0'],
            'redirect' => settingsRedirect('caching'),
        ])->assertRedirect();

        expect(settingsStored()['okTtlDays'])->toBe(0);
    });

    it('takes the crawl settings back off the scanning tab', function() {
        settingsWrite(['notifyEmailRecipients' => 'reports@example.com']);

        $this->post('actions/link-audit/settings/save-scanning', [
            'settings' => [
                'renderedCrawlEnabled' => '1',
                'maxPagesToCrawl' => '250',
                'checkAnchorFragments' => '1',
            ],
            'redirect' => settingsRedirect('scanning'),
        ])->assertRedirect();

        $stored = settingsStored();

        expect($stored['renderedCrawlEnabled'])->toBeTrue()
            ->and($stored['maxPagesToCrawl'])->toBe(250)
            ->and($stored['checkAnchorFragments'])->toBeTrue()
            // The tab-wipe rule holds for the new fields too.
            ->and($stored['notifyEmailRecipients'])->toBe('reports@example.com');
    });

    it('keeps the settings the tab did not carry at all', function() {
        settingsWrite(['proxy' => 'http://proxy.example:8080']);

        $this->post('actions/link-audit/settings/save-scanning', [
            'settings' => [
                'scanOnSave' => '1',
                'checkImages' => '',
            ],
            'redirect' => settingsRedirect('scanning'),
        ])->assertRedirect();

        $stored = settingsStored();

        expect($stored['proxy'])->toBe('http://proxy.example:8080')
            ->and($stored['scanOnSave'])->toBeTrue()
            ->and($stored['checkImages'])->toBeFalse();
    });
});

describe('A refused save', function() {
    it('shows the tab again and changes nothing', function() {
        settingsWrite(['concurrency' => 10]);

        $this->post('actions/link-audit/settings/save-http', [
            'settings' => ['concurrency' => '500'],
            'redirect' => settingsRedirect('http'),
        ])
            ->assertOk()
            ->assertSee('Concurrent Requests');

        expect(settingsStored()['concurrency'])->toBe(10);
    });
});

describe('The editable tables', function() {
    it('takes the rows back in the shape the scanner reads them', function() {
        $this->post('actions/link-audit/settings/save-scanning', [
            'settings' => [
                'excludedUriPatterns' => [
                    ['enabled' => '1', 'siteId' => '', 'uriPattern' => '^checkout'],
                    ['enabled' => '', 'siteId' => '', 'uriPattern' => 'print$'],
                    ['enabled' => '1', 'siteId' => '', 'uriPattern' => ''],
                ],
                'internalUrlAllowPatterns' => [
                    ['pattern' => '^downloads/', 'note' => 'Served straight off disk'],
                ],
            ],
            'redirect' => settingsRedirect('scanning'),
        ])->assertRedirect();

        $stored = settingsStored();

        expect($stored['excludedUriPatterns'])->toBe([
            ['enabled' => true, 'siteId' => '', 'uriPattern' => '^checkout'],
            ['enabled' => false, 'siteId' => '', 'uriPattern' => 'print$'],
        ])
            ->and($stored['internalUrlAllowPatterns'])->toBe([
                ['pattern' => '^downloads/', 'note' => 'Served straight off disk'],
            ]);
    });

    it('honours a saved exclusion the next time an element is looked at', function() {
        $this->post('actions/link-audit/settings/save-scanning', [
            'settings' => [
                'excludedUriPatterns' => [
                    ['enabled' => '1', 'siteId' => '', 'uriPattern' => '^checkout'],
                ],
            ],
            'redirect' => settingsRedirect('scanning'),
        ])->assertRedirect();

        $scans = LinkAudit::getInstance()->getScanService();
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;

        expect($scans->isUriExcluded('checkout/basket', $siteId))->toBeTrue()
            ->and($scans->isUriExcluded('about', $siteId))->toBeFalse();
    });
});

describe('Who may be on the screen', function() {
    it('shows an admin the tabs', function() {
        $this->get('admin/link-audit/settings/http')
            ->assertOk()
            ->assertSee('Concurrent Requests');
    });

    it('sends the bare settings URL to the first tab', function() {
        $this->get('admin/link-audit/settings')->assertRedirect();
    });

    it('refuses a reader who is not an admin', function() {
        $user = UserFactory::factory()->create();

        Craft::$app->getUserPermissions()->saveUserPermissions((int)$user->id, [
            'accesscp',
            'accessplugin-link-audit',
            'editsite:' . Craft::$app->getSites()->getPrimarySite()->uid,
            BaseController::PERMISSION_VIEW_REPORTS,
        ]);

        $this->actingAs($user);

        expect(fn() => $this->get('admin/link-audit/settings/http'))
            ->toThrow(ForbiddenHttpException::class);
    });

    it('refuses a save from somebody who is not an admin', function() {
        $user = UserFactory::factory()->create();

        Craft::$app->getUserPermissions()->saveUserPermissions((int)$user->id, [
            'accesscp',
            'accessplugin-link-audit',
            'editsite:' . Craft::$app->getSites()->getPrimarySite()->uid,
            BaseController::PERMISSION_VIEW_REPORTS,
            BaseController::PERMISSION_RUN_SCANS,
        ]);

        $this->actingAs($user);

        expect(fn() => $this->post('actions/link-audit/settings/save-http', [
            'settings' => ['concurrency' => '50'],
        ]))->toThrow(ForbiddenHttpException::class);
    });
});
