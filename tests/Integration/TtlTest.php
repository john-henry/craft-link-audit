<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\helpers\UrlNormaliser;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\Verdict;
use johnhenry\linkaudit\records\UrlRecord;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Whether the check phase would pick this URL up right now. */
function linkAuditIsDue(int $urlId): bool
{
    return LinkAudit::getInstance()
        ->getUrlStore()
        ->pendingQuery()
        ->andWhere(['id' => $urlId])
        ->exists();
}

/** A URL row, read back in full. */
function linkAuditTtlRow(int $urlId): array
{
    /** @var array<string, mixed> $row */
    $row = (new Query())
        ->from([UrlRecord::tableName()])
        ->where(['id' => $urlId])
        ->one();

    return $row;
}

/** Drags a URL's next check date back into the past. */
function linkAuditMakeStale(int $urlId): void
{
    Db::update(UrlRecord::tableName(), [
        'nextCheckAfter' => Db::prepareDateForDb(DateTimeHelper::now()->modify('-1 hour')),
    ], ['id' => $urlId]);
}

/**
 * How many hours from now until a stored date. Stored dates are UTC, so they
 * are read back as UTC: assuming the system time zone here would shift every
 * answer by the server's offset and make the assertions below lie.
 */
function linkAuditHoursFromNow(?string $storedDate): float
{
    $date = DateTimeHelper::toDateTime($storedDate);
    expect($date)->not->toBeFalse();

    return ($date->getTimestamp() - DateTimeHelper::now()->getTimestamp()) / 3600;
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('always offers a URL that has never been checked', function() {
    $urlId = LinkAudit::getInstance()->getUrlStore()->upsert('https://example.com/never-checked', false);

    expect(linkAuditIsDue($urlId))->toBeTrue();
});

it('holds a working link back until its time to live runs out', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $urlId = $store->upsert('https://example.com/works', false);

    $store->recordVerdict($urlId, new Verdict(status: UrlStatus::Ok, httpStatus: 200, method: 'head'));

    $row = linkAuditTtlRow($urlId);

    expect(linkAuditIsDue($urlId))->toBeFalse()
        ->and($row['status'])->toBe(UrlStatus::Ok->value)
        ->and(linkAuditHoursFromNow($row['nextCheckAfter']))->toBeGreaterThan(29 * 24)
        ->and($row['dateLastOk'])->not->toBeNull()
        ->and($row['dateLastChecked'])->not->toBeNull();

    linkAuditMakeStale($urlId);

    expect(linkAuditIsDue($urlId))->toBeTrue();
});

it('asks a broken link again the next day, not the next month', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $urlId = $store->upsert('https://example.com/gone', false);

    $store->recordVerdict($urlId, new Verdict(
        status: UrlStatus::Broken,
        httpStatus: 404,
        method: 'head',
        reason: Verdict::REASON_HTTP,
        message: 'Not Found',
        responseTimeMs: 142,
    ));

    $row = linkAuditTtlRow($urlId);
    $hours = linkAuditHoursFromNow($row['nextCheckAfter']);

    expect(linkAuditIsDue($urlId))->toBeFalse()
        ->and($hours)->toBeLessThanOrEqual(24)
        ->and($hours)->toBeGreaterThan(23)
        ->and($row['dateLastBroken'])->not->toBeNull()
        ->and($row['dateLastOk'])->toBeNull()
        ->and((int)$row['httpStatus'])->toBe(404)
        ->and($row['method'])->toBe('head')
        ->and($row['reason'])->toBe(Verdict::REASON_HTTP)
        ->and($row['message'])->toBe('Not Found')
        ->and((int)$row['responseTimeMs'])->toBe(142);

    linkAuditMakeStale($urlId);

    expect(linkAuditIsDue($urlId))->toBeTrue();
});

it('comes back to an unreachable link within hours', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $urlId = $store->upsert('https://example.com/timed-out', false);

    $store->recordVerdict($urlId, new Verdict(
        status: UrlStatus::Unreachable,
        reason: Verdict::REASON_TIMEOUT,
    ));

    $hours = linkAuditHoursFromNow(linkAuditTtlRow($urlId)['nextCheckAfter']);

    expect($hours)->toBeLessThanOrEqual(6)
        ->and($hours)->toBeGreaterThan(5);
});

it('leaves a bot-blocked link alone for a fortnight', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $urlId = $store->upsert('https://www.linkedin.com/in/someone', false);

    $store->recordVerdict($urlId, new Verdict(status: UrlStatus::Blocked, httpStatus: 999));

    $hours = linkAuditHoursFromNow(linkAuditTtlRow($urlId)['nextCheckAfter']);

    expect(linkAuditIsDue($urlId))->toBeFalse()
        ->and($hours)->toBeGreaterThan(13 * 24);
});

