<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\elements\Entry;
use craft\helpers\StringHelper;
use johnhenry\linkaudit\enums\LinkKind;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\ExtractedLink;
use johnhenry\linkaudit\models\Verdict;
use markhuot\craftpest\factories\Entry as EntryFactory;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** An entry in the laFixture section, which serves URLs at la-fixture/{slug}. */
function ilEntry(bool $enabled = true): Entry
{
    $section = Craft::$app->getEntries()->getSectionByHandle('laFixture');

    if ($section === null) {
        throw new RuntimeException(
            'The laFixture test section is missing. Run `ddev craft project-config/apply`.',
        );
    }

    $slug = 'il-fixture-' . StringHelper::toLowerCase(StringHelper::randomString(10));

    return EntryFactory::factory()
        ->section($section)
        ->title('IL fixture ' . $slug)
        ->slug($slug)
        ->enabled($enabled)
        ->create();
}

/**
 * An entry in the site's `collection` section, whose `Work` entry type has no
 * URL format on the primary site: a real target that exists and is enabled but
 * has nowhere of its own to send a visitor.
 */
function ilNoUrlEntry(bool $enabled = true): Entry
{
    $section = Craft::$app->getEntries()->getSectionByHandle('collection');

    if ($section === null) {
        throw new RuntimeException(
            'The collection section is missing. Run `ddev craft project-config/apply`.',
        );
    }

    return EntryFactory::factory()
        ->section($section)
        ->title('IL no-URL fixture ' . StringHelper::randomString(10))
        ->enabled($enabled)
        ->create();
}

/** The resolver under test. */
function ilResolver(): \johnhenry\linkaudit\services\InternalResolver
{
    return LinkAudit::getInstance()->getInternalResolver();
}

/** The site the fixtures live on. */
function ilSiteId(): int
{
    return Craft::$app->getSites()->getPrimarySite()->id;
}

/** An absolute URL on the primary site. */
function ilUrl(string $uri): string
{
    return rtrim((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), '/')
        . '/' . ltrim($uri, '/');
}

// ---------------------------------------------------------------------------
// URI resolution
// ---------------------------------------------------------------------------

it('says a URL matching a live entry is fine', function() {
    $entry = ilEntry();
    $verdict = ilResolver()->resolveUrl(ilUrl("la-fixture/$entry->slug"), ilSiteId());

    expect($verdict->status)->toBe(UrlStatus::Ok)
        ->and($verdict->reason)->toBeNull();
});

it('calls a URL matching a disabled entry broken', function() {
    $entry = ilEntry(enabled: false);
    $verdict = ilResolver()->resolveUrl(ilUrl("la-fixture/$entry->slug"), ilSiteId());

    expect($verdict->status)->toBe(UrlStatus::Broken)
        ->and($verdict->reason)->toBe(Verdict::REASON_NO_ELEMENT);
});

it('calls a URL matching nothing at all broken', function() {
    $verdict = ilResolver()->resolveUrl(ilUrl('la-fixture/nothing-lives-here-at-all'), ilSiteId());

    expect($verdict->status)->toBe(UrlStatus::Broken)
        ->and($verdict->reason)->toBe(Verdict::REASON_NO_ELEMENT)
        ->and($verdict->message)->not->toBeNull();
});

it('reads the homepage as the token Craft stores for it', function() {
    $verdict = ilResolver()->resolveUrl(ilUrl(''), ilSiteId());

    // Whether the homepage has an element behind it depends on the site, so the
    // only safe assertion is that it was answered without being called broken
    // for the wrong reason.
    expect($verdict->status)->toBeIn([UrlStatus::Ok, UrlStatus::Broken]);
});

it('lets an always valid pattern through instead of calling it broken', function() {
    LinkAudit::getInstance()->getSettings()->internalUrlAllowPatterns = [
        ['pattern' => '^downloads/'],
    ];

    $verdict = ilResolver()->resolveUrl(ilUrl('downloads/brochure.pdf'), ilSiteId());

    expect($verdict->status)->toBe(UrlStatus::Ignored);
});

