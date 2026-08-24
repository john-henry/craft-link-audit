<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\elements\Asset;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use johnhenry\linkaudit\enums\LinkKind;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\ExtractedLink;
use johnhenry\linkaudit\models\Verdict;
use johnhenry\linkaudit\services\LinkExtractor;
use markhuot\craftpest\factories\Asset as AssetFactory;
use markhuot\craftpest\factories\Entry as EntryFactory;
use verbb\hyper\models\LinkCollection;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * An entry in the dedicated laFixture section.
 *
 * The section, its entry types and its fields all live in project config rather
 * than being provisioned inside the test transaction: creating a section
 * auto-commits the transaction RefreshesDatabase relies on, and the teardown
 * afterwards throws an orphaned model error.
 */
function laEntry(?string $title = null): Entry
{
    $section = Craft::$app->getEntries()->getSectionByHandle('laFixture');

    if ($section === null) {
        throw new RuntimeException(
            'The laFixture test section is missing. Run `ddev craft project-config/apply`.',
        );
    }

    $slug = 'la-fixture-' . StringHelper::toLowerCase(StringHelper::randomString(10));

    return EntryFactory::factory()
        ->section($section)
        ->title($title ?? 'LA fixture ' . $slug)
        ->slug($slug)
        ->create();
}

/**
 * An entry in the site's `collection` section, whose `Work` entry type has no
 * URL format on the primary site: a real-world stand-in for a data-only entry a
 * relation field points at without ever asserting it renders a link.
 */
function laNoUrlEntry(?string $title = null, bool $enabled = true): Entry
{
    $section = Craft::$app->getEntries()->getSectionByHandle('collection');

    if ($section === null) {
        throw new RuntimeException(
            'The collection section is missing. Run `ddev craft project-config/apply`.',
        );
    }

    return EntryFactory::factory()
        ->section($section)
        ->title($title ?? 'No URL fixture ' . StringHelper::randomString(10))
        ->enabled($enabled)
        ->create();
}

/** Everything the extractor finds in an element. */
function laExtract(Entry $entry): array
{
    return LinkAudit::getInstance()->getLinkExtractor()->extract($entry);
}

/** Only the links found in one field. */
function laLinksIn(array $links, string $handle): array
{
    return array_values(array_filter(
        $links,
        static fn(ExtractedLink $link) => $link->fieldHandle === $handle,
    ));
}

/** The normalised URLs of a set of links, in the order they were found. */
function laUrls(array $links): array
{
    return array_map(static fn(ExtractedLink $link) => $link->url, $links);
}

/** The base URL of the site the fixtures are read on. */
function laBaseUrl(): string
{
    return rtrim((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), '/');
}

/** An asset in the "site" volume, for reference tag element-link tests. */
function laAsset(): Asset
{
    return AssetFactory::factory()->volume('site')->create();
}

// ---------------------------------------------------------------------------
// Rich text
// ---------------------------------------------------------------------------

it('reads every checkable link out of a rich text field', function() {
    $entry = laEntry();
    $entry->setFieldValue('laBody', <<<'HTML'
        <p><a href="https://example.com/one">First link</a></p>
        <p><a href="https://example.com/two?a=1&amp;b=2">Second link</a></p>
        <p><a href="/about-us">Internal link</a></p>
        <p><a href="mailto:hello@example.com">Mail us</a></p>
        <p><a href="#section-two">Jump down</a></p>
        <p><img src="https://example.com/logo.png" alt="The logo"></p>
        HTML);

    $links = laLinksIn(laExtract($entry), 'laBody');

    expect(laUrls($links))->toBe([
        'https://example.com/one',
        'https://example.com/two?a=1&b=2',
        laBaseUrl() . '/about-us',
        'https://example.com/logo.png',
    ]);
});

it('decodes the entity encoded ampersand an editor never typed', function() {
    $entry = laEntry();
    $entry->setFieldValue(
        'laBody',
        '<p><a href="https://example.com/search?q=cats&amp;page=2">Cats</a></p>',
    );

    $links = laLinksIn(laExtract($entry), 'laBody');

    expect($links[0]->url)->toBe('https://example.com/search?q=cats&page=2')
        ->and($links[0]->rawHref)->toBe('https://example.com/search?q=cats&page=2');
});