it('never schedules an ignored or unsafe URL, however stale it looks', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $ignoredId = $store->upsert('https://example.com/not-our-problem', false);
    $unsafeId = $store->upsert('https://169.254.169.254/latest/meta-data', false);

    $store->recordVerdict($ignoredId, new Verdict(status: UrlStatus::Ignored));
    $store->recordVerdict($unsafeId, new Verdict(
        status: UrlStatus::Unsafe,
        reason: Verdict::REASON_PRIVATE_IP,
    ));

    expect(linkAuditTtlRow($ignoredId)['nextCheckAfter'])->toBeNull()
        ->and(linkAuditTtlRow($unsafeId)['nextCheckAfter'])->toBeNull()
        ->and(linkAuditIsDue($ignoredId))->toBeFalse()
        ->and(linkAuditIsDue($unsafeId))->toBeFalse();

    linkAuditMakeStale($ignoredId);
    linkAuditMakeStale($unsafeId);

    expect(linkAuditIsDue($ignoredId))->toBeFalse()
        ->and(linkAuditIsDue($unsafeId))->toBeFalse();
});

it('counts consecutive failures and forgets them the moment the link answers', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $urlId = $store->upsert('https://example.com/flaky', false);
    $unreachable = new Verdict(status: UrlStatus::Unreachable, reason: Verdict::REASON_CONNECT);

    $store->recordVerdict($urlId, $unreachable);
    expect((int)linkAuditTtlRow($urlId)['failCount'])->toBe(1);

    $store->recordVerdict($urlId, $unreachable);
    expect((int)linkAuditTtlRow($urlId)['failCount'])->toBe(2);

    $store->recordVerdict($urlId, new Verdict(status: UrlStatus::Broken, httpStatus: 404));
    expect((int)linkAuditTtlRow($urlId)['failCount'])->toBe(2);

    $store->recordVerdict($urlId, new Verdict(status: UrlStatus::Ok, httpStatus: 200));
    expect((int)linkAuditTtlRow($urlId)['failCount'])->toBe(0);
});

it('calls a link broken once it has failed often enough to mean it', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $urlId = $store->upsert('https://example.com/keeps-timing-out', false);
    $timeout = new Verdict(status: UrlStatus::Unreachable, reason: Verdict::REASON_TIMEOUT);

    $store->recordVerdict($urlId, $timeout);
    expect(linkAuditTtlRow($urlId)['status'])->toBe(UrlStatus::Unreachable->value);

    $store->recordVerdict($urlId, $timeout);
    expect(linkAuditTtlRow($urlId)['status'])->toBe(UrlStatus::Unreachable->value);

    $store->recordVerdict($urlId, $timeout);
    $row = linkAuditTtlRow($urlId);

    expect($row['status'])->toBe(UrlStatus::Broken->value)
        ->and((int)$row['failCount'])->toBe(3)
        ->and($row['reason'])->toBe(Verdict::REASON_TIMEOUT)
        ->and($row['dateLastBroken'])->not->toBeNull()
        ->and(linkAuditHoursFromNow($row['nextCheckAfter']))->toBeLessThanOrEqual(24);
});

// A domain that has stopped resolving is nearly always a domain that is gone,
// so it gets a lower bar than a timeout: two strikes rather than three.
it('gives up on a host that does not resolve a check sooner', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $urlId = $store->upsert('https://gone-for-good.example/page', false);
    $noSuchHost = new Verdict(
        status: UrlStatus::Unreachable,
        reason: Verdict::REASON_DNS,
        message: 'Could not resolve host.',
    );

    $store->recordVerdict($urlId, $noSuchHost);
    expect(linkAuditTtlRow($urlId)['status'])->toBe(UrlStatus::Unreachable->value);

    $store->recordVerdict($urlId, $noSuchHost);
    $row = linkAuditTtlRow($urlId);

    expect($row['status'])->toBe(UrlStatus::Broken->value)
        ->and((int)$row['failCount'])->toBe(2)
        ->and($row['reason'])->toBe(Verdict::REASON_DNS);
});

it('forgets a run of DNS failures the moment the host resolves again', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $urlId = $store->upsert('https://back-from-the-dead.example/page', false);

    $store->recordVerdict($urlId, new Verdict(
        status: UrlStatus::Unreachable,
        reason: Verdict::REASON_DNS,
    ));
    $store->recordVerdict($urlId, new Verdict(status: UrlStatus::Ok, httpStatus: 200, method: 'head'));

    $row = linkAuditTtlRow($urlId);

    expect($row['status'])->toBe(UrlStatus::Ok->value)
        ->and((int)$row['failCount'])->toBe(0);

    $store->recordVerdict($urlId, new Verdict(
        status: UrlStatus::Unreachable,
        reason: Verdict::REASON_DNS,
    ));

    expect(linkAuditTtlRow($urlId)['status'])->toBe(UrlStatus::Unreachable->value);
});

