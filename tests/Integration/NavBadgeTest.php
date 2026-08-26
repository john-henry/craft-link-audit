<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\helpers\Cp;
use craft\helpers\Db;
use johnhenry\linkaudit\controllers\BaseController;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\helpers\UrlNormaliser;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\Verdict;
use johnhenry\linkaudit\records\ReferenceRecord;
use johnhenry\linkaudit\records\UrlRecord;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// The counts on the nav
//
// The navigation renders on every control panel page there is, so the two
// things worth proving are that the numbers are right and that reading them is
// nearly free. The cache is not an implementation detail here: it is the whole
// reason this is allowed to hang off the chrome at all.
//
// Helper names carry a `navBadge` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/**
 * Empties the two tables the counts are read from.
 *
 * The suite runs against a development database carrying whatever the last real
 * scan left behind, and "no badge at zero" cannot be asserted next to somebody's
 * genuinely broken links. Inside the test's own transaction, so the developer's
 * data comes back on rollback.
 */
function navBadgeClearUrls(): void
{
    $db = Craft::$app->getDb();
    $db->createCommand()->delete(ReferenceRecord::tableName())->execute();
    $db->createCommand()->delete(UrlRecord::tableName())->execute();
    // Dismissals too: the ignored badge counts those, and a decision left
    // behind by the development database would badge the screen from beyond
    // its cleared URL row.
    $db->createCommand()->delete(johnhenry\linkaudit\records\IgnoreRecord::tableName())->execute();

    LinkAudit::getInstance()->getReportService()->invalidateCounts();
}

/** The site the control panel is currently working with. */
function navBadgeSiteId(): int
{
    $site = Cp::requestedSite();

    return (int)($site->id ?? Craft::$app->getSites()->getPrimarySite()->id);
}

/**
 * Stores one URL carrying the given verdict, referenced once on the site.
 *
 * A reference is what makes a URL this site's problem, so a URL without one is
 * counted by nothing.
 */
function navBadgeSeed(string $url, Verdict $verdict, ?int $siteId = null): int
{
    $siteId ??= navBadgeSiteId();
    $store = LinkAudit::getInstance()->getUrlStore();
    $urlId = $store->upsert($url, false);

    $store->recordVerdict($urlId, $verdict);
    $store->replaceReferencesFor(
        (int)UserFactory::factory()->create()->id,
        $siteId,
        [['urlId' => $urlId, 'elementType' => craft\elements\User::class]],
    );

    return $urlId;
}

/**
 * Puts a user holding the given plugin permissions behind the rest of the test.
 *
 * Craft's own gates come as standard, the primary site included: the nav item is
 * built for whoever is looking at it, and a user Craft would not let into the
 * control panel at all proves nothing about a badge.
 */
function navBadgeReader(array $permissions = [BaseController::PERMISSION_VIEW_REPORTS]): void
{
    $user = UserFactory::factory()->create();

    Craft::$app->getUserPermissions()->saveUserPermissions((int)$user->id, array_merge([
        'accesscp',
        'accessplugin-link-audit',
        'editsite:' . Craft::$app->getSites()->getPrimarySite()->uid,
    ], $permissions));

    test()->actingAs($user);
}

/** The plugin's nav item, as Craft would build it for the acting user. */
function navBadgeItem(): ?array
{
    return LinkAudit::getInstance()->getCpNavItem();
}

// ---------------------------------------------------------------------------
// The numbers
// ---------------------------------------------------------------------------

it('badges the section with the broken count and each list with its own', function() {
    navBadgeReader();
    navBadgeClearUrls();

    foreach (['one', 'two', 'three'] as $path) {
        navBadgeSeed(
            "https://example.com/nav-badge-broken-$path",
            new Verdict(status: UrlStatus::Broken, httpStatus: 404),
        );
    }

    navBadgeSeed(
        'https://example.com/nav-badge-moved',
        new Verdict(status: UrlStatus::Redirect, httpStatus: 301, redirectPermanent: true),
    );
    navBadgeSeed(
        'https://example.com/nav-badge-blocked',
        new Verdict(status: UrlStatus::Blocked, httpStatus: 403),
    );
    navBadgeSeed(
        'https://example.com/nav-badge-quiet',
        new Verdict(status: UrlStatus::Ignored, reason: Verdict::REASON_IGNORED),
    );
    navBadgeSeed(
        'https://example.com/nav-badge-silent',
        new Verdict(status: UrlStatus::Unreachable, reason: Verdict::REASON_DNS),
    );

    $item = navBadgeItem();

    expect($item)->not->toBeNull()
        ->and($item['badgeCount'])->toBe(3)
        ->and($item['subnav']['broken']['badgeCount'])->toBe(3)
        ->and($item['subnav']['redirects']['badgeCount'])->toBe(1)
        ->and($item['subnav']['blocked']['badgeCount'])->toBe(1)
        ->and($item['subnav']['no-answer']['badgeCount'])->toBe(1)
        // A URL holding the ignored verdict without a dismissal behind it, a
        // rule-quieted one or a skipped scheme, is not on the Ignored screen,
        // so it earns that screen no badge.
        ->and($item['subnav']['ignored'])->not->toHaveKey('badgeCount')
        // The overview is not a list of anything, so there is nothing to count
        // on it.
        ->and($item['subnav']['overview'])->not->toHaveKey('badgeCount');
});

