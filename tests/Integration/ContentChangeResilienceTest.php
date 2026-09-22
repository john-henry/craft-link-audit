<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\linkaudit\LinkAudit;

// ---------------------------------------------------------------------------
// The save hook is not allowed to fail a save
//
// It listens on EVENT_AFTER_PROPAGATE, which fires inside the save's own
// transaction, and before it decides whether to queue anything it asks the
// element for its root owner and then asks the queue for a row. Either can
// throw. Anything that does is the author being told their page could not be
// saved, because a link audit could not tidy itself up.
//
// The delete hook alongside it already said exactly this in its own comment and
// wrapped itself accordingly. This is the same rule, applied to the other half.
//
// Asserted from the source rather than by failing a save. The throw has to come
// from inside the hook's own work for the hook's catch to be the thing that
// catches it, and a listener attached from a test fires alongside the hook
// rather than within it, so a save that survives would prove nothing about
// whose catch saved it.
// ---------------------------------------------------------------------------

it('keeps both content hooks inside a catch, not just the delete one', function() {
    // The asymmetry this file exists for. Asserted from the source because the
    // throw has to come from inside the hook's own work to be caught by it, and
    // a listener attached from a test fires alongside it rather than within it.
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/base/PluginTrait.php');

    $unguarded = [];

    foreach (['_onContentChange', '_registerReferenceCleanup'] as $method) {
        preg_match('/(?:private|protected|public) (?:static )?function ' . $method . '\(.*?\n    \}/s', $source, $m);

        if (!str_contains($m[0] ?? '', 'catch (Throwable')) {
            $unguarded[] = $method;
        }
    }

    expect($unguarded)->toBe([]);
});

it('wraps the work the hook does, not merely the tail of it', function() {
    // getRootOwner() is asked before anything is queued and is the more likely
    // thrower of the two, so the catch has to start above it.
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/base/PluginTrait.php');

    preg_match('/private static function _onContentChange\(.*?\n    \}/s', $source, $m);
    $body = $m[0] ?? '';

    expect($body)->not->toBeEmpty();

    $tryAt = strpos($body, 'try {');
    $rereadAt = strpos($body, '_pageToReread(');
    $queueAt = strpos($body, '_queueExtraction(');

    expect($tryAt)->not->toBeFalse()
        ->and($tryAt)->toBeLessThan($rereadAt)
        ->and($tryAt)->toBeLessThan($queueAt);
});