it('keeps the anchor text and the field it was found in', function() {
    $entry = laEntry();
    $entry->setFieldValue(
        'laBody',
        '<p><a href="https://example.com/report">  Read   the report  </a></p>',
    );

    $link = laLinksIn(laExtract($entry), 'laBody')[0];

    expect($link->linkText)->toBe('Read the report')
        ->and($link->fieldHandle)->toBe('laBody')
        ->and($link->fieldUid)->not->toBeNull()
        ->and($link->elementId)->toBe($entry->id)
        ->and($link->ownerElementId)->toBe($entry->id)
        ->and($link->elementType)->toBe(Entry::class)
        ->and($link->source)->toBe(ExtractedLink::SOURCE_FIELD);
});

it('tells a link to one of our own sites from a link to somebody else', function() {
    $entry = laEntry();
    $entry->setFieldValue('laBody', sprintf(
        '<p><a href="%s/news">Ours</a> <a href="https://elsewhere.example/news">Theirs</a></p>',
        laBaseUrl(),
    ));

    $links = laLinksIn(laExtract($entry), 'laBody');

    expect($links[0]->kind)->toBe(LinkKind::Internal)
        ->and($links[0]->isInternal())->toBeTrue()
        ->and($links[1]->kind)->toBe(LinkKind::External)
        ->and($links[1]->isInternal())->toBeFalse();
});

it('reads image sources only when the settings ask for them', function() {
    $entry = laEntry();
    $entry->setFieldValue('laBody', <<<'HTML'
        <p><a href="https://example.com/page">A link</a></p>
        <p><img src="https://example.com/photo.jpg" alt="A photo"></p>
        HTML);

    expect(laLinksIn(laExtract($entry), 'laBody'))->toHaveCount(2);

    LinkAudit::getInstance()->getSettings()->checkImages = false;

    $links = laLinksIn(laExtract($entry), 'laBody');

    expect($links)->toHaveCount(1)
        ->and($links[0]->url)->toBe('https://example.com/page');
});

it('reads iframe sources only when the settings ask for them', function() {
    $entry = laEntry();
    $entry->setFieldValue(
        'laBody',
        '<p><iframe src="https://embeds.example/video/1" title="A video"></iframe></p>',
    );

    expect(laLinksIn(laExtract($entry), 'laBody'))->toHaveCount(0);

    LinkAudit::getInstance()->getSettings()->checkIframes = true;

    $links = laLinksIn(laExtract($entry), 'laBody');

    expect($links)->toHaveCount(1)
        ->and($links[0]->url)->toBe('https://embeds.example/video/1')
        ->and($links[0]->linkText)->toBe('A video');
});

it('leaves the links inside a nested entry placeholder to that entry', function() {
    $entry = laEntry();
    $entry->setFieldValue('laBody', <<<'HTML'
        <p><a href="https://example.com/outer">Outer link</a></p>
        <craft-entry data-entry-id="99999999"><a href="https://example.com/inner">Inner link</a></craft-entry>
        HTML);

    $links = laLinksIn(laExtract($entry), 'laBody');

    expect(laUrls($links))->toBe(['https://example.com/outer']);
});

it('records a scheme it does not check rather than pretending it was never there', function() {
    $entry = laEntry();
    $entry->setFieldValue(
        'laBody',
        '<p><a href="ftp://files.example.com/handbook.pdf">The handbook</a></p>',
    );

    $link = laLinksIn(laExtract($entry), 'laBody')[0];

    expect($link->kind)->toBe(LinkKind::Ignored)
        ->and($link->url)->toBe('ftp://files.example.com/handbook.pdf')
        ->and($link->initialStatus()->value)->toBe('ignored');
});

