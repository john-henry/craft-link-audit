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
use johnhenry\linkaudit\records\IgnoreRecord;
use johnhenry\linkaudit\records\UrlRecord;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Ignores
//
// The one thing this table exists to guarantee is that an author's decision
// outlives the scan that follows it. Reference rows are rebuilt wholesale on
// every extraction and orphan URL rows are pruned when a scan finishes, so
// there are two separate ways a decision could quietly evaporate. Both are
// asserted below.
//
// Helper names carry an `ign` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/** The ignore service, freshly memoised. */
function ignService(): johnhenry\linkaudit\services\IgnoreService
{
    return LinkAudit::getInstance()->getIgnoreService();
}

/** A URL row, read back in full. */
function ignRow(string $urlHash): ?array
{
    $row = (new Query())
        ->from([UrlRecord::tableName()])
        ->where(['urlHash' => $urlHash])
        ->one();

    return is_array($row) ? $row : null;
}

/** How many ignore rows there are for a hash. */
function ignRowCount(string $urlHash): int
{
    return (int)(new Query())
        ->from([IgnoreRecord::tableName()])
        ->where(['urlHash' => $urlHash])
        ->count();
}

/** Whether the check phase would pick this URL up right now. */
function ignIsDue(string $urlHash): bool
{
    return LinkAudit::getInstance()
        ->getUrlStore()
        ->pendingQuery()
        ->andWhere(['urlHash' => $urlHash])
        ->exists();
}

/** A stored URL with one reference pointing at it, as an extraction would leave it. */
function ignStoreUrl(string $url, ?Verdict $verdict = null): array
{
    $store = LinkAudit::getInstance()->getUrlStore();
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $elementId = (int)UserFactory::factory()->create()->id;
    $urlId = $store->upsert($url, false);

    $store->replaceReferencesFor($elementId, $siteId, [
        ['urlId' => $urlId, 'elementType' => User::class, 'fieldHandle' => 'body'],
    ]);

    if ($verdict !== null) {
        $store->recordVerdict($urlId, $verdict);
    }

    return [
        'urlId' => $urlId,
        'hash' => UrlNormaliser::hash($url),
        'elementId' => $elementId,
        'siteId' => $siteId,
    ];
}

/**
 * Runs the check phase over one URL.
 *
 * One rather than everything that is due: this suite runs against a development
 * database that may well be carrying real pending URLs, and offering those to
 * the checker would put actual requests on the wire.
 */
function ignRunCheckPhase(string $urlHash): int
{
    $rows = LinkAudit::getInstance()
        ->getUrlStore()
        ->pendingQuery()
        ->andWhere(['urlHash' => $urlHash])
        ->all();

    return LinkAudit::getInstance()->getScanService()->checkChunk($rows);
}

describe('Ignoring a URL', function() {
    it('takes it out of the lists and stops it being offered for checking', function() {
        $stored = ignStoreUrl('https://example.com/not-our-problem', new Verdict(
            status: UrlStatus::Broken,
            httpStatus: 404,
        ));
        $report = LinkAudit::getInstance()->getReportService();

        $before = $report->urlTable(UrlStatus::Broken, $stored['siteId'], [], 1, 100)['total'];

        expect(ignService()->ignoreUrl($stored['hash']))->toBeTrue();

        $row = ignRow($stored['hash']);
        $after = $report->urlTable(UrlStatus::Broken, $stored['siteId'], [], 1, 100)['total'];

        expect($row['status'])->toBe(UrlStatus::Ignored->value)
            ->and($row['nextCheckAfter'])->toBeNull()
            ->and(ignIsDue($stored['hash']))->toBeFalse()
            ->and($after)->toBe($before - 1);
    });

    it('keeps the note and who made the decision', function() {
        $stored = ignStoreUrl('https://example.com/deliberate');
        $userId = (int)UserFactory::factory()->create()->id;

        ignService()->ignoreUrl($stored['hash'], '  Client asked us to leave it.  ', $userId);

        /** @var array<string, mixed> $ignore */
        $ignore = (new Query())
            ->from([IgnoreRecord::tableName()])
            ->where(['urlHash' => $stored['hash']])
            ->one();

        expect($ignore['scope'])->toBe('url')
            ->and($ignore['note'])->toBe('Client asked us to leave it.')
            ->and((int)$ignore['userId'])->toBe($userId)
            ->and($ignore['value'])->toBe('https://example.com/deliberate');
    });

    it('keeps one row when the same URL is ignored twice', function() {
        $stored = ignStoreUrl('https://example.com/twice');

        ignService()->ignoreUrl($stored['hash'], 'First reason');
        ignService()->ignoreUrl($stored['hash'], 'Second reason');

        /** @var array<string, mixed> $ignore */
        $ignore = (new Query())
            ->from([IgnoreRecord::tableName()])
            ->where(['urlHash' => $stored['hash']])
            ->one();

        expect(ignRowCount($stored['hash']))->toBe(1)
            ->and($ignore['note'])->toBe('Second reason');
    });

    it('says no when nothing has ever been seen at that address', function() {
        expect(ignService()->ignoreUrl(str_repeat('a', 40)))->toBeFalse();
    });

    it('lists it with the note, the date and whoever decided', function() {
        $stored = ignStoreUrl('https://example.com/listed');
        $user = UserFactory::factory()->create();

        ignService()->ignoreUrl($stored['hash'], 'Nobody minds this one.', (int)$user->id);

        $listed = null;

        foreach (ignService()->ignoredUrls() as $row) {
            if ($row['urlHash'] === $stored['hash']) {
                $listed = $row;
            }
        }

        expect($listed)->not->toBeNull()
            ->and($listed['url'])->toBe('https://example.com/listed')
            ->and($listed['note'])->toBe('Nobody minds this one.')
            ->and((int)$listed['userId'])->toBe((int)$user->id)
            ->and($listed['dateIgnored'])->not->toBeNull();
    });
});