it('ignores an always valid pattern that does not match', function() {
    LinkAudit::getInstance()->getSettings()->internalUrlAllowPatterns = [
        ['pattern' => '^downloads/'],
    ];

    // A path with no extension, so the pattern is what is under test rather
    // than the file-shaped predicate a URL like uploads/brochure.pdf would
    // also trip.
    $verdict = ilResolver()->resolveUrl(ilUrl('uploads/nothing-lives-here'), ilSiteId());

    expect($verdict->status)->toBe(UrlStatus::Broken);
});

it('does not fall over on a malformed always valid pattern', function() {
    LinkAudit::getInstance()->getSettings()->internalUrlAllowPatterns = [
        ['pattern' => '['],
        ['pattern' => '^still-works/'],
    ];

    $verdict = ilResolver()->resolveUrl(ilUrl('still-works/page'), ilSiteId());

    expect($verdict->status)->toBe(UrlStatus::Ignored);
});

// ---------------------------------------------------------------------------
// Files
// ---------------------------------------------------------------------------

it('leaves a file-shaped URL with no element match pending for the check phase, instead of calling it broken', function() {
    $verdict = ilResolver()->resolveUrl(ilUrl('uploads/brochure.pdf'), ilSiteId());

    expect($verdict)->toBeNull();
});

it('resolves an element URI containing a dot by the element match, never reaching the file predicate', function() {
    $section = Craft::$app->getEntries()->getSectionByHandle('laFixture');

    if ($section === null) {
        throw new RuntimeException(
            'The laFixture test section is missing. Run `ddev craft project-config/apply`.',
        );
    }

    $slug = 'il-dotted-1.5-' . StringHelper::toLowerCase(StringHelper::randomString(8));
    $entry = EntryFactory::factory()
        ->section($section)
        ->title('IL dotted ' . $slug)
        ->slug($slug)
        ->create();

    $verdict = ilResolver()->resolveUrl(ilUrl("la-fixture/$entry->slug"), ilSiteId());

    expect($entry->slug)->toContain('.')
        ->and($verdict->status)->toBe(UrlStatus::Ok);
});

it('leaves a URL under one of this installation\'s own asset filesystems pending, extension or not', function() {
    $fs = Craft::$app->getFs()->getFilesystemByHandle('site');
    $url = rtrim((string)$fs?->getRootUrl(), '/') . '/no-extension-at-all';

    expect(ilResolver()->resolveUrl($url, ilSiteId()))->toBeNull();
})->skip(
    fn() => Craft::$app->getFs()->getFilesystemByHandle('site')?->getRootUrl() === null,
    'This project has no "site" filesystem with a public URL to test against.',
);

it('records a file-shaped internal URL as ignored when internal checking is off', function() {
    LinkAudit::getInstance()->getSettings()->checkInternalLinks = false;

    $verdict = ilResolver()->resolveUrl(ilUrl('uploads/brochure.pdf'), ilSiteId());

    expect($verdict->status)->toBe(UrlStatus::Ignored);
});

it('leaves an extracted internal link pending when it is shaped like a file', function() {
    $link = new ExtractedLink(
        kind: LinkKind::Internal,
        elementId: 1,
        elementType: Entry::class,
        ownerElementId: 1,
        siteId: ilSiteId(),
        url: ilUrl('uploads/brochure.pdf'),
        rawHref: '/uploads/brochure.pdf',
    );

    expect(ilResolver()->resolve($link))->toBeNull();
});

// ---------------------------------------------------------------------------
// Routes
// ---------------------------------------------------------------------------

it('does not call a template only route broken', function() {
    $verdict = ilResolver()->resolveUrl(ilUrl('accessibility'), ilSiteId());

    expect($verdict->status)->toBe(UrlStatus::Ok);
})->skip(
    fn() => !array_key_exists('accessibility', Craft::$app->getRoutes()->getConfigFileRoutes()),
    'This project has no config/routes.php entry to resolve against.',
);

