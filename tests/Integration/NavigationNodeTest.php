<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\elements\Entry;
use craft\helpers\StringHelper;
use johnhenry\linkaudit\enums\LinkKind;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\ExtractedLink;
use markhuot\craftpest\factories\Entry as EntryFactory;
use verbb\navigation\elements\Node;
use verbb\navigation\Navigation;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

// Navigation 4 renamed navs to menus and gave each node type its own class, so
// these helpers answer the same question under either version.

/** Every navigation (Navigation 3) or menu (Navigation 4) in the project. */
function navAll(): array
{
    $plugin = Navigation::$plugin ?? null;

    if ($plugin === null) {
        return [];
    }

    return method_exists($plugin, 'getMenus')
        ? $plugin->getMenus()->getAllMenus()
        : $plugin->getNavs()->getAllNavs();
}

/** Whether verbb/navigation is installed and has a navigation to hang nodes off. */
function navInstalled(): bool
{
    return class_exists(Node::class) && class_exists(Navigation::class) && navAll() !== [];
}

/** The first navigation in the project. */
function navFirst(): object
{
    return array_values(navAll())[0];
}

/** The node type class for a custom URL, a passive node or an entry. */
function navType(string $kind): string
{
    $v4 = [
        'custom' => 'verbb\\navigation\\nodetypes\\Custom',
        'passive' => 'verbb\\navigation\\nodetypes\\Passive',
        'entry' => 'verbb\\navigation\\nodetypes\\Entry',
    ];
    $v3 = [
        'custom' => 'verbb\\navigation\\nodetypes\\CustomType',
        'passive' => 'verbb\\navigation\\nodetypes\\PassiveType',
        'entry' => Entry::class,
    ];

    return class_exists($v4['custom']) ? $v4[$kind] : $v3[$kind];
}

/** Saves a node in the first navigation and returns it. */
function navNode(array $config): Node
{
    $node = new Node(array_merge([
        (property_exists(Node::class, 'menuId') ? 'menuId' : 'navId') => navFirst()->id,
        'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
        'enabled' => true,
        'type' => navType('custom'),
    ], $config));

    if (!Craft::$app->getElements()->saveElement($node)) {
        throw new RuntimeException('Could not save the test node: ' . print_r($node->getErrors(), true));
    }

    return $node;
}

/** An entry a node can point at. */
function navEntry(): Entry
{
    $section = Craft::$app->getEntries()->getSectionByHandle('laFixture');
    $slug = 'nav-fixture-' . StringHelper::toLowerCase(StringHelper::randomString(10));

    return EntryFactory::factory()
        ->section($section)
        ->title('Nav fixture ' . $slug)
        ->slug($slug)
        ->create();
}

/** The links the extractor found for one node. */
function navLinksFor(Node $node): array
{
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $links = LinkAudit::getInstance()->getLinkExtractor()->extractNavigationNodes($siteId);

    return array_values(array_filter(
        $links,
        static fn(ExtractedLink $link) => $link->elementId === $node->id,
    ));
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('reads a custom URL node as an ordinary candidate', function() {
    $node = navNode([
        'title' => 'A custom link',
        'url' => 'https://example.com/from-a-nav-node',
    ]);

    $links = navLinksFor($node);

    expect($links)->toHaveCount(1)
        ->and($links[0]->kind)->toBe(LinkKind::External)
        ->and($links[0]->url)->toBe('https://example.com/from-a-nav-node')
        ->and($links[0]->source)->toBe(ExtractedLink::SOURCE_NAV)
        ->and($links[0]->elementType)->toBe(Node::class)
        ->and($links[0]->ownerElementId)->toBe($node->id)
        ->and($links[0]->linkText)->toBe('A custom link')
        ->and($links[0]->fieldHandle)->toBeNull();
})->skip(fn() => !navInstalled(), 'verbb/navigation is not installed, so there are no nodes to read.');

it('reads a root relative custom URL node as internal', function() {
    $node = navNode([
        'title' => 'An internal link',
        'url' => '/about-us',
    ]);

    $links = navLinksFor($node);

    expect($links)->toHaveCount(1)
        ->and($links[0]->kind)->toBe(LinkKind::Internal)
        ->and($links[0]->isInternal())->toBeTrue();
})->skip(fn() => !navInstalled(), 'verbb/navigation is not installed, so there are no nodes to read.');

it('reads an entry node as an element link', function() {
    $entry = navEntry();
    $node = navNode([
        'title' => 'An entry link',
        'type' => navType('entry'),
        'elementId' => $entry->id,
    ]);

    $links = navLinksFor($node);

    expect($links)->toHaveCount(1)
        ->and($links[0]->kind)->toBe(LinkKind::Element)
        ->and($links[0]->targetElementId)->toBe($entry->id)
        ->and($links[0]->source)->toBe(ExtractedLink::SOURCE_NAV);
})->skip(fn() => !navInstalled(), 'verbb/navigation is not installed, so there are no nodes to read.');

it('has nothing to say about a passive node', function() {
    $node = navNode([
        'title' => 'Just a heading',
        'type' => navType('passive'),
    ]);

    expect(navLinksFor($node))->toBe([]);
})->skip(fn() => !navInstalled(), 'verbb/navigation is not installed, so there are no nodes to read.');

it('leaves a custom URL that only a request could resolve alone', function() {
    $node = navNode([
        'title' => 'A dynamic link',
        'url' => 'https://example.com/{currentUser.username}',
    ]);

    expect(navLinksFor($node))->toBe([]);
})->skip(fn() => !navInstalled(), 'verbb/navigation is not installed, so there are no nodes to read.');

it('skips a mailto node the same way it skips one in content', function() {
    $node = navNode([
        'title' => 'Mail us',
        'url' => 'mailto:hello@example.com',
    ]);

    expect(navLinksFor($node))->toBe([]);
})->skip(fn() => !navInstalled(), 'verbb/navigation is not installed, so there are no nodes to read.');

it('reads nothing at all when navigation scanning is off', function() {
    navNode([
        'title' => 'A custom link',
        'url' => 'https://example.com/never-read',
    ]);

    LinkAudit::getInstance()->getSettings()->scanNavigationNodes = false;

    expect(LinkAudit::getInstance()->getLinkExtractor()->extractNavigationNodes())->toBe([]);
})->skip(fn() => !navInstalled(), 'verbb/navigation is not installed, so there are no nodes to read.');

it('records a nav reference against the node, which is an element in its own right', function() {
    $node = navNode([
        'title' => 'A stored link',
        'url' => 'https://example.com/stored-from-a-node',
    ]);

    $plugin = LinkAudit::getInstance();
    $store = $plugin->getUrlStore();
    $link = navLinksFor($node)[0];

    $urlId = $store->upsert($link->url, $link->isInternal(), $link->siteId, $link->initialStatus());
    $store->replaceReferencesFor($link->elementId, $link->siteId, [$link->toReference($urlId)]);

    $reference = (new craft\db\Query())
        ->from([johnhenry\linkaudit\records\ReferenceRecord::tableName()])
        ->where(['urlId' => $urlId])
        ->one();

    expect($reference)->not->toBeFalse()
        ->and($reference['source'])->toBe('nav')
        ->and((int)$reference['elementId'])->toBe($node->id)
        ->and($reference['elementType'])->toBe(Node::class);
})->skip(fn() => !navInstalled(), 'verbb/navigation is not installed, so there are no nodes to read.');