describe('Restoring a URL', function() {
    it('puts it back in the queue and drops the decision', function() {
        $stored = ignStoreUrl('https://example.com/back-again', new Verdict(
            status: UrlStatus::Broken,
            httpStatus: 404,
        ));

        ignService()->ignoreUrl($stored['hash']);

        expect(ignService()->restoreUrl($stored['hash']))->toBeTrue()
            ->and(ignRow($stored['hash'])['status'])->toBe(UrlStatus::Pending->value)
            ->and(ignRowCount($stored['hash']))->toBe(0)
            ->and(ignIsDue($stored['hash']))->toBeTrue();
    });

    it('says no when the URL was not ignored in the first place', function() {
        $stored = ignStoreUrl('https://example.com/never-ignored');

        expect(ignService()->restoreUrl($stored['hash']))->toBeFalse();
    });
});

describe('Surviving a rescan', function() {
    it('holds the ignore through a full re-extraction of the same content', function() {
        $stored = ignStoreUrl('https://example.com/durable', new Verdict(
            status: UrlStatus::Broken,
            httpStatus: 404,
        ));
        $store = LinkAudit::getInstance()->getUrlStore();

        ignService()->ignoreUrl($stored['hash'], 'Leave it.');

        // What a scan does to this URL: the element is read again, its reference
        // rows are rebuilt from scratch, the URL is upserted again, and the check
        // phase is offered everything that is due.
        $urlId = $store->upsert('https://example.com/durable', false);
        $store->replaceReferencesFor($stored['elementId'], $stored['siteId'], [
            ['urlId' => $urlId, 'elementType' => User::class, 'fieldHandle' => 'body'],
        ]);
        ignRunCheckPhase($stored['hash']);

        $row = ignRow($stored['hash']);

        expect($urlId)->toBe($stored['urlId'])
            ->and($row['status'])->toBe(UrlStatus::Ignored->value)
            ->and(ignRowCount($stored['hash']))->toBe(1);
    });

    it('holds the ignore even after the URL row is pruned and comes back', function() {
        $stored = ignStoreUrl('https://example.com/pruned-and-back');
        $store = LinkAudit::getInstance()->getUrlStore();
        $scans = LinkAudit::getInstance()->getScanService();

        ignService()->ignoreUrl($stored['hash'], 'Leave it.');

        // The link comes out of the content, so the URL row is orphaned and a
        // finishing scan deletes it. The decision is in its own table and stays.
        $store->replaceReferencesFor($stored['elementId'], $stored['siteId'], []);
        // Pruning leaves a row alone for its first hour, so that a scan cannot
        // delete one another scan is still writing the references for.
        craft\helpers\Db::update(UrlRecord::tableName(), [
            'dateFirstSeen' => craft\helpers\Db::prepareDateForDb(
                craft\helpers\DateTimeHelper::now()->modify('-2 hours'),
            ),
        ], ['id' => $stored['urlId']]);
        $scans->pruneOrphanUrls();

        expect(ignRow($stored['hash']))->toBeNull()
            ->and(ignRowCount($stored['hash']))->toBe(1);

        // Then somebody puts the link back, and a later scan stores it afresh.
        $newUrlId = $store->upsert('https://example.com/pruned-and-back', false);

        expect(ignRow($stored['hash'])['status'])->toBe(UrlStatus::Ignored->value)
            ->and($newUrlId)->not->toBe($stored['urlId']);
    });

    it('refuses to write a fresh verdict over an ignored one', function() {
        $stored = ignStoreUrl('https://example.com/left-alone');
        $store = LinkAudit::getInstance()->getUrlStore();

        ignService()->ignoreUrl($stored['hash']);
        $store->recordVerdict($stored['urlId'], new Verdict(status: UrlStatus::Ok, httpStatus: 200));

        expect(ignRow($stored['hash'])['status'])->toBe(UrlStatus::Ignored->value);
    });

    it('keeps what the last check learned, so the decision can be reviewed', function() {
        $stored = ignStoreUrl('https://example.com/broken-then-ignored', new Verdict(
            status: UrlStatus::Broken,
            httpStatus: 404,
            method: 'head',
            reason: Verdict::REASON_HTTP,
            message: 'Not Found',
        ));

        ignService()->ignoreUrl($stored['hash'], 'Client knows.');
        ignRunCheckPhase($stored['hash']);

        // A rescan re-extracts the link and offers the URL again. Neither is
        // allowed to rub out the 404 that made somebody ignore it.
        LinkAudit::getInstance()->getUrlStore()->recordVerdict($stored['urlId'], new Verdict(
            status: UrlStatus::Ignored,
            reason: Verdict::REASON_IGNORED,
        ));

        $row = ignRow($stored['hash']);

        expect($row['status'])->toBe(UrlStatus::Ignored->value)
            ->and((int)$row['httpStatus'])->toBe(404)
            ->and($row['message'])->toBe('Not Found')
            ->and($row['reason'])->toBe(Verdict::REASON_IGNORED)
            ->and($row['dateLastChecked'])->not->toBeNull()
            ->and($row['dateLastBroken'])->not->toBeNull();
    });
});

