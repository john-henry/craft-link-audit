<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\linkaudit\enums\SchemeKind;
use johnhenry\linkaudit\helpers\UrlNormaliser;

it('lowercases the scheme and host and leaves the path case alone', function() {
    expect(UrlNormaliser::normalise('HTTP://Example.COM/Path/To/Page'))
        ->toBe('http://example.com/Path/To/Page')
        ->and(UrlNormaliser::normalise('HtTpS://WWW.Example.com/'))
        ->toBe('https://www.example.com/');
});

it('drops the default port and keeps any other one', function() {
    expect(UrlNormaliser::normalise('http://example.com:80/a'))
        ->toBe('http://example.com/a')
        ->and(UrlNormaliser::normalise('https://example.com:443/a'))
        ->toBe('https://example.com/a')
        ->and(UrlNormaliser::normalise('https://example.com:8443/a'))
        ->toBe('https://example.com:8443/a')
        ->and(UrlNormaliser::normalise('http://example.com:443/a'))
        ->toBe('http://example.com:443/a');
});

it('strips the fragment', function() {
    expect(UrlNormaliser::normalise('https://example.com/a#section-two'))
        ->toBe('https://example.com/a')
        ->and(UrlNormaliser::normalise('https://example.com/a?b=1#top'))
        ->toBe('https://example.com/a?b=1');
});

it('writes an empty path as the root it means', function() {
    expect(UrlNormaliser::normalise('https://example.com'))
        ->toBe('https://example.com/')
        ->and(UrlNormaliser::normalise('https://example.com?a=1'))
        ->toBe('https://example.com/?a=1');
});

it('leaves trailing slashes alone, because servers do not', function() {
    expect(UrlNormaliser::normalise('https://example.com/a/'))
        ->toBe('https://example.com/a/')
        ->and(UrlNormaliser::normalise('https://example.com/a'))
        ->toBe('https://example.com/a')
        ->and(UrlNormaliser::hash((string)UrlNormaliser::normalise('https://example.com/a/')))
        ->not->toBe(UrlNormaliser::hash((string)UrlNormaliser::normalise('https://example.com/a')));
});

it('strips tracking parameters when asked to', function() {
    expect(UrlNormaliser::normalise('https://example.com/a?utm_source=news&id=2&fbclid=xyz'))
        ->toBe('https://example.com/a?id=2')
        ->and(UrlNormaliser::normalise('https://example.com/a?UTM_Campaign=spring'))
        ->toBe('https://example.com/a')
        ->and(UrlNormaliser::normalise('https://example.com/a?gclid=1&mc_eid=2&yclid=3'))
        ->toBe('https://example.com/a');
});

it('keeps tracking parameters when the setting is off', function() {
    expect(UrlNormaliser::normalise('https://example.com/a?utm_source=news&id=2', null, false))
        ->toBe('https://example.com/a?utm_source=news&id=2');
});

it('leaves query parameter order exactly as the author wrote it', function() {
    expect(UrlNormaliser::normalise('https://example.com/a?b=1&a=2&c=3'))
        ->toBe('https://example.com/a?b=1&a=2&c=3');
});

it('converts an international host to punycode', function() {
    expect(UrlNormaliser::normalise('https://bücher.example/laden'))
        ->toBe('https://xn--bcher-kva.example/laden')
        ->and(UrlNormaliser::normalise('https://BÜCHER.example/'))
        ->toBe('https://xn--bcher-kva.example/');
});

it('normalises percent-encoding without changing what it means', function() {
    expect(UrlNormaliser::normalise('https://example.com/%7euser/file'))
        ->toBe('https://example.com/~user/file')
        ->and(UrlNormaliser::normalise('https://example.com/a%2fb'))
        ->toBe('https://example.com/a%2Fb')
        ->and(UrlNormaliser::normalise('https://example.com/caf%c3%a9'))
        ->toBe('https://example.com/caf%C3%A9')
        ->and(UrlNormaliser::normalise('https://example.com/a b'))
        ->toBe('https://example.com/a%20b');
});

it('takes the credentials off a URL that carries them', function() {
    expect(UrlNormaliser::normalise('https://admin:hunter2@example.com/private'))
        ->toBe('https://example.com/private')
        ->and(UrlNormaliser::normalise('https://admin@example.com/private'))
        ->toBe('https://example.com/private')
        ->and(UrlNormaliser::normalise('https://admin:hunter2@example.com:8443/private'))
        ->toBe('https://example.com:8443/private');
});