it('reads a link with a very long scheme without losing the ones beside it', function() {
    $entry = laEntry();
    $entry->setFieldValue('laBody', <<<'HTML'
        <p>
            <a href="chrome-extension://abcdefghijklmnop/options.html">Open the extension</a>
            <a href="https://example.com/ordinary-neighbour">An ordinary link</a>
        </p>
        HTML);

    $links = laLinksIn(laExtract($entry), 'laBody');

    expect($links)->toHaveCount(2)
        ->and($links[0]->kind)->toBe(LinkKind::Ignored)
        ->and($links[0]->url)->toBe('chrome-extension://abcdefghijklmnop/options.html')
        ->and($links[1]->url)->toBe('https://example.com/ordinary-neighbour');
});

it('finds nothing in an empty rich text field', function() {
    $entry = laEntry();
    $entry->setFieldValue('laBody', '');

    expect(laExtract($entry))->toBe([]);
});

// ---------------------------------------------------------------------------
// Link fields
// ---------------------------------------------------------------------------

it('reads a link field in URL mode as an ordinary candidate', function() {
    $entry = laEntry();
    $entry->setFieldValue('laLink', 'https://example.com/from-a-link-field');

    $links = laLinksIn(laExtract($entry), 'laLink');

    expect($links)->toHaveCount(1)
        ->and($links[0]->kind)->toBe(LinkKind::External)
        ->and($links[0]->url)->toBe('https://example.com/from-a-link-field')
        ->and($links[0]->targetElementId)->toBeNull();
});

it('reads a link field in entry mode as an element link', function() {
    $target = laEntry('The target entry');
    $entry = laEntry();
    $entry->setFieldValue('laLink', "{entry:{$target->id}@{$target->siteId}:url}");

    $links = laLinksIn(laExtract($entry), 'laLink');

    expect($links)->toHaveCount(1)
        ->and($links[0]->kind)->toBe(LinkKind::Element)
        ->and($links[0]->targetElementId)->toBe($target->id)
        ->and($links[0]->targetElementType)->toBe(Entry::class)
        ->and($links[0]->url)->toBe(rtrim((string)$target->getUrl(), '/'))
        ->and($links[0]->isInternal())->toBeTrue();
});

it('skips a link field holding an email address', function() {
    $entry = laEntry();
    $entry->setFieldValue('laLink', 'mailto:hello@example.com');

    expect(laLinksIn(laExtract($entry), 'laLink'))->toBe([]);
});

// ---------------------------------------------------------------------------
// Plain text and relations
// ---------------------------------------------------------------------------

it('leaves plain text fields alone unless it is asked to read them', function() {
    $entry = laEntry();
    $entry->setFieldValue('laText', 'https://example.com/in-a-text-field');

    expect(laLinksIn(laExtract($entry), 'laText'))->toBe([]);

    LinkAudit::getInstance()->getSettings()->scanPlainTextUrls = true;

    $links = laLinksIn(laExtract($entry), 'laText');

    expect($links)->toHaveCount(1)
        ->and($links[0]->url)->toBe('https://example.com/in-a-text-field');
});

it('only reads a plain text value that is a URL and nothing else', function() {
    LinkAudit::getInstance()->getSettings()->scanPlainTextUrls = true;

    $entry = laEntry();
    $entry->setFieldValue('laText', 'Have a look at https://example.com/somewhere for more');

    expect(laLinksIn(laExtract($entry), 'laText'))->toBe([]);
});

// ---------------------------------------------------------------------------
// Nested elements
// ---------------------------------------------------------------------------

it('records a link inside a Matrix block against the block and its owner', function() {
    $entry = laEntry();
    $entry->setFieldValue('laBlocks', [
        'new1' => [
            'type' => 'laBlock',
            'fields' => [
                'laBody' => '<p><a href="https://example.com/in-a-block">Block link</a></p>',
            ],
        ],
    ]);

    expect(Craft::$app->getElements()->saveElement($entry))->toBeTrue();

    $block = $entry->getFieldValue('laBlocks')->one();
    $links = laExtract($entry);

    expect($links)->toHaveCount(1)
        ->and($links[0]->url)->toBe('https://example.com/in-a-block')
        ->and($links[0]->linkText)->toBe('Block link')
        ->and($links[0]->fieldHandle)->toBe('laBody')
        ->and($links[0]->elementId)->toBe($block->id)
        ->and($links[0]->elementId)->not->toBe($entry->id)
        ->and($links[0]->ownerElementId)->toBe($entry->id)
        ->and($links[0]->elementType)->toBe(Entry::class);
});

