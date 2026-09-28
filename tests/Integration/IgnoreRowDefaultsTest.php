<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\linkaudit\LinkAudit;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Every settings table with an "On" switch is read by skipping the rows whose
// switch is off. Craft's editable table builds a newly added row from
// `defaultValues`, so without an entry there the switch starts off: a pattern
// or a host somebody typed in saves, reads back looking right, and is then
// passed over by the scan with nothing said about it.
//
// A lightswitch posts on every row, off included, as an empty string, so the
// row is there either way and only the switch tells them apart.
// ---------------------------------------------------------------------------

beforeEach(function() {
    $this->actingAs(UserFactory::factory()->admin(true)->create());
    $settings = LinkAudit::getInstance()->getSettings();
    $this->storedRows = [
        'ignorePatterns' => $settings->ignorePatterns,
        'ignoreHosts' => $settings->ignoreHosts,
        'excludedUriPatterns' => $settings->excludedUriPatterns,
    ];
});

afterEach(function() {
    $settings = LinkAudit::getInstance()->getSettings();

    foreach ($this->storedRows as $name => $value) {
        $settings->$name = $value;
    }
});

it('gives every lightswitch column a default, so an added row starts on', function() {
    // Pinned across the settings templates rather than on the three tables
    // that carry one today, so a fourth cannot be added without it.
    $switches = 0;
    $defaults = 0;

    foreach (glob(dirname(__DIR__, 2) . '/src/templates/_settings/*.twig') ?: [] as $path) {
        $source = (string)file_get_contents($path);
        $switches += substr_count($source, "type: 'lightswitch'");
        $defaults += substr_count($source, 'defaultValues: { enabled: true }');
    }

    expect($switches)->toBeGreaterThan(0)
        ->and($defaults)->toBe($switches);
});

it('applies an ignore rule whose switch came back on', function() {
    $settings = LinkAudit::getInstance()->getSettings();
    $settings->ignorePatterns = [['enabled' => true, 'pattern' => '^https://staging\.', 'note' => '']];

    // The reader skips a row that is off, so this is the assertion that the
    // stored shape and the reader agree.
    $rules = LinkAudit::$plugin->getIgnoreService()->ruleFor('https://staging.example.com/a');

    expect($rules)->not->toBeNull();
});

it('passes over the same rule when its switch is off', function() {
    $settings = LinkAudit::getInstance()->getSettings();
    $settings->ignorePatterns = [['enabled' => false, 'pattern' => '^https://staging\.', 'note' => '']];

    expect(LinkAudit::$plugin->getIgnoreService()->ruleFor('https://staging.example.com/a'))->toBeNull();
});