it('gives a credentialled URL the same hash as the plain one, because it is the same page', function() {
    expect(UrlNormaliser::hash((string)UrlNormaliser::normalise('https://admin:hunter2@example.com/private')))
        ->toBe(UrlNormaliser::hash((string)UrlNormaliser::normalise('https://example.com/private')));
});

it('still resolves a relative reference against a base URL that carries credentials', function() {
    expect(UrlNormaliser::normalise('/about', 'https://admin:hunter2@example.com/en/docs/page'))
        ->toBe('https://example.com/about');
});

it('resolves a relative reference against the base URL', function() {
    $base = 'https://example.com/en/docs/page';

    expect(UrlNormaliser::normalise('/about', $base))
        ->toBe('https://example.com/about')
        ->and(UrlNormaliser::normalise('contact', $base))
        ->toBe('https://example.com/en/docs/contact')
        ->and(UrlNormaliser::normalise('../up', $base))
        ->toBe('https://example.com/en/up')
        ->and(UrlNormaliser::normalise('?page=2', $base))
        ->toBe('https://example.com/en/docs/page?page=2')
        ->and(UrlNormaliser::normalise('//cdn.example.org/logo.png', $base))
        ->toBe('https://cdn.example.org/logo.png');
});

it('refuses a relative reference with no base URL to hang it on', function() {
    expect(UrlNormaliser::normalise('/about'))->toBeNull()
        ->and(UrlNormaliser::normalise('contact.html'))->toBeNull()
        ->and(UrlNormaliser::normalise('//cdn.example.org/logo.png'))->toBeNull();
});

it('flattens dot segments', function() {
    expect(UrlNormaliser::normalise('https://example.com/a/b/../c'))
        ->toBe('https://example.com/a/c')
        ->and(UrlNormaliser::normalise('https://example.com/a/./b'))
        ->toBe('https://example.com/a/b')
        ->and(UrlNormaliser::normalise('https://example.com/../../etc'))
        ->toBe('https://example.com/etc');
});

it('decodes the entities an author never typed', function() {
    expect(UrlNormaliser::normalise('https://example.com/a?x=1&amp;y=2'))
        ->toBe('https://example.com/a?x=1&y=2')
        ->and(UrlNormaliser::hash((string)UrlNormaliser::normalise('https://example.com/a?x=1&amp;y=2')))
        ->toBe(UrlNormaliser::hash((string)UrlNormaliser::normalise('https://example.com/a?x=1&y=2')));
});

it('shrugs off the whitespace a rich text editor leaves behind', function() {
    expect(UrlNormaliser::normalise("  https://example.com/a\n"))
        ->toBe('https://example.com/a')
        ->and(UrlNormaliser::normalise("https://exa\tmple.com/a"))
        ->toBe('https://example.com/a');
});

it('gives the same hash for the same URL and for URLs that normalise together', function() {
    $hash = UrlNormaliser::hash('https://example.com/a');

    expect(UrlNormaliser::hash('https://example.com/a'))->toBe($hash)
        ->and($hash)->toHaveLength(40)
        ->and(UrlNormaliser::hash((string)UrlNormaliser::normalise('HTTP://Example.com:80/a#top')))
        ->toBe(UrlNormaliser::hash((string)UrlNormaliser::normalise('http://example.com/a')))
        ->and(UrlNormaliser::hash((string)UrlNormaliser::normalise('https://example.com/a?utm_source=x')))
        ->toBe(UrlNormaliser::hash((string)UrlNormaliser::normalise('https://example.com/a')))
        ->and(UrlNormaliser::hash((string)UrlNormaliser::normalise('https://example.com/%7Ea')))
        ->toBe(UrlNormaliser::hash((string)UrlNormaliser::normalise('https://example.com/~a')))
        ->and(UrlNormaliser::hash((string)UrlNormaliser::normalise('https://example.com/a')))
        ->not->toBe(UrlNormaliser::hash((string)UrlNormaliser::normalise('https://example.com/b')));
});