describe('Settings rules', function() {
    it('quiets a URL matching an ignore pattern the next time it is checked', function() {
        $stored = ignStoreUrl('https://tickets.example.com/event/1234');

        expect(ignIsDue($stored['hash']))->toBeTrue();

        LinkAudit::getInstance()->getSettings()->ignorePatterns = [
            ['pattern' => 'tickets\.example\.com', 'note' => 'Always 403s a robot'],
        ];

        ignRunCheckPhase($stored['hash']);

        $row = ignRow($stored['hash']);

        expect($row['status'])->toBe(UrlStatus::Ignored->value)
            ->and($row['reason'])->toBe(Verdict::REASON_IGNORE_RULE)
            ->and($row['message'])->toContain('tickets\.example\.com')
            ->and(ignIsDue($stored['hash']))->toBeFalse();
    });

    it('quiets every URL on an ignored host, subdomains included', function() {
        $stored = ignStoreUrl('https://shop.quiet.example/product/9');

        LinkAudit::getInstance()->getSettings()->ignoreHosts = [
            ['host' => 'quiet.example', 'note' => 'Ours, and it moves constantly'],
        ];

        ignRunCheckPhase($stored['hash']);

        expect(ignRow($stored['hash'])['status'])->toBe(UrlStatus::Ignored->value)
            ->and(ignRow($stored['hash'])['reason'])->toBe(Verdict::REASON_IGNORE_RULE);
    });

    it('leaves a rule alone that matches nothing', function() {
        $url = 'https://elsewhere.example/still-checked';

        LinkAudit::getInstance()->getSettings()->ignorePatterns = [
            ['pattern' => 'never-matches-this', 'note' => ''],
        ];
        LinkAudit::getInstance()->getSettings()->ignoreHosts = [
            ['host' => 'somewhere-else.example', 'note' => ''],
        ];

        expect(ignService()->isIgnored($url))->toBeFalse()
            ->and(ignService()->ruleFor($url))->toBeNull();
    });

    it('ignores a disabled rule row', function() {
        LinkAudit::getInstance()->getSettings()->ignoreHosts = [
            ['host' => 'switched-off.example', 'note' => '', 'enabled' => false],
        ];

        expect(ignService()->ruleFor('https://switched-off.example/a'))->toBeNull();
    });

    it('says which of the two quieted a URL', function() {
        $byHand = ignStoreUrl('https://example.com/by-hand');
        ignService()->ignoreUrl($byHand['hash']);

        LinkAudit::getInstance()->getSettings()->ignoreHosts = [['host' => 'byrule.example']];

        expect(ignService()->verdictFor('https://example.com/by-hand')->reason)
            ->toBe(Verdict::REASON_IGNORED)
            ->and(ignService()->verdictFor('https://byrule.example/a')->reason)
            ->toBe(Verdict::REASON_IGNORE_RULE)
            ->and(ignService()->verdictFor('https://plain.example/a'))
            ->toBeNull();
    });

    it('shrugs at a pattern that is not a valid regular expression', function() {
        LinkAudit::getInstance()->getSettings()->ignorePatterns = [['pattern' => '([unclosed']];

        expect(ignService()->ruleFor('https://example.com/([unclosed'))->toBeNull();
    });

    it('keeps the ignore when the rule is taken back out of the settings', function() {
        $stored = ignStoreUrl('https://sticky.example/page');

        LinkAudit::getInstance()->getSettings()->ignoreHosts = [['host' => 'sticky.example']];
        ignRunCheckPhase($stored['hash']);

        expect(ignRow($stored['hash'])['status'])->toBe(UrlStatus::Ignored->value);

        LinkAudit::getInstance()->getSettings()->ignoreHosts = [];

        // A verdict is a row, and nothing rechecks a row it was told to leave
        // alone. Restoring is what puts it back, and that is the documented way
        // round.
        expect(ignIsDue($stored['hash']))->toBeFalse();

        ignService()->restoreUrl($stored['hash']);

        expect(ignIsDue($stored['hash']))->toBeTrue();
    });
});
