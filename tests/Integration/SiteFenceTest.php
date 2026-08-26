<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\elements\User;
use johnhenry\linkaudit\controllers\BaseController;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\helpers\UrlNormaliser;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\Verdict;
use markhuot\craftpest\factories\User as UserFactory;
use yii\web\NotFoundHttpException;

// ---------------------------------------------------------------------------
// The site fence on one URL
//
// A URL row is global and a hash is a bare string in a request, so nothing about
// the hash itself says which site the URL belongs to. What ties a URL to a site
// is the references, and that is what the fence reads: a reader entitled to one
// site may not read the verdict on, or act on, a URL that only another site's
// content points at.
//
// The refusal has to be the same 404 an unrecognised hash gets, or the fence
// answers the very question it exists to refuse.
//
// Helper names carry a `fence` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/** The site the fenced reader is not entitled to. */
function fenceOtherSite(): craft\models\Site
{
    $primary = Craft::$app->getSites()->getPrimarySite();

    foreach (Craft::$app->getSites()->getAllSites() as $site) {
        if ((int)$site->id !== (int)$primary->id) {
            return $site;
        }
    }

    throw new RuntimeException(
        'The site fence tests need a second site. Run `ddev craft project-config/apply`.',
    );
}

/** A broken URL with one reference on the given site. */
function fenceSeed(string $url, int $siteId): string
{
    $store = LinkAudit::getInstance()->getUrlStore();
    $urlId = $store->upsert($url, false);

    $store->recordVerdict($urlId, new Verdict(status: UrlStatus::Broken, httpStatus: 404));
    $store->replaceReferencesFor(
        (int)UserFactory::factory()->create()->id,
        $siteId,
        [['urlId' => $urlId, 'elementType' => User::class]],
    );

    return UrlNormaliser::hash($url);
}

/** A reader entitled to the primary site and nothing else. */
function fenceReader(): User
{
    $user = UserFactory::factory()->create();

    Craft::$app->getUserPermissions()->saveUserPermissions((int)$user->id, [
        'accesscp',
        'accessplugin-link-audit',
        'editsite:' . Craft::$app->getSites()->getPrimarySite()->uid,
        BaseController::PERMISSION_VIEW_REPORTS,
        BaseController::PERMISSION_MANAGE_IGNORES,
        BaseController::PERMISSION_RUN_SCANS,
    ]);

    return $user;
}

describe('A URL only another site points at', function() {
    it('is a 404 on the detail page', function() {
        $hash = fenceSeed('https://example.com/fenced-detail', (int)fenceOtherSite()->id);

        $this->actingAs(fenceReader());

        expect(fn() => $this->get("admin/link-audit/url?hash=$hash"))
            ->toThrow(NotFoundHttpException::class);
    });

    it('is a 404 on an ignore, and is not ignored', function() {
        $url = 'https://example.com/fenced-ignore';
        $hash = fenceSeed($url, (int)fenceOtherSite()->id);

        $this->actingAs(fenceReader());

        expect(fn() => $this->post('actions/link-audit/url-actions/ignore', ['hash' => $hash]))
            ->toThrow(NotFoundHttpException::class);

        $row = LinkAudit::getInstance()->getReportService()->urlByHash($hash);

        expect((string)$row['status'])->not->toBe(UrlStatus::Ignored->value);
    });

    it('is a 404 on a recheck', function() {
        $hash = fenceSeed('https://example.com/fenced-recheck', (int)fenceOtherSite()->id);

        $this->actingAs(fenceReader());

        expect(fn() => $this->post('actions/link-audit/url-actions/recheck', ['hash' => $hash]))
            ->toThrow(NotFoundHttpException::class);
    });

    it('is a 404 on a restore', function() {
        $url = 'https://example.com/fenced-restore';
        $hash = fenceSeed($url, (int)fenceOtherSite()->id);

        LinkAudit::getInstance()->getIgnoreService()->ignoreUrl($hash);

        $this->actingAs(fenceReader());

        expect(fn() => $this->post('actions/link-audit/url-actions/restore', ['hash' => $hash]))
            ->toThrow(NotFoundHttpException::class);
    });

    it('is left off the ignored screen', function() {
        $hash = fenceSeed('https://example.com/fenced-from-the-ignored-list', (int)fenceOtherSite()->id);

        LinkAudit::getInstance()->getIgnoreService()->ignoreUrl($hash);

        $this->actingAs(fenceReader());

        $this->get('admin/link-audit/ignored')
            ->assertOk()
            ->assertDontSee('fenced-from-the-ignored-list');
    });
});

