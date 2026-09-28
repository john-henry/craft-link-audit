<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\linkaudit\models\SettingsModel;

// ---------------------------------------------------------------------------
// The three settings that take a regular expression
//
// Ignored URL Patterns, Excluded URI Patterns and Always Valid Internal URLs
// are all read with a scoped error handler, so a pattern that does not compile
// matches nothing rather than derailing a scan. That is right where they are
// used and wrong at the point somebody types one: without a check here the save
// goes through, the row sits on the settings screen looking like a rule, and it
// quietly does nothing.
//
// Which way it fails matters. An ignore that ignores nothing keeps reporting
// the links it was written to quiet, and an exclusion that excludes nothing
// keeps scanning the pages it was written to leave alone. Neither says a word.
//
// Helper names carry a `patterns` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/**
 * The column each table keeps its pattern in. They do not agree, and reading
 * the wrong one finds an empty string on every row: a test that fed the wrong
 * shape would pass against a validator that checked nothing.
 */
function patternsColumn(string $attribute): string
{
    return $attribute === 'excludedUriPatterns' ? 'uriPattern' : 'pattern';
}

/** Whether a settings model holding one pattern row validates. */
function patternsValidate(string $attribute, string $pattern, array $extra = []): bool
{
    $settings = new SettingsModel();
    $settings->$attribute = [[patternsColumn($attribute) => $pattern] + $extra];

    return $settings->validate([$attribute]);
}

it('reads the column each table actually stores its pattern in', function() {
    // Asserted against the code that reads them at scan time, so the validator
    // cannot drift onto a column nothing writes.
    $scan = (string) file_get_contents(dirname(__DIR__, 2) . '/src/services/ScanService.php');
    $ignore = (string) file_get_contents(dirname(__DIR__, 2) . '/src/services/IgnoreService.php');
    $resolver = (string) file_get_contents(dirname(__DIR__, 2) . '/src/services/InternalResolver.php');

    expect($scan)->toContain("\$row['uriPattern']")
        ->and($ignore)->toContain("'pattern'")
        ->and($resolver)->toContain("\$row['pattern']");
});

it('refuses a pattern that does not compile, in every setting that takes one', function() {
    foreach (['ignorePatterns', 'excludedUriPatterns', 'internalUrlAllowPatterns'] as $attribute) {
        expect(patternsValidate($attribute, '[unclosed'))
            ->toBeFalse("{$attribute} accepted a pattern that cannot compile");
    }
});

it('accepts a pattern that compiles', function() {
    expect(patternsValidate('ignorePatterns', '^https://example\.com/'))->toBeTrue();
});

it('accepts a slash without asking for it to be escaped', function() {
    // The tilde delimiter is the reason these read the way they do on screen.
    expect(patternsValidate('excludedUriPatterns', 'news/\d+'))->toBeTrue();
});

it('accepts an empty pattern, which is how the homepage is written', function() {
    expect(patternsValidate('excludedUriPatterns', ''))->toBeTrue();
});

it('leaves a row somebody switched off alone', function() {
    // Parking a half-written rule should not stop the rest of the screen saving.
    expect(patternsValidate('ignorePatterns', '[unclosed', ['enabled' => false]))
        ->toBeTrue();
});

it('names the pattern it would not take', function() {
    $settings = new SettingsModel();
    $settings->ignorePatterns = [['pattern' => '[unclosed']];
    $settings->validate(['ignorePatterns']);

    expect(implode(' ', $settings->getErrors('ignorePatterns')))->toContain('[unclosed');
});
