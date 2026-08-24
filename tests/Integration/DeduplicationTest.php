<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\db\Query;
use craft\elements\User;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\helpers\UrlNormaliser;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\Verdict;
use johnhenry\linkaudit\records\ReferenceRecord;
use johnhenry\linkaudit\records\UrlRecord;
use markhuot\craftpest\factories\User as UserFactory;
use yii\base\InvalidArgumentException;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** A real element id, so the references table's foreign key is satisfied. */
function linkAuditElementId(): int
{
    return (int)UserFactory::factory()->create()->id;
}

/** The site every reference in these tests is recorded against. */
function linkAuditSiteId(): int
{
    return Craft::$app->getSites()->getPrimarySite()->id;
}

/** How many reference rows point at a URL. */
function linkAuditReferenceCount(int $urlId): int
{
    return (int)(new Query())
        ->from([ReferenceRecord::tableName()])
        ->where(['urlId' => $urlId])
        ->count();
}

/** The one URL row, read back in full. */
function linkAuditUrlRow(int $urlId): array
{
    /** @var array<string, mixed> $row */
    $row = (new Query())
        ->from([UrlRecord::tableName()])
        ->where(['id' => $urlId])
        ->one();

    return $row;
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('stores one URL row no matter how many entries carry the link', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $siteId = linkAuditSiteId();
    $url = 'https://example.com/shared-footer-link';

    $firstId = $store->upsert($url, false);
    $secondId = $store->upsert($url, false);
    $thirdId = $store->upsert($url, false);

    expect($secondId)->toBe($firstId)
        ->and($thirdId)->toBe($firstId);

    foreach ([linkAuditElementId(), linkAuditElementId(), linkAuditElementId()] as $elementId) {
        $store->replaceReferencesFor($elementId, $siteId, [
            ['urlId' => $firstId, 'elementType' => User::class, 'fieldHandle' => 'footer'],
        ]);
    }

    $urlRows = (int)(new Query())
        ->from([UrlRecord::tableName()])
        ->where(['urlHash' => UrlNormaliser::hash($url)])
        ->count();

    expect($urlRows)->toBe(1)
        ->and(linkAuditReferenceCount($firstId))->toBe(3);
});

it('folds URLs that normalise together into the one row', function() {
    $store = LinkAudit::getInstance()->getUrlStore();

    $first = (string)UrlNormaliser::normalise('HTTPS://Example.com:443/a?utm_source=news#top');
    $second = (string)UrlNormaliser::normalise('https://example.com/a');

    expect($store->upsert($first, false))->toBe($store->upsert($second, false));
});

it('gives a new URL row a pending status and a first seen date', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $row = linkAuditUrlRow($store->upsert('https://example.com/brand-new', false));

    expect($row['status'])->toBe(UrlStatus::Pending->value)
        ->and($row['host'])->toBe('example.com')
        ->and($row['scheme'])->toBe('https')
        ->and($row['dateFirstSeen'])->not->toBeNull()
        ->and($row['dateLastChecked'])->toBeNull()
        ->and((int)$row['failCount'])->toBe(0);
});

it('records a URL that is never checked with the status it was given', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $row = linkAuditUrlRow(
        $store->upsert('ftp://files.example.com/handbook.pdf', false, null, UrlStatus::Ignored),
    );

    expect($row['status'])->toBe(UrlStatus::Ignored->value);
});

it('stores a scheme far longer than http, and clips one longer than the column', function() {
    $store = LinkAudit::getInstance()->getUrlStore();

    // Sixteen characters. The column used to hold eight, and a database in
    // strict mode answers an overflow with an error rather than a truncation,
    // which killed the extraction it happened in and every rescan after it.
    $extension = linkAuditUrlRow(
        $store->upsert('chrome-extension://abcdefghijklmnop/options.html', false, null, UrlStatus::Ignored),
    );

    $absurd = linkAuditUrlRow($store->upsert(
        'a-scheme-so-long-that-nobody-would-ever-type-it://somewhere',
        false,
        null,
        UrlStatus::Ignored,
    ));

    expect($extension['scheme'])->toBe('chrome-extension')
        ->and($extension['status'])->toBe(UrlStatus::Ignored->value)
        ->and(strlen((string)$absurd['scheme']))->toBe(32)
        ->and($absurd['status'])->toBe(UrlStatus::Ignored->value);
});

it('keeps external URLs global and internal ones tied to their site', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $siteId = linkAuditSiteId();

    $internal = linkAuditUrlRow($store->upsert('https://example.com/about', true, $siteId));
    $external = linkAuditUrlRow($store->upsert('https://elsewhere.example/about', false, $siteId));

    expect((int)$internal['siteId'])->toBe($siteId)
        ->and((bool)$internal['isInternal'])->toBeTrue()
        ->and($external['siteId'])->toBeNull()
        ->and((bool)$external['isInternal'])->toBeFalse();
});

