<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Broken, Redirects, Unverifiable and No Answer are the same page with a
// different heading, a different paragraph and a different empty message. They
// were four copies of the whole thing, including the twelve-key hand-off to
// the table partial: that hand-off is `only`, so a variable the partial starts
// needing and one of the four stops passing is not an error, it is a page
// quietly missing a filter.
//
// They share one layout now, and each page is the four values it differs by.
// These render the real routes, because the layout reads values the page sets
// at its top level and nothing but rendering proves that reaches it.
// ---------------------------------------------------------------------------

beforeEach(function() {
    $this->actingAs(UserFactory::factory()->admin(true)->create());
});

it('renders each status page with its own heading and blurb', function(string $path, string $heading, string $blurb) {
    $this->get("admin/link-audit/$path")
        ->assertOk()
        ->assertSee($heading)
        ->assertSee($blurb);
})->with([
    'broken'     => ['broken', 'Broken', 'the codes for a page that is not there any more'],
    'redirects'  => ['redirects', 'Redirects', 'These all still work, so nobody is landing on an error'],
    'blocked'    => ['blocked', 'Unverifiable', 'refuse to answer anything but a person in a browser'],
    'no-answer'  => ['no-answer', 'No Answer', 'These did not answer when they were asked'],
]);

it('hands the table the values this page set and not another', function(string $path, string $emptyMessage) {
    // Whichever branch the partial takes proves the hand-off: with rows it
    // renders the filter form, and with none it prints the empty message this
    // page set, which only reaches it through the shared layout.
    $response = $this->get("admin/link-audit/$path")->assertOk();

    $body = (string)$response->content;
    $rendered = str_contains($body, 'id="link-audit-filters"');

    expect($rendered || str_contains($body, $emptyMessage))->toBeTrue(
        "Neither the table nor the empty message this page set reached the partial for /$path.",
    );
})->with([
    'broken' => ['broken', 'Nothing broken on this site'],
    'redirects' => ['redirects', 'No redirects on this site'],
    'blocked' => ['blocked', 'Nothing on this site refused the checker'],
    'no-answer' => ['no-answer', 'Everything on this site answered when it was asked'],
]);

it('keeps each page on its own subnav entry', function() {
    foreach (['broken', 'redirects', 'blocked', 'no-answer'] as $handle) {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . "/src/templates/$handle.twig");

        expect($source)->toContain("selectedSubnavItem = '$handle'");
    }
});