it('records where a redirect ended up, whether it was permanent, and what it answered with', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $urlId = $store->upsert('https://example.com/old-address', false);

    $store->recordVerdict($urlId, new Verdict(
        status: UrlStatus::Redirect,
        httpStatus: 200,
        method: 'head',
        finalUrl: 'https://example.com/new-address',
        redirectCount: 1,
        redirectPermanent: true,
        redirectStatus: 301,
    ));

    $row = linkAuditTtlRow($urlId);

    expect($row['finalUrl'])->toBe('https://example.com/new-address')
        ->and((int)$row['redirectCount'])->toBe(1)
        ->and((bool)$row['redirectPermanent'])->toBeTrue()
        ->and((int)$row['redirectStatus'])->toBe(301)
        ->and($row['dateLastOk'])->not->toBeNull()
        ->and(linkAuditIsDue($urlId))->toBeFalse();
});

it('takes the redirect off a URL that has stopped redirecting', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $urlId = $store->upsert('https://example.com/moved-then-settled', false);

    $store->recordVerdict($urlId, new Verdict(
        status: UrlStatus::Redirect,
        httpStatus: 200,
        method: 'head',
        finalUrl: 'https://example.com/settled',
        redirectCount: 1,
        redirectPermanent: false,
        redirectStatus: 302,
    ));

    $store->recordVerdict($urlId, new Verdict(status: UrlStatus::Ok, httpStatus: 200, method: 'head'));

    $row = linkAuditTtlRow($urlId);

    // The row carries the last check and nothing older, so a link that answers
    // for itself now cannot go on showing somebody a 302 from a month ago.
    expect($row['status'])->toBe(UrlStatus::Ok->value)
        ->and($row['redirectStatus'])->toBeNull()
        ->and($row['finalUrl'])->toBeNull()
        ->and((int)$row['redirectCount'])->toBe(0);
});

it('writes nothing at all for a URL that was only set aside', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $urlId = $store->upsert('https://example.com/asked-too-often', false);

    $store->recordVerdict($urlId, new Verdict(status: UrlStatus::Ok, httpStatus: 200, method: 'head'));
    $store->recordVerdict($urlId, new Verdict(
        status: UrlStatus::Pending,
        httpStatus: 429,
        reason: Verdict::REASON_RATE_LIMITED,
        retryAfterSeconds: 60,
    ));

    $row = linkAuditTtlRow($urlId);

    expect($row['status'])->toBe(UrlStatus::Ok->value)
        ->and((int)$row['httpStatus'])->toBe(200)
        ->and(linkAuditIsDue($urlId))->toBeFalse();
});

it('stops offering a URL that was set aside until its time has come round', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $urlId = $store->upsert('https://example.com/set-aside', false);

    expect(linkAuditIsDue($urlId))->toBeTrue();

    $store->defer($urlId, 900);

    $row = linkAuditTtlRow($urlId);

    expect($row['status'])->toBe(UrlStatus::Pending->value)
        ->and($row['nextCheckAfter'])->not->toBeNull()
        // Fifty thousand rate limited URLs coming round again on every single
        // check phase is what this one line prevents.
        ->and(linkAuditIsDue($urlId))->toBeFalse();

    linkAuditMakeStale($urlId);

    expect(linkAuditIsDue($urlId))->toBeTrue();
});

it('offers a URL again the moment an author restores it', function() {
    $store = LinkAudit::getInstance()->getUrlStore();
    $urlId = $store->upsert('https://example.com/restored-after-a-deferral', false);
    $hash = UrlNormaliser::hash('https://example.com/restored-after-a-deferral');

    $store->defer($urlId, 900);
    LinkAudit::getInstance()->getIgnoreService()->ignoreUrl($hash, 'Leave it.');

    expect(linkAuditIsDue($urlId))->toBeFalse();

    LinkAudit::getInstance()->getIgnoreService()->restoreUrl($hash);

    expect(linkAuditTtlRow($urlId)['nextCheckAfter'])->toBeNull()
        ->and(linkAuditIsDue($urlId))->toBeTrue();
});

it('says nothing and does nothing when the URL row is gone', function() {
    LinkAudit::getInstance()->getUrlStore()->recordVerdict(999999, new Verdict(status: UrlStatus::Ok));
})->throwsNoExceptions();