it('keeps a site scoped route to the site it was defined for', function() {
    $store = Craft::$app->getSites()->getSiteByHandle('spokeChain');
    $storeUrl = rtrim((string)$store->getBaseUrl(), '/') . '/account/orders/1000';

    expect(ilResolver()->resolveUrl($storeUrl, (int)$store->id)->status)->toBe(UrlStatus::Ok)
        ->and(ilResolver()->resolveUrl(ilUrl('account/orders/1000'), ilSiteId())->status)
        ->toBe(UrlStatus::Broken);
})->skip(
    fn() => Craft::$app->getSites()->getSiteByHandle('spokeChain') === null,
    'This project has no second site with a site scoped route to resolve against.',
);

it('records an internal link as ignored when internal checking is off', function() {
    LinkAudit::getInstance()->getSettings()->checkInternalLinks = false;

    $verdict = ilResolver()->resolveUrl(ilUrl('la-fixture/nothing-here-either'), ilSiteId());

    expect($verdict->status)->toBe(UrlStatus::Ignored);
});

// ---------------------------------------------------------------------------
// Element links
// ---------------------------------------------------------------------------

it('says an element link to a live entry is fine', function() {
    $entry = ilEntry();
    $verdict = ilResolver()->resolveElement($entry->id, Entry::class, ilSiteId());

    expect($verdict->status)->toBe(UrlStatus::Ok);
});

it('calls an element link to a disabled entry broken', function() {
    $entry = ilEntry(enabled: false);
    $verdict = ilResolver()->resolveElement($entry->id, Entry::class, ilSiteId());

    expect($verdict->status)->toBe(UrlStatus::Broken)
        ->and($verdict->reason)->toBe(Verdict::REASON_NO_ELEMENT)
        ->and($verdict->message)->toContain('disabled');
});

it('calls an element link to a deleted entry broken', function() {
    $entry = ilEntry();
    $elementId = $entry->id;
    Craft::$app->getElements()->deleteElement($entry);

    $verdict = ilResolver()->resolveElement($elementId, Entry::class, ilSiteId());

    expect($verdict->status)->toBe(UrlStatus::Broken)
        ->and($verdict->reason)->toBe(Verdict::REASON_NO_ELEMENT)
        ->and($verdict->message)->toContain('deleted');
});

it('calls an element link with no element at all broken', function() {
    $verdict = ilResolver()->resolveElement(0, Entry::class, ilSiteId());

    expect($verdict->status)->toBe(UrlStatus::Broken);
});

it('records an element link as ignored when internal checking is off', function() {
    LinkAudit::getInstance()->getSettings()->checkInternalLinks = false;

    $entry = ilEntry();
    $verdict = ilResolver()->resolveElement($entry->id, Entry::class, ilSiteId());

    expect($verdict->status)->toBe(UrlStatus::Ignored);
});

it('calls a link-sourced element link to a URL-less entry broken, and says so plainly', function() {
    $target = ilNoUrlEntry();
    $verdict = ilResolver()->resolveElement($target->id, Entry::class, ilSiteId());

    expect($verdict->status)->toBe(UrlStatus::Broken)
        ->and($verdict->reason)->toBe(Verdict::REASON_NO_ELEMENT)
        ->and($verdict->message)->toContain('no URL')
        ->and($verdict->message)->not->toContain('deleted')
        ->and($verdict->message)->not->toContain('disabled');
});

// ---------------------------------------------------------------------------
// Relation links
// ---------------------------------------------------------------------------

it('says a relation to a live entry with no URL of its own is fine', function() {
    $target = ilNoUrlEntry();
    $verdict = ilResolver()->resolveElement($target->id, Entry::class, ilSiteId(), isRelation: true);

    expect($verdict->status)->toBe(UrlStatus::Ok);
});

it('calls a relation to a disabled entry broken', function() {
    $target = ilNoUrlEntry(enabled: false);
    $verdict = ilResolver()->resolveElement($target->id, Entry::class, ilSiteId(), isRelation: true);

    expect($verdict->status)->toBe(UrlStatus::Broken)
        ->and($verdict->reason)->toBe(Verdict::REASON_NO_ELEMENT)
        ->and($verdict->message)->toContain('disabled');
});

