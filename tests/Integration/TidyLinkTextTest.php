<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\linkaudit\helpers\HtmlParser;

// ---------------------------------------------------------------------------
// Link text is tidied on two paths: a link found in a field, and one found on
// a crawled page. Both wrote the same method out, clip length included, so a
// column that widened would have been followed in one of them.
// ---------------------------------------------------------------------------

it('collapses runs of whitespace into single spaces', function() {
    expect(HtmlParser::tidyLinkText("  Read\n\t  more  "))->toBe('Read more');
});

it('answers null for nothing rather than an empty string', function() {
    // The column is nullable and a blank is not a link text, so the two paths
    // that store this must not disagree about which it is.
    expect(HtmlParser::tidyLinkText(null))->toBeNull()
        ->and(HtmlParser::tidyLinkText('   '))->toBeNull()
        ->and(HtmlParser::tidyLinkText(''))->toBeNull();
});

it('clips to the width the column can actually hold', function() {
    $long = str_repeat('a', HtmlParser::LINK_TEXT_MAX_LENGTH + 50);

    expect(mb_strlen((string)HtmlParser::tidyLinkText($long)))
        ->toBe(HtmlParser::LINK_TEXT_MAX_LENGTH);
});

it('clips by characters, not bytes, so multibyte text is not cut in half', function() {
    // mb_substr rather than substr: a clip landing inside a multibyte sequence
    // stores a broken character.
    $long = str_repeat('é', HtmlParser::LINK_TEXT_MAX_LENGTH + 50);
    $tidied = (string)HtmlParser::tidyLinkText($long);

    expect(mb_strlen($tidied))->toBe(HtmlParser::LINK_TEXT_MAX_LENGTH)
        ->and(mb_check_encoding($tidied, 'UTF-8'))->toBeTrue();
});

it('is the one implementation both scan paths use', function() {
    // Two copies of a clip length is one copy that gets updated.
    foreach (['LinkExtractor', 'PageCrawler'] as $service) {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . "/src/services/{$service}.php");

        expect($source)->toContain('HtmlParser::tidyLinkText(')
            ->and($source)->not->toContain('function _tidyText(');
    }
});
