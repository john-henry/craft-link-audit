<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\linkaudit\console\controllers\ExportController;
use johnhenry\linkaudit\console\controllers\ScanController;
use johnhenry\linkaudit\LinkAudit;

// ---------------------------------------------------------------------------
// --site has to mean something wherever it is offered
//
// `scan/report` listed --site and read it nowhere: the flag was taken, the
// report came out covering the whole install, and a mistyped handle came back
// with exit 0 and a report that looked right. Its sibling `scan/element`
// refuses the same handle outright, so the two commands disagreed about
// whether a site that does not exist is a problem.
//
// An option is a promise. Offering it and reading it nowhere is the shape of
// this, so the check is that every action offering --site reaches the resolver
// that refuses an unknown handle.
// ---------------------------------------------------------------------------

/** The actions a controller offers --site on. */
function actionsOfferingSite(object $controller): array
{
    $source = (string)file_get_contents((new ReflectionClass($controller))->getFileName());

    preg_match('/public function options\(\$actionID\): array.*?\n    \}/s', $source, $m);

    $offering = [];

    foreach (explode("\n", $m[0]) as $line) {
        if (!preg_match("/((?:'[\w-]+',?\s*)+)=>\s*\[([^\]]*)\]/", trim($line), $arm)) {
            continue;
        }

        if (!in_array('site', preg_split('/\W+/', $arm[2], -1, PREG_SPLIT_NO_EMPTY) ?: [], true)) {
            continue;
        }

        foreach (preg_match_all("/'([\w-]+)'/", $arm[1], $names) ? $names[1] : [] as $name) {
            $offering[] = $name;
        }
    }

    return $offering;
}

it('offers --site on at least one action, so the check means something', function() {
    $scan = new ScanController('scan', LinkAudit::getInstance());

    expect(actionsOfferingSite($scan))->not->toBeEmpty();
});

it('reads --site in every action that offers it', function(string $class, array $resolvers) {
    // An action offering the flag has to reach something that reads it. The
    // resolvers are the only two places the handle is turned into a site, and
    // both refuse one that names nothing.
    $controller = new $class('x', LinkAudit::getInstance());
    $source = (string)file_get_contents((new ReflectionClass($controller))->getFileName());

    foreach (actionsOfferingSite($controller) as $action) {
        $method = 'action' . str_replace(' ', '', ucwords(str_replace('-', ' ', $action)));

        preg_match("/public function {$method}\(\): int\s*\{(.*?)\n    \}/s", $source, $body);
        expect($body)->not->toBeEmpty("could not read $method");

        $reachesResolver = false;

        foreach ($resolvers as $resolver) {
            if (str_contains($body[1], $resolver)) {
                $reachesResolver = true;
                break;
            }

            // or through a helper the action calls that reads it itself
            foreach (['_queue('] as $helper) {
                if (str_contains($body[1], $helper)
                    && preg_match("/private function " . trim($helper, '(') . ".*?\n    \}/s", $source, $h)
                    && str_contains($h[0], $resolver)) {
                    $reachesResolver = true;
                    break 2;
                }
            }
        }

        expect($reachesResolver)->toBeTrue("$method offers --site and never resolves it");
    }
})->with([
    'ScanController' => [ScanController::class, ['_siteId()']],
    'ExportController' => [ExportController::class, ['_siteIds()']],
]);