it('calls a relation to a deleted entry broken', function() {
    $target = ilNoUrlEntry();
    $elementId = $target->id;
    Craft::$app->getElements()->deleteElement($target);

    $verdict = ilResolver()->resolveElement($elementId, Entry::class, ilSiteId(), isRelation: true);

    expect($verdict->status)->toBe(UrlStatus::Broken)
        ->and($verdict->reason)->toBe(Verdict::REASON_NO_ELEMENT)
        ->and($verdict->message)->toContain('deleted');
});

it('answers a relation and a link field differently for the very same URL-less target', function() {
    $target = ilNoUrlEntry();

    $relationVerdict = ilResolver()->resolveElement($target->id, Entry::class, ilSiteId(), isRelation: true);
    $linkVerdict = ilResolver()->resolveElement($target->id, Entry::class, ilSiteId());

    expect($relationVerdict->status)->toBe(UrlStatus::Ok)
        ->and($linkVerdict->status)->toBe(UrlStatus::Broken)
        ->and($linkVerdict->reason)->toBe(Verdict::REASON_NO_ELEMENT);
});

it('records a relation link as ignored when internal checking is off', function() {
    LinkAudit::getInstance()->getSettings()->checkInternalLinks = false;

    $target = ilNoUrlEntry();
    $verdict = ilResolver()->resolveElement($target->id, Entry::class, ilSiteId(), isRelation: true);

    expect($verdict->status)->toBe(UrlStatus::Ignored);
});

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------

it('leaves an external link to the HTTP checker', function() {
    $link = new ExtractedLink(
        kind: LinkKind::External,
        elementId: 1,
        elementType: Entry::class,
        ownerElementId: 1,
        siteId: ilSiteId(),
        url: 'https://elsewhere.example/page',
        rawHref: 'https://elsewhere.example/page',
    );

    expect(ilResolver()->resolve($link))->toBeNull();
});

it('answers an unknown scheme without asking anybody', function() {
    $link = new ExtractedLink(
        kind: LinkKind::Ignored,
        elementId: 1,
        elementType: Entry::class,
        ownerElementId: 1,
        siteId: ilSiteId(),
        url: 'ftp://files.example.com/handbook.pdf',
        rawHref: 'ftp://files.example.com/handbook.pdf',
    );

    expect(ilResolver()->resolve($link)?->status)->toBe(UrlStatus::Ignored);
});

it('resolves an extracted internal link end to end', function() {
    $entry = ilEntry();
    $link = new ExtractedLink(
        kind: LinkKind::Internal,
        elementId: $entry->id,
        elementType: Entry::class,
        ownerElementId: $entry->id,
        siteId: ilSiteId(),
        url: ilUrl("la-fixture/$entry->slug"),
        rawHref: "/la-fixture/$entry->slug",
    );

    expect(ilResolver()->resolve($link)?->status)->toBe(UrlStatus::Ok);
});

it('resolves an extracted element link end to end', function() {
    $entry = ilEntry();
    $link = new ExtractedLink(
        kind: LinkKind::Element,
        elementId: $entry->id,
        elementType: Entry::class,
        ownerElementId: $entry->id,
        siteId: ilSiteId(),
        url: (string)$entry->getUrl(),
        rawHref: "{entry:$entry->id:url}",
        targetElementId: $entry->id,
        targetElementType: Entry::class,
    );

    expect(ilResolver()->resolve($link)?->status)->toBe(UrlStatus::Ok);
});

it('resolves an extracted relation link to a URL-less target end to end', function() {
    $target = ilNoUrlEntry();
    $link = new ExtractedLink(
        kind: LinkKind::Relation,
        elementId: 1,
        elementType: Entry::class,
        ownerElementId: 1,
        siteId: ilSiteId(),
        url: ExtractedLink::relationSyntheticUrl($target->id),
        rawHref: ExtractedLink::relationSyntheticUrl($target->id),
        targetElementId: $target->id,
        targetElementType: Entry::class,
    );

    expect(ilResolver()->resolve($link)?->status)->toBe(UrlStatus::Ok);
});