it('keeps the owner\'s own links apart from its blocks\'', function() {
    $entry = laEntry();
    $entry->setFieldValue('laBlocks', [
        'new1' => [
            'type' => 'laBlock',
            'fields' => [
                'laBody' => '<p><a href="https://example.com/nested">Nested</a></p>',
            ],
        ],
    ]);
    Craft::$app->getElements()->saveElement($entry);

    $entry->setFieldValue('laBody', '<p><a href="https://example.com/top">Top</a></p>');

    $links = laExtract($entry);
    $byUrl = array_combine(laUrls($links), $links);

    expect($links)->toHaveCount(2)
        ->and($byUrl['https://example.com/top']->elementId)->toBe($entry->id)
        ->and($byUrl['https://example.com/nested']->elementId)->not->toBe($entry->id)
        ->and($byUrl['https://example.com/nested']->ownerElementId)->toBe($entry->id);
});

it('reads a link in a Matrix block\'s link field too', function() {
    $entry = laEntry();
    $entry->setFieldValue('laBlocks', [
        'new1' => [
            'type' => 'laBlock',
            'fields' => ['laLink' => 'https://example.com/block-link-field'],
        ],
    ]);
    Craft::$app->getElements()->saveElement($entry);

    $links = laLinksIn(laExtract($entry), 'laLink');

    expect($links)->toHaveCount(1)
        ->and($links[0]->url)->toBe('https://example.com/block-link-field')
        ->and($links[0]->ownerElementId)->toBe($entry->id);
});

it('gives the same answer every time it is asked, so nothing leaks between runs', function() {
    $entry = laEntry();
    $entry->setFieldValue('laBody', '<p><a href="https://example.com/twice">Twice</a></p>');

    expect(laExtract($entry))->toHaveCount(1)
        ->and(laExtract($entry))->toHaveCount(1);
});

it('caps how far down nested content it will walk', function() {
    expect(LinkExtractor::MAX_DEPTH)->toBe(10);
});

// ---------------------------------------------------------------------------
// Hyper
// ---------------------------------------------------------------------------

it('reads a Hyper URL link', function() {
    $entry = laEntry();
    $entry->setFieldValue('laHyper', [
        [
            'handle' => 'default-verbb-hyper-links-url',
            'linkValue' => 'https://example.com/from-hyper',
            'linkText' => 'A Hyper link',
        ],
    ]);

    $links = laLinksIn(laExtract($entry), 'laHyper');

    expect($links)->toHaveCount(1)
        ->and($links[0]->kind)->toBe(LinkKind::External)
        ->and($links[0]->url)->toBe('https://example.com/from-hyper')
        ->and($links[0]->linkText)->toBe('A Hyper link');
})->skip(
    fn() => !class_exists(LinkCollection::class),
    'verbb/hyper is not installed, so there is no Hyper field to read.',
);

it('reads a Hyper entry link as an element link', function() {
    $target = laEntry('The Hyper target');
    $entry = laEntry();
    $entry->setFieldValue('laHyper', [
        [
            'handle' => 'default-verbb-hyper-links-entry',
            'linkValue' => $target->id,
            'linkSiteId' => $target->siteId,
        ],
    ]);

    $links = laLinksIn(laExtract($entry), 'laHyper');

    expect($links)->toHaveCount(1)
        ->and($links[0]->kind)->toBe(LinkKind::Element)
        ->and($links[0]->targetElementId)->toBe($target->id)
        ->and($links[0]->targetElementType)->toBe(Entry::class);
})->skip(
    fn() => !class_exists(LinkCollection::class),
    'verbb/hyper is not installed, so there is no Hyper field to read.',
);

