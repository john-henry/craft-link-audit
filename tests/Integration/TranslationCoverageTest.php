<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

// ---------------------------------------------------------------------------
// The message file against the strings that ask for it
//
// A string with no entry still renders: Craft falls back to the source message,
// so an English install looks perfectly correct and nothing complains. What is
// lost is quieter than that. The file is what a translator is handed, so a
// string missing from it is a string nobody is ever given the chance to
// translate, and it stays English on an install that translated everything else.
//
// The other way round costs nothing at runtime and misleads all the same: an
// entry nothing asks for is a line a translator will spend time on for a screen
// that no longer says it.
//
// JsTranslationsTest already pins the JavaScript list against the templates.
// This pins the message file against every string, in PHP, Twig and JS alike.
//
// Helper names carry a `tCov` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/** The message file, as it ships. */
function tCovKeys(): array
{
    return require dirname(__DIR__, 2) . '/src/translations/en/link-audit.php';
}

/** Every string anywhere in the plugin that asks to be translated. */
function tCovLiterals(): array
{
    $root = dirname(__DIR__, 2) . '/src';
    $found = [];

    // The four ways a string reaches the translator: PHP, control-panel JS,
    // and Twig with or without a parameters argument after the domain. The
    // last one is easy to leave out of a search like this and covers every
    // string carrying a {placeholder}.
    $patterns = [
        "/Craft::t\(\s*'link-audit'\s*,\s*'((?:[^'\\\\]|\\\\.)*)'/",
        "/Craft\.t\(\s*'link-audit'\s*,\s*'((?:[^'\\\\]|\\\\.)*)'/",
        "/'((?:[^'\\\\]|\\\\.)*)'\s*\|\s*t\(\s*'link-audit'\s*[,)]/",
        "/\"((?:[^\"\\\\]|\\\\.)*)\"\s*\|\s*t\(\s*'link-audit'\s*[,)]/",
    ];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'twig', 'js'], true)) {
            continue;
        }

        if (str_contains($file->getPathname(), '/translations/')) {
            continue;
        }

        $source = (string)file_get_contents($file->getPathname());

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $source, $matches);

            foreach ($matches[1] as $literal) {
                $found[stripcslashes($literal)] = true;
            }
        }
    }

    return array_keys($found);
}

it('finds the translatable strings at all', function() {
    // Without this the checks below pass on a search that matched nothing,
    // which is what a change to how strings are written would leave behind.
    // Kept as a plain floor rather than a count against the file, so it says
    // only the one thing the other two cannot say for themselves.
    expect(tCovLiterals())->not->toBeEmpty();
});

it('has an entry for every string that asks to be translated', function() {
    $missing = array_values(array_diff(tCovLiterals(), array_keys(tCovKeys())));

    expect($missing)->toBe([], sprintf(
        "These strings are translated in the code and missing from the message file, so a "
        . "translator is never handed them:\n  - %s",
        implode("\n  - ", array_slice($missing, 0, 20)),
    ));
});

it('carries no entry nothing asks for', function() {
    $orphaned = array_values(array_diff(array_keys(tCovKeys()), tCovLiterals()));

    expect($orphaned)->toBe([], sprintf(
        "These entries are in the message file and asked for nowhere:\n  - %s",
        implode("\n  - ", array_slice($orphaned, 0, 20)),
    ));
});

it('keeps the English value the same as its key', function() {
    // The key is the string the source asks for. An English value that has
    // drifted from it renders something the code does not say, which reads as
    // a translation bug on the one install nobody thinks to check.
    $drifted = [];

    foreach (tCovKeys() as $key => $value) {
        if ($key !== $value) {
            $drifted[] = $key;
        }
    }

    expect($drifted)->toBe([]);
});
