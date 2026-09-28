<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\linkaudit\LinkAudit;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// A control-panel page sets selectedSubnavItem, and Craft highlights that entry
// while the page is open. A page that does not set one leaves the whole subnav
// unlit: the section is open, nothing in it is marked, and a reader working
// through a list of URLs loses where they came from. The URL detail page was
// the one page missing it.
//
// Pinned across every template that extends the control-panel layout rather
// than on the pages that exist today, so a new screen cannot arrive without it.
// ---------------------------------------------------------------------------

/** Every template of this plugin that renders a control-panel page. */
function cpPageTemplates(): array
{
    $root = dirname(__DIR__, 2) . '/src/templates';
    $pages = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'twig') {
            continue;
        }

        $source = (string)file_get_contents($file->getPathname());

        $name = substr($file->getPathname(), strlen($root) + 1);

        // A layout is not a page. It extends the control-panel layout the same
        // way, but the page extending it is the one that knows which entry it
        // belongs under, so requiring a layout to name one would mean every
        // page sharing it claimed the same.
        if (str_starts_with($name, '_layouts/')) {
            continue;
        }

        $extendsCp = str_contains($source, "extends '_layouts/cp'");
        $extendsOwnLayout = str_contains($source, "extends 'link-audit/_layouts/");

        if ($extendsCp || $extendsOwnLayout) {
            $pages[$name] = $source;
        }
    }

    return $pages;
}

it('finds the control-panel pages at all', function() {
    // Without this the check below passes on a plugin that renders no pages,
    // which is what a rename of the layout would leave behind.
    expect(cpPageTemplates())->not->toBeEmpty();
});

it('marks a subnav entry on every control-panel page', function() {
    $missing = array_keys(array_filter(
        cpPageTemplates(),
        static fn(string $source): bool => !str_contains($source, 'selectedSubnavItem'),
    ));

    expect($missing)->toBe([]);
});

it('only names subnav entries the plugin actually registers', function() {
    // The nav is built for whoever is asking, and answers nothing at all to
    // somebody who may not read the reports.
    $this->actingAs(UserFactory::factory()->admin(true)->create());

    // A handle that names nothing highlights nothing, which looks the same as
    // setting none at all.
    $handles = array_keys(LinkAudit::getInstance()->getCpNavItem()['subnav'] ?? []);
    $unknown = [];

    foreach (cpPageTemplates() as $name => $source) {
        if (!preg_match("/selectedSubnavItem\s*=\s*'([^']+)'/", $source, $m)) {
            continue;
        }

        if (!in_array($m[1], $handles, true)) {
            $unknown[] = "$name → '{$m[1]}'";
        }
    }

    expect($handles)->not->toBeEmpty()
        ->and($unknown)->toBe([]);
});
