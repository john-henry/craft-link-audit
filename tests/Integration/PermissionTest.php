<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\linkaudit\controllers\BaseController;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\helpers\UrlNormaliser;
use johnhenry\linkaudit\LinkAudit;
use markhuot\craftpest\factories\User as UserFactory;
use yii\web\ForbiddenHttpException;

// ---------------------------------------------------------------------------
// Who may do what
//
// Three tiers, and the one worth guarding hardest is the middle one: reading a
// report is not permission to start work on every host in the content, and a
// scan is a lot of outbound requests made in this installation's name.
//
// Helper names carry a `perm` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/**
 * A non-admin user holding exactly the given plugin permissions.
 *
 * Craft's own gates come as standard, since without them the control panel
 * refuses at the door and the plugin's gates are never reached, which would make
 * every one of these tests pass for the wrong reason. That includes the primary
 * site: a report is content, so the plugin fences it with the same permission
 * Craft fences content with, and a user who may edit no site may read no report.
 */
function permUserWith(array $permissions): craft\elements\User
{
    $user = UserFactory::factory()->create();

    Craft::$app->getUserPermissions()->saveUserPermissions((int)$user->id, array_merge([
        'accesscp',
        'accessplugin-link-audit',
        'editsite:' . Craft::$app->getSites()->getPrimarySite()->uid,
    ], $permissions));

    return $user;
}

/** The source of one of the plugin's controllers. */
function permSource(string $class): string
{
    return (string)file_get_contents(dirname(__DIR__, 2) . "/src/controllers/$class.php");
}

/**
 * How many scan rows there are.
 *
 * Counted rather than asserted absolutely: this suite runs against a
 * development database that carries whatever the last real scan left behind.
 */
function permScanCount(): int
{
    return (int)(new craft\db\Query())
        ->from([johnhenry\linkaudit\records\ScanRecord::tableName()])
        ->count();
}

/** Marks every scan the development database left running as finished. */
function permSettleRunningScans(): void
{
    craft\helpers\Db::update(
        johnhenry\linkaudit\records\ScanRecord::tableName(),
        ['status' => johnhenry\linkaudit\enums\ScanStatus::Complete->value],
        ['not', ['status' => johnhenry\linkaudit\enums\ScanStatus::Complete->value]],
    );
}

/** Every controller the plugin ships, as file name to source. */
function permAllSources(): array
{
    $sources = [];

    foreach ((array)glob(dirname(__DIR__, 2) . '/src/controllers/*.php') as $path) {
        $sources[basename((string)$path)] = (string)file_get_contents((string)$path);
    }

    return $sources;
}

describe('Running a scan', function() {
    it('refuses a reader who may not run scans', function() {
        $this->actingAs(permUserWith([BaseController::PERMISSION_VIEW_REPORTS]));

        expect(fn() => $this->post('actions/link-audit/scans/start', [
            'mode' => 'full',
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
        ]))->toThrow(ForbiddenHttpException::class);
    });

    it('refuses somebody with no link audit permissions at all', function() {
        $this->actingAs(permUserWith([]));

        expect(fn() => $this->post('actions/link-audit/scans/start', [
            'mode' => 'full',
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
        ]))->toThrow(ForbiddenHttpException::class);
    });

    it('lets a reader who may run scans queue one', function() {
        $this->actingAs(permUserWith([
            BaseController::PERMISSION_VIEW_REPORTS,
            BaseController::PERMISSION_RUN_SCANS,
        ]));

        // A run that is still going refuses another, and this suite runs against
        // a development database that may well be carrying one. What is under
        // test here is the permission gate, so the history is settled first.
        permSettleRunningScans();

        $before = permScanCount();

        $this->post('actions/link-audit/scans/start', [
            'mode' => 'full',
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
            'redirect' => Craft::$app->getSecurity()->hashData('link-audit'),
        ])->assertRedirect();

        expect(permScanCount())->toBe($before + 1);
    });
});

describe('Acting on one URL', function() {
    // A hash nobody has seen is enough for these: the permission gate runs in
    // beforeAction and in the first two lines of the action, long before
    // anything is looked up, so a refusal here is the gate refusing and not the
    // lookup failing.
    $hash = str_repeat('f', 40);

    it('refuses a recheck from somebody who may not run scans', function() use ($hash) {
        $this->actingAs(permUserWith([BaseController::PERMISSION_VIEW_REPORTS]));

        expect(fn() => $this->post('actions/link-audit/url-actions/recheck', ['hash' => $hash]))
            ->toThrow(ForbiddenHttpException::class);
    });

    it('refuses an ignore from somebody who may not manage ignores', function() use ($hash) {
        $this->actingAs(permUserWith([
            BaseController::PERMISSION_VIEW_REPORTS,
            BaseController::PERMISSION_RUN_SCANS,
        ]));

        expect(fn() => $this->post('actions/link-audit/url-actions/ignore', ['hash' => $hash]))
            ->toThrow(ForbiddenHttpException::class);
    });

    it('refuses a restore from somebody who may not manage ignores', function() use ($hash) {
        $this->actingAs(permUserWith([BaseController::PERMISSION_VIEW_REPORTS]));

        expect(fn() => $this->post('actions/link-audit/url-actions/restore', ['hash' => $hash]))
            ->toThrow(ForbiddenHttpException::class);
    });

    it('lets somebody who may manage ignores ignore a URL', function() {
        $store = LinkAudit::getInstance()->getUrlStore();
        $url = 'https://example.com/permission-test-ignore';
        $urlId = $store->upsert($url, false);
        $hash = UrlNormaliser::hash($url);

        // Referenced on the primary site, because the action is fenced by the
        // reader's own sites: a URL nothing they can read points at is refused
        // before the ignore is ever written.
        $store->replaceReferencesFor(
            (int)UserFactory::factory()->create()->id,
            (int)Craft::$app->getSites()->getPrimarySite()->id,
            [['urlId' => $urlId, 'elementType' => craft\elements\User::class]],
        );

        $this->actingAs(permUserWith([
            BaseController::PERMISSION_VIEW_REPORTS,
            BaseController::PERMISSION_MANAGE_IGNORES,
        ]));

        $this->post('actions/link-audit/url-actions/ignore', [
            'hash' => $hash,
            'note' => 'Not worth chasing.',
            'redirect' => Craft::$app->getSecurity()->hashData('link-audit/ignored'),
        ])->assertRedirect();

        $status = (new craft\db\Query())
            ->select(['status'])
            ->from([johnhenry\linkaudit\records\UrlRecord::tableName()])
            ->where(['id' => $urlId])
            ->scalar();

        expect($status)->toBe(UrlStatus::Ignored->value);
    });
});