it('skips a Hyper link with nothing in it', function() {
    $entry = laEntry();
    $entry->setFieldValue('laHyper', [
        ['handle' => 'default-verbb-hyper-links-url', 'linkValue' => null],
    ]);

    expect(laLinksIn(laExtract($entry), 'laHyper'))->toBe([]);
})->skip(
    fn() => !class_exists(LinkCollection::class),
    'verbb/hyper is not installed, so there is no Hyper field to read.',
);

// ---------------------------------------------------------------------------
// Relations
// ---------------------------------------------------------------------------

it('leaves relation fields alone unless it is asked to read them', function() {
    $target = laEntry('A related entry');
    $entry = laEntry();
    $entry->setFieldValue('laRelated', [$target->id]);

    expect(laLinksIn(laExtract($entry), 'laRelated'))->toBe([]);

    LinkAudit::getInstance()->getSettings()->scanRelationFields = true;

    $links = laLinksIn(laExtract($entry), 'laRelated');

    expect($links)->toHaveCount(1)
        ->and($links[0]->kind)->toBe(LinkKind::Relation)
        ->and($links[0]->targetElementId)->toBe($target->id);
});

it('gives a relation a stand-in URL of its own, apart from a link field pointing at the same URL-less target', function() {
    $target = laNoUrlEntry('A URL-less target');
    $entry = laEntry();
    $entry->setFieldValue('laRelated', [$target->id]);
    $entry->setFieldValue('laLink', "{entry:{$target->id}@{$target->siteId}:url}");

    LinkAudit::getInstance()->getSettings()->scanRelationFields = true;

    $links = laExtract($entry);
    $relationLink = laLinksIn($links, 'laRelated')[0];
    $linkFieldLink = laLinksIn($links, 'laLink')[0];

    expect($relationLink->kind)->toBe(LinkKind::Relation)
        ->and($relationLink->url)->toBe(ExtractedLink::relationSyntheticUrl($target->id))
        ->and($linkFieldLink->kind)->toBe(LinkKind::Element)
        ->and($linkFieldLink->url)->toBe(ExtractedLink::syntheticUrl($target->id))
        ->and($relationLink->url)->not->toBe($linkFieldLink->url);
});

// ---------------------------------------------------------------------------
// Reference tags
// ---------------------------------------------------------------------------

it('resolves a reference tag entry link to the target element, not a literal URL', function() {
    $target = laEntry('Ref tag target');
    $entry = laEntry();
    $entry->setFieldValue('laBody', sprintf(
        '<p><a href="{entry:%d:url}">Read more</a></p>',
        $target->id,
    ));

    $link = laLinksIn(laExtract($entry), 'laBody')[0];

    expect($link->kind)->toBe(LinkKind::Element)
        ->and($link->targetElementId)->toBe($target->id)
        ->and($link->targetElementType)->toBe(Entry::class)
        ->and($link->url)->not->toContain('{entry')
        ->and($link->url)->toBe(rtrim((string)$target->getUrl(), '/'));

    $verdict = LinkAudit::getInstance()->getInternalResolver()->resolve($link);

    expect($verdict?->status)->toBe(UrlStatus::Ok);
});

it('honours a reference tag\'s @site segment and ignores its fallback once it parses', function() {
    $target = laEntry('Ref tag site target');
    $entry = laEntry();
    $entry->setFieldValue('laBody', sprintf(
        '<p><a href="{entry:%d@%d:url||https://fallback.example}">Read more</a></p>',
        $target->id,
        $target->siteId,
    ));

    $link = laLinksIn(laExtract($entry), 'laBody')[0];

    expect($link->kind)->toBe(LinkKind::Element)
        ->and($link->targetElementId)->toBe($target->id)
        ->and($link->siteId)->toBe($target->siteId)
        ->and($link->url)->not->toBe('https://fallback.example');
});

