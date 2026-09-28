<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\elements\Entry;
use craft\helpers\StringHelper;
use johnhenry\linkaudit\LinkAudit;
use markhuot\craftpest\factories\Entry as EntryFactory;

// ---------------------------------------------------------------------------
// Reading one element, and saying honestly what happened
//
// scanElement() skips a site where the element cannot be read: no element with
// that id, or one excluded from the audit by its section or its URI. Counting
// those as nothing found hands the console a zero, and a zero printed in green
// reads as a page that was read and carries no links.
//
// The two answers are different and the caller has to be able to tell them
// apart, which is why null is not zero here.
//
// Helper names carry a `scanEl` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/** A fixture entry in the plugin's own test section. */
function scanElFixtureEntry(string $html = '<p><a href="https://example.com/a">A link</a></p>'): Entry
{
    $section = Craft::$app->getEntries()->getSectionByHandle('laFixture');

    if ($section === null) {
        throw new RuntimeException(
            'The laFixture test section is missing. Run `ddev craft project-config/apply`.',
        );
    }

    return EntryFactory::factory()
        ->section($section)
        ->title('LA scan element ' . StringHelper::randomString(8))
        ->slug('la-scan-el-' . StringHelper::toLowerCase(StringHelper::randomString(12)))
        ->set('laBody', $html)
        ->create();
}

it('answers null for an id that names no element', function() {
    expect(LinkAudit::$plugin->getScanService()->scanElement(99999999))->toBeNull();
});

it('answers a number for an element it actually read', function() {
    $entry = scanElFixtureEntry();

    expect(LinkAudit::$plugin->getScanService()->scanElement((int) $entry->id))
        ->toBeInt();
});

it('answers zero, not null, for a page that was read and had no links', function() {
    // The distinction the null is there to make: this page was read.
    $entry = scanElFixtureEntry('<p>Nothing to follow here.</p>');

    expect(LinkAudit::$plugin->getScanService()->scanElement((int) $entry->id))
        ->toBe(0);
});

it('tells the console the two apart', function() {
    // The command prints a green count for one and an error for the other, so
    // the check has to be on null rather than on falsiness: zero is a real
    // answer and must not take the error path.
    $source = (string) file_get_contents(
        dirname(__DIR__, 2) . '/src/console/controllers/ScanController.php',
    );

    preg_match('/public function actionElement\(\).*?\n    \}/s', $source, $m);

    expect($m[0] ?? '')->toContain('$found === null')
        ->and($m[0] ?? '')->toContain('ExitCode::DATAERR');
});