it('leaves an existing verdict alone when the same URL turns up again', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $urlId = $store->upsert('https://example.com/checked-already', false);

    $store->recordVerdict($urlId, new Verdict(
        status: UrlStatus::Broken,
        httpStatus: 404,
    ));

    expect($store->upsert('https://example.com/checked-already', false))->toBe($urlId)
        ->and(linkAuditUrlRow($urlId)['status'])->toBe(UrlStatus::Broken->value);
});

it('rebuilds an element\'s references rather than piling them up', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $siteId = linkAuditSiteId();
    $elementId = linkAuditElementId();

    $firstUrlId = $store->upsert('https://example.com/one', false);
    $secondUrlId = $store->upsert('https://example.com/two', false);

    $store->replaceReferencesFor($elementId, $siteId, [
        ['urlId' => $firstUrlId, 'elementType' => User::class],
        ['urlId' => $secondUrlId, 'elementType' => User::class],
    ]);

    expect(linkAuditReferenceCount($firstUrlId))->toBe(1)
        ->and(linkAuditReferenceCount($secondUrlId))->toBe(1);

    $store->replaceReferencesFor($elementId, $siteId, [
        ['urlId' => $secondUrlId, 'elementType' => User::class],
    ]);

    expect(linkAuditReferenceCount($firstUrlId))->toBe(0)
        ->and(linkAuditReferenceCount($secondUrlId))->toBe(1);
});

it('clears an element\'s references when its last link is removed', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $siteId = linkAuditSiteId();
    $elementId = linkAuditElementId();
    $urlId = $store->upsert('https://example.com/going-away', false);

    $store->replaceReferencesFor($elementId, $siteId, [
        ['urlId' => $urlId, 'elementType' => User::class],
    ]);
    $store->replaceReferencesFor($elementId, $siteId, []);

    expect(linkAuditReferenceCount($urlId))->toBe(0);
});

it('only rebuilds the element and site it was asked about', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $siteId = linkAuditSiteId();
    $keptElementId = linkAuditElementId();
    $rebuiltElementId = linkAuditElementId();
    $urlId = $store->upsert('https://example.com/shared', false);

    $store->replaceReferencesFor($keptElementId, $siteId, [
        ['urlId' => $urlId, 'elementType' => User::class],
    ]);
    $store->replaceReferencesFor($rebuiltElementId, $siteId, [
        ['urlId' => $urlId, 'elementType' => User::class],
    ]);
    $store->replaceReferencesFor($rebuiltElementId, $siteId, []);

    expect(linkAuditReferenceCount($urlId))->toBe(1);
});

it('writes everything the control panel needs onto a reference row', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $siteId = linkAuditSiteId();
    $elementId = linkAuditElementId();
    $ownerElementId = linkAuditElementId();
    $urlId = $store->upsert('https://example.com/detailed', false);

    $store->replaceReferencesFor($elementId, $siteId, [
        [
            'urlId' => $urlId,
            'elementType' => User::class,
            'ownerElementId' => $ownerElementId,
            'fieldHandle' => 'bodyContent',
            'fieldUid' => 'a3b1c9d4-0000-4000-8000-000000000001',
            'linkText' => 'Read the report',
            'rawHref' => 'https://example.com/detailed#summary',
            'source' => 'field',
        ],
    ]);

    /** @var array<string, mixed> $reference */
    $reference = (new Query())
        ->from([ReferenceRecord::tableName()])
        ->where(['urlId' => $urlId])
        ->one();

    expect((int)$reference['elementId'])->toBe($elementId)
        ->and((int)$reference['ownerElementId'])->toBe($ownerElementId)
        ->and($reference['elementType'])->toBe(User::class)
        ->and($reference['fieldHandle'])->toBe('bodyContent')
        ->and($reference['linkText'])->toBe('Read the report')
        ->and($reference['rawHref'])->toBe('https://example.com/detailed#summary')
        ->and($reference['source'])->toBe('field');
});

it('clips anchor text that is far too long for the column', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $siteId = linkAuditSiteId();
    $elementId = linkAuditElementId();
    $urlId = $store->upsert('https://example.com/wordy', false);

    $store->replaceReferencesFor($elementId, $siteId, [
        [
            'urlId' => $urlId,
            'elementType' => User::class,
            'linkText' => str_repeat('a', 400),
            'rawHref' => 'https://example.com/' . str_repeat('b', 600),
        ],
    ]);

    /** @var array<string, mixed> $reference */
    $reference = (new Query())
        ->from([ReferenceRecord::tableName()])
        ->where(['urlId' => $urlId])
        ->one();

    expect(strlen((string)$reference['linkText']))->toBe(255)
        ->and(strlen((string)$reference['rawHref']))->toBe(500);
});

it('refuses a reference that does not say which URL it is for', function() {
    $store = LinkAudit::getInstance()->getUrlStore();

    $store->replaceReferencesFor(linkAuditElementId(), linkAuditSiteId(), [
        ['elementType' => User::class],
    ]);
})->throws(InvalidArgumentException::class);