describe('A reference only another site points at', function() {
    it('is a 404 on the reference-location endpoint', function() {
        $store = LinkAudit::getInstance()->getUrlStore();
        $urlId = $store->upsert('https://example.com/fenced-reference', false);
        $store->recordVerdict($urlId, new Verdict(status: UrlStatus::Broken, httpStatus: 404));
        $store->replaceReferencesFor(
            (int)UserFactory::factory()->create()->id,
            (int)fenceOtherSite()->id,
            [['urlId' => $urlId, 'elementType' => User::class]],
        );
        $referenceId = (int)(new craft\db\Query())
            ->select(['id'])
            ->from([johnhenry\linkaudit\records\ReferenceRecord::tableName()])
            ->where(['urlId' => $urlId])
            ->scalar();

        $this->actingAs(fenceReader());

        expect(fn() => test()->http('get', "actions/link-audit/urls/reference-location?id=$referenceId")
            ->addHeader('Accept', 'application/json')
            ->send())->toThrow(NotFoundHttpException::class);
    });

    it('answers an unknown reference id with the same 404', function() {
        $this->actingAs(fenceReader());

        expect(fn() => test()->http('get', 'actions/link-audit/urls/reference-location?id=99999999')
            ->addHeader('Accept', 'application/json')
            ->send())->toThrow(NotFoundHttpException::class);
    });
});

describe('A page recheck', function() {
    it('is a 404 for an element the reader may not see', function() {
        $this->actingAs(fenceReader());

        expect(fn() => test()->http('post', 'actions/link-audit/url-actions/recheck-element')
            ->withCsrfToken()
            ->addHeader('Accept', 'application/json')
            ->setBody([
                'elementId' => 99999999,
                'siteId' => (int)Craft::$app->getSites()->getPrimarySite()->id,
            ])
            ->send())->toThrow(NotFoundHttpException::class);
    });

    it('refuses a site the reader may not edit', function() {
        $this->actingAs(fenceReader());

        expect(fn() => test()->http('post', 'actions/link-audit/url-actions/recheck-element')
            ->withCsrfToken()
            ->addHeader('Accept', 'application/json')
            ->setBody([
                'elementId' => (int)UserFactory::factory()->create()->id,
                'siteId' => (int)fenceOtherSite()->id,
            ])
            ->send())->toThrow(yii\web\ForbiddenHttpException::class);
    });
});

describe('A URL this reader\'s own site points at', function() {
    it('opens on the detail page', function() {
        $hash = fenceSeed(
            'https://example.com/mine-to-read',
            (int)Craft::$app->getSites()->getPrimarySite()->id,
        );

        $this->actingAs(fenceReader());

        $this->get("admin/link-audit/url?hash=$hash")
            ->assertOk()
            ->assertSee('mine-to-read');
    });

    it('can be ignored', function() {
        $hash = fenceSeed(
            'https://example.com/mine-to-ignore',
            (int)Craft::$app->getSites()->getPrimarySite()->id,
        );

        $this->actingAs(fenceReader());

        $this->post('actions/link-audit/url-actions/ignore', [
            'hash' => $hash,
            'redirect' => Craft::$app->getSecurity()->hashData('link-audit/ignored'),
        ])->assertRedirect();

        $row = LinkAudit::getInstance()->getReportService()->urlByHash($hash);

        expect((string)$row['status'])->toBe(UrlStatus::Ignored->value);
    });
});

describe('An admin', function() {
    it('reads a URL on any site at all', function() {
        $hash = fenceSeed('https://example.com/admins-see-everything', (int)fenceOtherSite()->id);

        $this->actingAs(UserFactory::factory()->admin(true)->create());

        $this->get("admin/link-audit/url?hash=$hash")
            ->assertOk()
            ->assertSee('admins-see-everything');
    });
});