it('resolves a reference tag asset link to the target asset', function() {
    $asset = laAsset();
    $entry = laEntry();
    $entry->setFieldValue('laBody', sprintf(
        '<p><a href="{asset:%d:url}">Download</a></p>',
        $asset->id,
    ));

    $link = laLinksIn(laExtract($entry), 'laBody')[0];

    expect($link->kind)->toBe(LinkKind::Element)
        ->and($link->targetElementId)->toBe($asset->id)
        ->and($link->targetElementType)->toBe(Asset::class);
});

it('reads a reference tag whose braces the purifier percent-encoded', function() {
    $asset = laAsset();
    $entry = laEntry();
    $entry->setFieldValue('laBody', sprintf(
        '<p><a href="%%7Basset%%3A%d%%3Aurl%%7D">Download</a></p>',
        $asset->id,
    ));

    $link = laLinksIn(laExtract($entry), 'laBody')[0];

    expect($link->kind)->toBe(LinkKind::Element)
        ->and($link->targetElementId)->toBe($asset->id)
        ->and($link->targetElementType)->toBe(Asset::class);
});

it('reads a reference tag carrying an empty fallback', function() {
    $asset = laAsset();
    $entry = laEntry();
    $entry->setFieldValue('laBody', sprintf(
        '<p><a href="{asset:%d:url||}">Download</a></p>',
        $asset->id,
    ));

    $link = laLinksIn(laExtract($entry), 'laBody')[0];

    expect($link->kind)->toBe(LinkKind::Element)
        ->and($link->targetElementId)->toBe($asset->id);
});

it('falls back to the URL after || when a reference tag\'s type is not one it resolves', function() {
    $entry = laEntry();
    $entry->setFieldValue(
        'laBody',
        '<p><a href="{widget:5:url||https://fallback.example}">Widget</a></p>',
    );

    $link = laLinksIn(laExtract($entry), 'laBody')[0];

    expect($link->kind)->toBe(LinkKind::External)
        ->and($link->url)->toBe('https://fallback.example/');
});

it('calls a reference tag pointing at nothing an element link that resolves broken', function() {
    $entry = laEntry();
    $entry->setFieldValue(
        'laBody',
        '<p><a href="{entry:999999999:url}">Gone</a></p>',
    );

    $link = laLinksIn(laExtract($entry), 'laBody')[0];

    expect($link->kind)->toBe(LinkKind::Element)
        ->and($link->targetElementId)->toBe(999999999);

    $verdict = LinkAudit::getInstance()->getInternalResolver()->resolve($link);

    expect($verdict?->status)->toBe(UrlStatus::Broken)
        ->and($verdict?->reason)->toBe(Verdict::REASON_NO_ELEMENT);
});

it('reads a reference tag out of an image source the same way as a href', function() {
    $target = laEntry('Ref tag image target');
    $entry = laEntry();
    $entry->setFieldValue('laBody', sprintf(
        '<p><img src="{entry:%d:url}" alt="Ref tag image"></p>',
        $target->id,
    ));

    $link = laLinksIn(laExtract($entry), 'laBody')[0];

    expect($link->kind)->toBe(LinkKind::Element)
        ->and($link->targetElementId)->toBe($target->id);
});

it('does not mistake a dynamic nav-style path for a reference tag', function() {
    $entry = laEntry();
    $entry->setFieldValue('laBody', '<p><a href="/{slug}">Dynamic</a></p>');

    $link = laLinksIn(laExtract($entry), 'laBody')[0];

    expect($link->kind)->toBe(LinkKind::Internal)
        ->and($link->url)->toBe(laBaseUrl() . '/{slug}');
});

it('still resolves a reference tag with an anchor fragment CKEditor tacked on after it', function() {
    $target = laEntry('Ref tag anchored target');
    $entry = laEntry();
    $entry->setFieldValue('laBody', sprintf(
        '<p><a href="{entry:%d@%d:url||https://fallback.example}#anchorId30">Jump to it</a></p>',
        $target->id,
        $target->siteId,
    ));

    $link = laLinksIn(laExtract($entry), 'laBody')[0];

    expect($link->kind)->toBe(LinkKind::Element)
        ->and($link->targetElementId)->toBe($target->id)
        ->and($link->url)->not->toContain('{entry');
});
