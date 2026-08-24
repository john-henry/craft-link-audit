<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use johnhenry\linkaudit\enums\ScanMode;
use johnhenry\linkaudit\enums\ScanStatus;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\Verdict;
use johnhenry\linkaudit\records\ScanRecord;
use johnhenry\linkaudit\records\UrlRecord;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// Telling somebody a scan found something
//
// Email goes out through Craft's own mailer, put into file transport for the
// duration, so what is asserted is a real composed message rather than a call
// on a mock. Slack goes out through an injected Guzzle client with history
// recording on it, which is what lets a test prove that a channel nobody turned
// on makes no request at all.
//
// Helper names carry a `notify` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/** Where file transport drops the messages this file sends. */
function notifyMailPath(): string
{
    return Craft::$app->getPath()->getTempPath() . '/link-audit-notification-test';
}

/** The messages sent since the last time the folder was cleared. */
function notifySentMessages(): array
{
    return array_map(
        static fn(string $path): string => (string)file_get_contents($path),
        (array)glob(notifyMailPath() . '/*.eml'),
    );
}

/** An open scan, with its clock started, for a notification to be about. */
function notifyOpenScan(?int $siteId = null): array
{
    $scan = new ScanRecord([
        'siteId' => $siteId,
        'mode' => ScanMode::CheckOnly->value,
        'status' => ScanStatus::Checking->value,
    ]);
    $scan->save(false);

    LinkAudit::getInstance()->getScanService()->markStatus((int)$scan->id, ScanStatus::Checking);

    /** @var array<string, mixed> $row */
    $row = LinkAudit::getInstance()->getScanService()->getScan((int)$scan->id);

    return $row;
}

/** A broken URL with one reference on the primary site. */
function notifySeedBroken(string $url, int $httpStatus = 404): int
{
    $store = LinkAudit::getInstance()->getUrlStore();
    $urlId = $store->upsert($url, false);

    $store->recordVerdict($urlId, new Verdict(status: UrlStatus::Broken, httpStatus: $httpStatus));
    $store->replaceReferencesFor(
        (int)UserFactory::factory()->create()->id,
        (int)Craft::$app->getSites()->getPrimarySite()->id,
        [['urlId' => $urlId, 'elementType' => craft\elements\User::class]],
    );

    return $urlId;
}

/**
 * Drags a URL's breakage back into the past, so it reads as a link that was
 * already broken before the scan under test opened. Stored dates are only
 * accurate to the second, which is not enough to tell two rows written in the
 * same breath apart.
 */
function notifyBackdateBroken(int $urlId, string $modifier = '-1 hour'): void
{
    Db::update(UrlRecord::tableName(), [
        'dateLastBroken' => Db::prepareDateForDb(DateTimeHelper::now()->modify($modifier)),
    ], ['id' => $urlId]);
}

/**
 * A webhook URL the SSRF guard will pass without a name server.
 *
 * Built on the hostname this installation serves, which the guard exempts by
 * design: a local or intranet install legitimately resolves to a private
 * address, and that host comes from Craft's own site config rather than from
 * anybody's input.
 */
