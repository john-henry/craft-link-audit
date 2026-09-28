<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\linkaudit\models\SettingsModel;

// ---------------------------------------------------------------------------
// src/config.php is the file the guide tells people to copy
//
// It is the only place the shape of a setting is shown, and for the editable
// tables the shape is the whole difficulty: nobody guesses that an ignore rule
// is a row with `enabled`, `pattern` and `note` in it. A setting missing from
// here is a setting that can be pinned in code and that nobody finds out can
// be, and an example that would not validate is worse than none.
//
// Helper names carry a `cfgEx` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/**
 * The example file with its keys uncommented, as somebody copying it would
 * have after uncommenting the lot.
 *
 * Only the lines that are commented-out config are taken: the prose and the
 * section rules around them are left where they are.
 *
 * @return array<string, mixed>
 */
function cfgExValues(): array
{
    $source = (string)file_get_contents(dirname(__DIR__, 2) . '/src/config.php');
    $lines = [];

    foreach (explode("\n", $source) as $line) {
        $trimmed = ltrim($line);

        if (preg_match("/^\/\/\s*('\w+'\s*=>|\[|\],|\])/", $trimmed)) {
            $lines[] = preg_replace('/^(\s*)\/\/ ?/', '$1', $line);
            continue;
        }

        if (!str_starts_with($trimmed, '//') && !str_starts_with($trimmed, '*') && !str_starts_with($trimmed, '/*')) {
            $lines[] = $line;
        }
    }

    $code = implode("\n", $lines);

    /** @var array<string, mixed> $values */
    $values = eval(substr($code, strpos($code, 'return [')));

    return $values;
}

/** @return string[] */
function cfgExSettings(): array
{
    $names = [];

    foreach ((new ReflectionClass(SettingsModel::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        $names[] = $property->name;
    }

    return $names;
}

it('reads as config at all', function() {
    // Guards the rest: a file that parsed to nothing would pass every check
    // below without comparing anything.
    expect(cfgExValues())->not->toBeEmpty();
});

it('shows an example for every setting', function() {
    $missing = array_values(array_diff(cfgExSettings(), array_keys(cfgExValues())));

    expect($missing)->toBe([], sprintf(
        "These settings can be pinned in config and are not shown in the example file, so nobody "
        . "reading it finds out they can be:\n  - %s",
        implode("\n  - ", $missing),
    ));
});

it('names no setting the model does not have', function() {
    $unknown = array_values(array_diff(array_keys(cfgExValues()), cfgExSettings()));

    expect($unknown)->toBe([]);
});

it('shows examples the settings model actually accepts', function() {
    // The row shapes are the point of this one. A pattern that does not compile
    // or a column named wrong reads perfectly well and is refused on save.
    $settings = new SettingsModel();

    foreach (cfgExValues() as $name => $value) {
        $settings->$name = $value;
    }

    expect($settings->validate())->toBeTrue(
        'the example file does not validate: ' . json_encode($settings->getErrors()),
    );
});

it('shows the real default wherever it claims to', function() {
    // The file says most keys show their own default, and the ones whose
    // default is empty show an example instead. That is only safe while it
    // holds: a key showing something other than its default, where the default
    // is not empty, reads as "this is what it already is" and silently changes
    // the setting for anybody who uncomments it as it stands.
    $defaults = new SettingsModel();
    $wrong = [];

    foreach (cfgExValues() as $name => $shown) {
        $default = $defaults->$name;

        if ($shown === $default) {
            continue;
        }

        // An empty default teaches nothing, so those are allowed to show an
        // example. Anything else has to show the truth.
        if ($default === '' || $default === [] || $default === null) {
            continue;
        }

        $wrong[] = sprintf('%s shows %s, default is %s', $name, json_encode($shown), json_encode($default));
    }

    expect($wrong)->toBe([], sprintf(
        "These keys show a value that is neither their default nor an example standing in for an "
        . "empty one:\n  - %s",
        implode("\n  - ", $wrong),
    ));
});
