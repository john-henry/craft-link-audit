<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\SettingsModel;

// ---------------------------------------------------------------------------
// The recheck windows are turned into a DateInterval to work out when a URL is
// next due. A number stops being a date long before PHP's integers run out:
// `new DateInterval('P<huge>D')` is refused outright, and the check that was
// being scheduled throws rather than being scheduled.
//
// The save path casts what was typed and hands it on without clamping, so the
// only thing standing between a pasted number and a scan that throws is the
// rule. Every other integer setting already had a ceiling; these were the six
// without one.
// ---------------------------------------------------------------------------

/** Whether a settings value survives validation. */
function windowAccepts(string $attribute, int $value): bool
{
    $settings = new SettingsModel();
    $settings->$attribute = $value;

    return $settings->validate([$attribute]);
}

it('keeps accepting the values anybody actually sets', function(string $attribute, int $default) {
    expect(windowAccepts($attribute, $default))->toBeTrue()
        ->and(windowAccepts($attribute, 0))->toBeTrue();
})->with([
    'okTtlDays' => ['okTtlDays', 30],
    'redirectTtlDays' => ['redirectTtlDays', 30],
    'blockedTtlDays' => ['blockedTtlDays', 14],
    'retainDays' => ['retainDays', 90],
    'brokenRecheckHours' => ['brokenRecheckHours', 24],
    'unreachableRecheckHours' => ['unreachableRecheckHours', 6],
]);

it('refuses a number that is no longer a length of time', function(string $attribute) {
    expect(windowAccepts($attribute, PHP_INT_MAX))->toBeFalse();
})->with([
    'okTtlDays', 'redirectTtlDays', 'blockedTtlDays',
    'retainDays', 'brokenRecheckHours', 'unreachableRecheckHours',
]);

it('refuses what DateInterval itself would refuse', function() {
    // The ceiling is only worth having if it sits below the point the interval
    // gives up, so this asserts the two agree rather than trusting the number.
    $settings = new SettingsModel();
    $settings->okTtlDays = PHP_INT_MAX;

    expect($settings->validate(['okTtlDays']))->toBeFalse();

    expect(fn() => new DateInterval(sprintf('P%dD', PHP_INT_MAX)))
        ->toThrow(Exception::class);
});

it('leaves every accepted value usable as an interval', function(string $attribute, string $unit) {
    // The largest the rule now allows still has to make a date.
    $max = $unit === 'D' ? 3650 : 8760;

    expect(windowAccepts($attribute, $max))->toBeTrue();

    $interval = new DateInterval(sprintf('P%s%d%s', $unit === 'D' ? '' : 'T', $max, $unit));

    expect((new DateTime())->add($interval))->toBeInstanceOf(DateTime::class);
})->with([
    'okTtlDays' => ['okTtlDays', 'D'],
    'brokenRecheckHours' => ['brokenRecheckHours', 'H'],
]);