function notifyWebhookUrl(): string
{
    $host = parse_url(
        (string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(),
        PHP_URL_HOST,
    );

    return "https://$host/link-audit-test-webhook/T000/B000/xyz";
}

/** A Guzzle client that records every request instead of making one. */
function notifyRecordingClient(array &$history): Client
{
    $stack = HandlerStack::create(new MockHandler([new Response(200), new Response(200)]));
    $stack->push(Middleware::history($history));

    return new Client(['handler' => $stack]);
}

beforeEach(function() {
    $mailer = Craft::$app->getMailer();
    $mailer->useFileTransport = true;
    $mailer->fileTransportPath = notifyMailPath();

    if (is_dir(notifyMailPath())) {
        FileHelper::clearDirectory(notifyMailPath());
    }
});

afterEach(function() {
    Craft::$app->getMailer()->useFileTransport = false;
    LinkAudit::getInstance()->getNotificationService()->setClient(Craft::createGuzzleClient());
});

describe('Email', function() {
    it('writes to whoever is listed once the count reaches the threshold', function() {
        $settings = LinkAudit::getInstance()->getSettings();
        $settings->notifyEmailEnabled = true;
        $settings->notifyEmailRecipients = 'reports@example.com, someone@example.com';
        $settings->notifyBrokenThreshold = 1;

        $scan = notifyOpenScan();
        notifySeedBroken('https://example.com/notify-me-gone');

        LinkAudit::getInstance()->getScanService()->finalise((int)$scan['id']);

        $messages = notifySentMessages();

        expect($messages)->toHaveCount(1)
            ->and($messages[0])->toContain('reports@example.com')
            ->and($messages[0])->toContain('someone@example.com')
            ->and($messages[0])->toContain('notify-me-gone')
            ->and($messages[0])->toContain('broken link');
    });

    it('says nothing when fewer links are broken than the threshold', function() {
        $settings = LinkAudit::getInstance()->getSettings();
        $settings->notifyEmailEnabled = true;
        $settings->notifyEmailRecipients = 'reports@example.com';
        $settings->notifyBrokenThreshold = 3;

        $scan = notifyOpenScan();
        notifySeedBroken('https://example.com/only-one-gone');

        LinkAudit::getInstance()->getScanService()->finalise((int)$scan['id']);

        expect(notifySentMessages())->toBeEmpty();
    });

    it('says nothing at all when nobody has turned a channel on', function() {
        $scan = notifyOpenScan();
        notifySeedBroken('https://example.com/nobody-is-listening');

        LinkAudit::getInstance()->getScanService()->finalise((int)$scan['id']);

        expect(notifySentMessages())->toBeEmpty();
    });

    it('counts only what the scan itself found, when it is told to', function() {
        $settings = LinkAudit::getInstance()->getSettings();
        $settings->notifyEmailEnabled = true;
        $settings->notifyEmailRecipients = 'reports@example.com';
        $settings->notifyOnNewBroken = true;

        // Broken before this scan opened, so this scan is not what found it.
        notifyBackdateBroken(notifySeedBroken('https://example.com/broken-since-last-week'));
        $scan = notifyOpenScan();

        LinkAudit::getInstance()->getScanService()->finalise((int)$scan['id']);

        expect(notifySentMessages())->toBeEmpty();
    });
});

describe('Slack', function() {
    it('makes no request when nobody turned it on', function() {
        $history = [];
        LinkAudit::getInstance()->getNotificationService()->setClient(notifyRecordingClient($history));

        $settings = LinkAudit::getInstance()->getSettings();
        $settings->notifyEmailEnabled = true;
        $settings->notifyEmailRecipients = 'reports@example.com';
        $settings->notifySlackEnabled = false;
        $settings->notifySlackWebhookUrl = 'https://hooks.slack.example/should-never-be-called';

        $scan = notifyOpenScan();
        notifySeedBroken('https://example.com/slack-is-off');

        LinkAudit::getInstance()->getScanService()->finalise((int)$scan['id']);

        expect(notifySentMessages())->toHaveCount(1)
            ->and($history)->toBeEmpty();
    });

    it('posts to the webhook the environment variable points at', function() {
        // The webhook goes through the SSRF guard like every other outbound
        // request, so the host has to be one the guard will pass without this
        // suite needing to reach a name server. The guard exempts the hostname
        // this installation serves, which is exactly such a host.
        $webhook = notifyWebhookUrl();

        putenv("LINK_AUDIT_TEST_WEBHOOK=$webhook");
        $_SERVER['LINK_AUDIT_TEST_WEBHOOK'] = $webhook;

        $history = [];
        LinkAudit::getInstance()->getNotificationService()->setClient(notifyRecordingClient($history));

        $settings = LinkAudit::getInstance()->getSettings();
        $settings->notifySlackEnabled = true;
        $settings->notifySlackWebhookUrl = '$LINK_AUDIT_TEST_WEBHOOK';

        $scan = notifyOpenScan();
        notifySeedBroken('https://example.com/slack-hears-about-it');

        LinkAudit::getInstance()->getScanService()->finalise((int)$scan['id']);

        expect($history)->toHaveCount(1);

        $request = $history[0]['request'];
        $payload = (array)json_decode((string)$request->getBody(), true);

        expect((string)$request->getUri())->toBe($webhook)
            ->and($payload['text'])->toContain('slack-hears-about-it')
            ->and($payload['text'])->toContain('broken link');

        putenv('LINK_AUDIT_TEST_WEBHOOK');
        unset($_SERVER['LINK_AUDIT_TEST_WEBHOOK']);
    });

    it('refuses a webhook pointed at a private address, and makes no request', function() {
        $history = [];
        LinkAudit::getInstance()->getNotificationService()->setClient(notifyRecordingClient($history));

        $settings = LinkAudit::getInstance()->getSettings();
        $settings->notifySlackEnabled = true;
        $settings->notifySlackWebhookUrl = 'http://169.254.169.254/latest/meta-data/';

        $scan = notifyOpenScan();
        notifySeedBroken('https://example.com/slack-must-not-hear-about-it');

        LinkAudit::getInstance()->getScanService()->finalise((int)$scan['id']);

        expect($history)->toBeEmpty();
    });

    it('refuses a webhook with a scheme it never posts to', function() {
        $history = [];
        LinkAudit::getInstance()->getNotificationService()->setClient(notifyRecordingClient($history));

        $settings = LinkAudit::getInstance()->getSettings();
        $settings->notifySlackEnabled = true;
        $settings->notifySlackWebhookUrl = 'file:///etc/passwd';

        $scan = notifyOpenScan();
        notifySeedBroken('https://example.com/slack-scheme-refused');

        LinkAudit::getInstance()->getScanService()->finalise((int)$scan['id']);

        expect($history)->toBeEmpty();
    });
});