it('counts only the permanent redirects, since a temporary one is nobody here to fix', function() {
    navBadgeReader();
    navBadgeClearUrls();

    navBadgeSeed(
        'https://example.com/nav-badge-moved-for-good',
        new Verdict(status: UrlStatus::Redirect, httpStatus: 301, redirectPermanent: true),
    );
    navBadgeSeed(
        'https://example.com/nav-badge-moved-for-now',
        new Verdict(status: UrlStatus::Redirect, httpStatus: 302, redirectPermanent: false),
    );

    expect(navBadgeItem()['subnav']['redirects']['badgeCount'])->toBe(1);
});

it('leaves the badge off altogether rather than showing a nought', function() {
    navBadgeReader();
    navBadgeClearUrls();

    $item = navBadgeItem();

    expect($item)->not->toBeNull()
        ->and($item)->not->toHaveKey('badgeCount');

    foreach (['overview', 'broken', 'redirects', 'blocked', 'no-answer', 'ignored'] as $key) {
        expect($item['subnav'][$key])->not->toHaveKey('badgeCount');
    }
});

it('counts a site at a time, so another site\'s broken links are not this one\'s', function() {
    $sites = Craft::$app->getSites()->getAllSites();

    if (count($sites) < 2) {
        test()->markTestSkipped('This install has one site.');
    }

    navBadgeReader();
    navBadgeClearUrls();

    $other = array_values(array_filter(
        $sites,
        static fn(craft\models\Site $site): bool => (int)$site->id !== navBadgeSiteId(),
    ))[0];

    navBadgeSeed(
        'https://example.com/nav-badge-elsewhere',
        new Verdict(status: UrlStatus::Broken, httpStatus: 404),
        (int)$other->id,
    );

    expect(navBadgeItem())->not->toHaveKey('badgeCount');
});

// ---------------------------------------------------------------------------
// What it costs
// ---------------------------------------------------------------------------

it('reads the counts once and keeps the answer', function() {
    navBadgeReader();
    navBadgeClearUrls();

    navBadgeSeed(
        'https://example.com/nav-badge-cached',
        new Verdict(status: UrlStatus::Broken, httpStatus: 404),
    );

    expect(navBadgeItem()['badgeCount'])->toBe(1);

    // Written straight to the table, so nothing invalidates the stored count.
    // The badge holding still is the cache doing its job: the alternative is a
    // pair of COUNT queries on every control panel page load there is.
    Db::update(UrlRecord::tableName(), ['status' => UrlStatus::Ok->value], '');

    expect(navBadgeItem()['badgeCount'])->toBe(1);
});

it('shows a verdict recorded since the last build', function() {
    navBadgeReader();
    navBadgeClearUrls();

    $store = LinkAudit::getInstance()->getUrlStore();
    $urlId = $store->upsert('https://example.com/nav-badge-fresh', false);

    $store->replaceReferencesFor(
        (int)UserFactory::factory()->create()->id,
        navBadgeSiteId(),
        [['urlId' => $urlId, 'elementType' => craft\elements\User::class]],
    );

    // Stored while the URL is still pending, so there is a nought in the cache
    // for the verdict below to clear.
    expect(navBadgeItem())->not->toHaveKey('badgeCount');

    $store->recordVerdict($urlId, new Verdict(status: UrlStatus::Broken, httpStatus: 404));

    expect(navBadgeItem()['badgeCount'])->toBe(1);
});

it('drops an ignored URL off the broken badge the moment it is ignored', function() {
    navBadgeReader();
    navBadgeClearUrls();

    $url = 'https://example.com/nav-badge-let-it-go';
    navBadgeSeed($url, new Verdict(status: UrlStatus::Broken, httpStatus: 404));

    expect(navBadgeItem()['badgeCount'])->toBe(1);

    LinkAudit::getInstance()->getIgnoreService()->ignoreUrl(UrlNormaliser::hash($url));

    $item = navBadgeItem();

    expect($item)->not->toHaveKey('badgeCount')
        ->and($item['subnav']['ignored']['badgeCount'])->toBe(1);
});

// ---------------------------------------------------------------------------
// Who gets told
// ---------------------------------------------------------------------------

it('tells somebody who may not read the reports nothing at all', function() {
    navBadgeClearUrls();

    navBadgeSeed(
        'https://example.com/nav-badge-not-your-business',
        new Verdict(status: UrlStatus::Broken, httpStatus: 404),
    );

    navBadgeReader([]);

    // The whole item goes, badge and all: a count is a fact about the content,
    // and somebody refused the reports has no more business with the number
    // than with the list behind it.
    expect(navBadgeItem())->toBeNull();
});