describe('Reading a report', function() {
    it('refuses somebody without the report permission', function() {
        $this->actingAs(permUserWith([]));

        expect(fn() => $this->get('admin/link-audit'))
            ->toThrow(ForbiddenHttpException::class);
    });

    it('lets a reader in', function() {
        $this->actingAs(permUserWith([BaseController::PERMISSION_VIEW_REPORTS]));

        $this->get('admin/link-audit')->assertOk();
    });

    it('hides the scan buttons from a reader who may not run scans', function() {
        $this->actingAs(permUserWith([BaseController::PERMISSION_VIEW_REPORTS]));

        $this->get('admin/link-audit')->assertDontSee('Run full scan');
    });
});

describe('The row actions on a list', function() {
    /**
     * A broken URL with one reference on the primary site, so a list screen has
     * something to show.
     */
    $seed = function(string $url): string {
        $store = LinkAudit::getInstance()->getUrlStore();
        $urlId = $store->upsert($url, false);

        $store->recordVerdict($urlId, new johnhenry\linkaudit\models\Verdict(
            status: UrlStatus::Broken,
            httpStatus: 404,
        ));
        $store->replaceReferencesFor(
            (int)UserFactory::factory()->create()->id,
            (int)Craft::$app->getSites()->getPrimarySite()->id,
            [['urlId' => $urlId, 'elementType' => craft\elements\User::class]],
        );

        return UrlNormaliser::hash($url);
    };

    /** The row the table endpoint gives back for one hash. */
    $rowFor = function(string $hash): ?array {
        $query = http_build_query([
            'verdict' => UrlStatus::Broken->value,
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
            'per_page' => 200,
        ]);

        $json = (array)test()->http('get', "actions/link-audit/urls/table?$query")
            ->addHeader('Accept', 'application/json')
            ->send()
            ->getJsonContent()
            ->json();

        foreach ($json['data'] as $row) {
            if (str_contains((string)$row['url']['detailUrl'], $hash)) {
                return $row;
            }
        }

        return null;
    };

    it('sends no action buttons to a reader who may only read', function() use ($seed, $rowFor) {
        $hash = $seed('https://example.com/no-actions-for-you');

        $this->actingAs(permUserWith([BaseController::PERMISSION_VIEW_REPORTS]));

        $row = $rowFor($hash);

        expect($row)->not->toBeNull()
            ->and($row['actions'])->toBeNull();
    });

    it('sends only the buttons the reader is entitled to', function() use ($seed, $rowFor) {
        $hash = $seed('https://example.com/some-actions-for-you');

        $this->actingAs(permUserWith([
            BaseController::PERMISSION_VIEW_REPORTS,
            BaseController::PERMISSION_MANAGE_IGNORES,
        ]));

        $row = $rowFor($hash);

        expect($row['actions'])->not->toBeNull()
            ->and($row['actions']['ignore'])->toBeTrue()
            ->and($row['actions']['recheck'])->toBeFalse()
            ->and($row['actions']['hash'])->toBe($hash);
    });
});

describe('The controllers themselves', function() {
    // The handles are contract strings shared by the registration, the nav item
    // and the gates. A literal in three places drifts silently: a typo still
    // passes for an admin, who holds every permission, and refuses everybody
    // else. Constants are the only way to make that a compile-time problem.
    it('never writes a permission handle out as a literal', function() {
        foreach (permAllSources() as $file => $source) {
            expect([$file, preg_match('/requirePermission\(\s*[\'"]/', $source)])->toBe([$file, 0]);
        }
    });

    it('leaves nothing open to anonymous requests', function() {
        expect(permSource('BaseController'))
            ->toContain('protected array|bool|int $allowAnonymous = false;');

        // Only the base class declares it. A subclass redeclaring it is how a
        // controller quietly opens itself up.
        foreach (permAllSources() as $file => $source) {
            if ($file === 'BaseController.php') {
                continue;
            }

            expect([$file, str_contains($source, '$allowAnonymous')])->toBe([$file, false]);
        }
    });
});