it('sorts schemes into checkable, skippable and unknown', function() {
    expect(UrlNormaliser::classifyScheme('https://example.com/a'))->toBe(SchemeKind::Checkable)
        ->and(UrlNormaliser::classifyScheme('HTTP://example.com/a'))->toBe(SchemeKind::Checkable)
        ->and(UrlNormaliser::classifyScheme('/about'))->toBe(SchemeKind::Checkable)
        ->and(UrlNormaliser::classifyScheme('//cdn.example.com/a'))->toBe(SchemeKind::Checkable)
        ->and(UrlNormaliser::classifyScheme('mailto:hello@example.com'))->toBe(SchemeKind::Skippable)
        ->and(UrlNormaliser::classifyScheme('TEL:+353212345678'))->toBe(SchemeKind::Skippable)
        ->and(UrlNormaliser::classifyScheme('sms:+353212345678'))->toBe(SchemeKind::Skippable)
        ->and(UrlNormaliser::classifyScheme('javascript:void(0)'))->toBe(SchemeKind::Skippable)
        ->and(UrlNormaliser::classifyScheme('data:image/png;base64,iVBOR'))->toBe(SchemeKind::Skippable)
        ->and(UrlNormaliser::classifyScheme('#section-two'))->toBe(SchemeKind::Skippable)
        ->and(UrlNormaliser::classifyScheme('   '))->toBe(SchemeKind::Skippable)
        ->and(UrlNormaliser::classifyScheme('ftp://files.example.com/a'))->toBe(SchemeKind::Unknown)
        ->and(UrlNormaliser::classifyScheme('skype:someone'))->toBe(SchemeKind::Unknown);
});

it('normalises nothing it cannot check', function() {
    expect(UrlNormaliser::normalise('mailto:hello@example.com'))->toBeNull()
        ->and(UrlNormaliser::normalise('tel:+353212345678'))->toBeNull()
        ->and(UrlNormaliser::normalise('javascript:void(0)'))->toBeNull()
        ->and(UrlNormaliser::normalise('#top'))->toBeNull()
        ->and(UrlNormaliser::normalise('ftp://files.example.com/a'))->toBeNull()
        ->and(UrlNormaliser::normalise(''))->toBeNull()
        ->and(UrlNormaliser::normalise('https://'))->toBeNull();
});

it('refuses a host longer than a host is allowed to be', function() {
    // No name server would answer it, so there is nothing to check, and the
    // column it would be stored in is exactly this wide.
    $tooLong = str_repeat('averylongsegment.', 20) . 'example.com';
    $longEnough = str_repeat('a', 60) . '.example.com';

    expect(strlen($tooLong))->toBeGreaterThan(253)
        ->and(UrlNormaliser::normalise("https://$tooLong/page"))->toBeNull()
        ->and(UrlNormaliser::isInternal("https://$tooLong/page", ["https://$tooLong/"]))->toBeFalse()
        ->and(UrlNormaliser::normalise("https://$longEnough/page"))
        ->toBe("https://$longEnough/page");
});

it('tells an internal URL from an external one by host', function() {
    $baseUrls = ['https://example.com/', 'https://example.com/de/', 'https://shop.example.com/'];

    expect(UrlNormaliser::isInternal('https://example.com/about', $baseUrls))->toBeTrue()
        ->and(UrlNormaliser::isInternal('https://shop.example.com/basket', $baseUrls))->toBeTrue()
        ->and(UrlNormaliser::isInternal('https://example.org/about', $baseUrls))->toBeFalse()
        ->and(UrlNormaliser::isInternal('https://notexample.com/about', $baseUrls))->toBeFalse()
        ->and(UrlNormaliser::isInternal('https://example.com/about', []))->toBeFalse();
});

it('counts a resolved relative link as internal', function() {
    $base = 'https://example.com/en/';
    $normalised = UrlNormaliser::normalise('/about', $base);

    expect($normalised)->toBe('https://example.com/about')
        ->and(UrlNormaliser::isInternal($normalised, [$base]))->toBeTrue();
});

it('ignores the default port when deciding whether a URL is internal', function() {
    expect(UrlNormaliser::isInternal('https://example.com/a', ['https://example.com:443/']))->toBeTrue()
        ->and(UrlNormaliser::isInternal('http://example.com:8080/a', ['http://example.com/']))->toBeFalse();
});

it('reads the host and scheme back off a normalised URL', function() {
    $normalised = (string)UrlNormaliser::normalise('HTTPS://Example.COM:443/a?b=1');

    expect(UrlNormaliser::hostOf($normalised))->toBe('example.com')
        ->and(UrlNormaliser::schemeOf($normalised))->toBe('https')
        ->and(UrlNormaliser::hostOf('not a url at all'))->toBe('')
        ->and(UrlNormaliser::schemeOf('not a url at all'))->toBe('');
});
