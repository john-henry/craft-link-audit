<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\services;

use Craft;
use craft\db\Query;
use craft\helpers\App;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\exceptions\UnsafeUrlException;
use johnhenry\linkaudit\helpers\UrlSafety;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\SettingsModel;
use johnhenry\linkaudit\models\Verdict;
use johnhenry\linkaudit\records\ReferenceRecord;
use johnhenry\linkaudit\records\UrlRecord;
use Throwable;
use yii\base\Component;

/**
 * Tells somebody when a scan has found broken links.
 *
 * Two channels, both optional and both off until an admin turns them on: an
 * email to whoever should hear about it, and a post to a Slack webhook.
 *
 * What counts as "newly broken" is worth being plain about, because it decides
 * what lands in somebody's inbox. With `notifyOnNewBroken` on, which is the
 * default, a URL counts when this scan is what found it broken: its last broken
 * date falls inside the scan's own window. With it off, every broken link the
 * site is still carrying counts, however long it has been that way. Either way
 * a URL nothing points at any more is left out, since a broken link on no page
 * is nobody's problem.
 *
 * Nothing in here is allowed to take a scan down with it. A mail server that is
 * refusing connections or a webhook that has been revoked is logged and stepped
 * over: the scan finished, and the report is on the screen whether or not the
 * message got out.
 *
 * The Guzzle client is injectable, in the same shape {@see HttpChecker} uses, so
 * a test can prove that a disabled channel makes no request at all.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class NotificationService extends Component
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var int The most URLs listed in one message. The rest are counted rather
     * than named: nobody reads a hundred URLs in an email, and the list on the
     * screen is one click away.
     */
    private const _MAX_LISTED = 20;

    /**
     * @var int Seconds allowed for the whole webhook post.
     */
    private const _WEBHOOK_TIMEOUT = 10;

    // =========================================================================
    // Private Properties
    // =========================================================================

    /**
     * @var ClientInterface|null The HTTP client used for webhooks, built on
     * first use.
     */
    private ?ClientInterface $_client = null;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * The HTTP client webhooks go out on.
     *
     * @return ClientInterface The client.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getClient(): ClientInterface
    {
        if ($this->_client === null) {
            $this->_client = Craft::createGuzzleClient();
        }

        return $this->_client;
    }

    /**
     * Reports a finished scan, when there is anything worth reporting and
     * somebody has asked to hear about it.
     *
     * @param array<string, mixed> $scan The scan row, as
     *                                   {@see ScanService::getScan()} returns
     *                                   it.
     * @return bool Whether a message was sent. False is the ordinary answer:
     *              both channels off, or a count under the threshold.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function notifyScanComplete(array $scan): bool
    {
        $settings = $this->_settings();

        if (!$settings->notifyEmailEnabled && !$settings->notifySlackEnabled) {
            return false;
        }

        $total = (int)$this->_brokenQuery($scan)->count();

        if ($total < max(1, $settings->notifyBrokenThreshold)) {
            return false;
        }

        $urls = $this->_brokenQuery($scan)->limit(self::_MAX_LISTED)->all();
        $subject = $this->_subject($total);
        $body = $this->_body($total, $urls, $scan);

        if ($settings->notifyEmailEnabled) {
            $this->sendEmail($subject, $body);
        }

        if ($settings->notifySlackEnabled) {
            $this->postToSlack($subject, $body);
        }

        return true;
    }

    /**
     * Posts a message to the configured Slack webhook.
     *
     * The webhook goes through the same guard every other outbound request in
     * this plugin does. It is a URL out of the settings rather than out of a
     * content field, but it is still a URL this server is asked to POST to, and
     * an environment variable is exactly the sort of thing that gets pointed
     * somewhere it should not be on a staging box. A refusal is logged and
     * stepped over, in the shape {@see PageCrawler::fetch()} uses: a scan that
     * finished is not taken down by a message that did not go out.
     *
     * @param string $subject The lead line.
     * @param string $body The message.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function postToSlack(string $subject, string $body): void
    {
        $webhookUrl = trim(App::parseEnv($this->_settings()->notifySlackWebhookUrl));

        if ($webhookUrl === '') {
            Craft::warning('Slack notifications are on, but no webhook URL is set.', 'link-audit');

            return;
        }

        try {
            UrlSafety::assertSafeUrl($webhookUrl);
        } catch (UnsafeUrlException $e) {
            Craft::warning("Did not post the link report to Slack: {$e->getMessage()}", 'link-audit');

            return;
        }

        try {
            $this->getClient()->request('POST', $webhookUrl, [
                RequestOptions::TIMEOUT => self::_WEBHOOK_TIMEOUT,
                RequestOptions::HEADERS => ['Content-Type' => 'application/json'],
                RequestOptions::BODY => Json::encode([
                    'username' => Craft::t('link-audit', 'Link Audit'),
                    // Slack eats a bare angle bracket or ampersand as markup, so
                    // the three characters it cares about are escaped and the
                    // rest of the text is left alone.
                    'text' => '*' . $this->_slackEscape($subject) . "*\n" . $this->_slackEscape($body),
                ]),
            ]);
        } catch (Throwable $e) {
            Craft::error("Could not post the link report to Slack: {$e->getMessage()}", 'link-audit');
        }
    }

    /**
     * Emails a message to everybody on the recipient list.
     *
     * @param string $subject The subject line.
     * @param string $body The message.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function sendEmail(string $subject, string $body): void
    {
        $recipients = $this->_recipients();

        if ($recipients === []) {
            Craft::warning('Email notifications are on, but nobody is listed to receive them.', 'link-audit');

            return;
        }

        try {
            Craft::$app->getMailer()
                ->compose()
                ->setTo($recipients)
                ->setSubject($subject)
                ->setTextBody($body)
                ->send();
        } catch (Throwable $e) {
            Craft::error("Could not send the link report by email: {$e->getMessage()}", 'link-audit');
        }
    }

    /**
     * Sets the HTTP client webhooks go out on.
     *
     * @param ClientInterface $client The client.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function setClient(ClientInterface $client): void
    {
        $this->_client = $client;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * The message body: what the scan found, the first few URLs by name, and
     * where to read the rest.
     *
     * @param int $total How many broken links were counted.
     * @param array<int, array<string, mixed>> $urls The ones being named.
     * @param array<string, mixed> $scan The scan row.
     * @return string The body.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _body(int $total, array $urls, array $scan): string
    {
        $lines = [
            Craft::t(
                'link-audit',
                'The link scan on {site} has finished, and {count, plural, =1{one broken link came} other{# broken links came}} out of it.',
                [
                    'site' => Craft::$app->getSystemName(),
                    'count' => $total,
                ],
            ),
            '',
        ];

        $report = LinkAudit::$plugin->getReportService();
        $siteId = $scan['siteId'] !== null
            ? (int)$scan['siteId']
            : (int)Craft::$app->getSites()->getPrimarySite()->id;

        foreach ($urls as $url) {
            // The stored reason is a developer's code word, so it is put through
            // the same label the screens use rather than dropped into somebody's
            // inbox as it stands.
            $note = $url['httpStatus'] !== null
                ? (string)$url['httpStatus']
                : (string)(Verdict::reasonLabel($url['reason'] !== null ? (string)$url['reason'] : null) ?? '');

            // The label the screens use, so a stand-in for an element link is
            // named by its target's title rather than the internal marker.
            $label = $report->urlLabel((string)$url['url'], $siteId);
            $detailUrl = UrlHelper::cpUrl('link-audit/url', ['hash' => (string)$url['urlHash']]);

            $lines[] = $note !== ''
                ? sprintf('- %s (%s)', $label, $note)
                : '- ' . $label;
            // The link goes to the URL's own page, where the pages carrying it
            // are named, each with an Edit link that opens the entry on the
            // very field the link sits in.
            $lines[] = '  ' . $detailUrl;
        }

        if ($total > count($urls)) {
            $lines[] = '';
            $lines[] = Craft::t('link-audit', 'And {count} more.', ['count' => $total - count($urls)]);
        }

        $lines[] = '';
        $lines[] = Craft::t(
            'link-audit',
            'The note after each link is what the website said when it was asked. The full list, and the pages carrying each one, is here: {url}',
            ['url' => $this->_reportUrl($scan)],
        );

        return implode("\n", $lines);
    }

    /**
     * The broken URLs this message is about.
     *
     * Orphans are left out through the reference join: a URL nothing points at
     * any more is not a broken link on anybody's page. When the scan covered one
     * site, only references on that site count, so a message about one site does
     * not carry another's links.
     *
     * @param array<string, mixed> $scan The scan row.
     * @return Query The query, newest breakage first.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _brokenQuery(array $scan): Query
    {
        $references = (new Query())
            ->select(['urlId'])
            ->from([ReferenceRecord::tableName()]);

        if (($scan['siteId'] ?? null) !== null) {
            $references->where(['siteId' => (int)$scan['siteId']]);
        }

        $query = (new Query())
            ->select(['id', 'url', 'urlHash', 'host', 'httpStatus', 'reason', 'dateLastBroken'])
            ->from([UrlRecord::tableName()])
            ->where(['status' => UrlStatus::Broken->value])
            ->andWhere(['id' => $references])
            ->orderBy(['dateLastBroken' => SORT_DESC, 'id' => SORT_ASC]);

        $startedAt = $scan['dateStarted'] ?? null;

        // The stored date is already in the database's own format, so it is
        // compared as it stands rather than being parsed and formatted again.
        if ($this->_settings()->notifyOnNewBroken && $startedAt !== null) {
            $query->andWhere(['>=', 'dateLastBroken', (string)$startedAt]);
        }

        return $query;
    }

    /**
     * Where to send the reader for the whole story.
     *
     * @param array<string, mixed> $scan The scan row.
     * @return string The control panel URL.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _reportUrl(array $scan): string
    {
        $params = [];

        if (($scan['siteId'] ?? null) !== null) {
            $site = Craft::$app->getSites()->getSiteById((int)$scan['siteId']);

            if ($site !== null) {
                $params['site'] = $site->handle;
            }
        }

        return UrlHelper::cpUrl('link-audit/broken', $params);
    }

    /**
     * The email addresses to write to.
     *
     * Split on commas and line breaks alike, so a list pasted in from anywhere
     * works, and run through the environment parser first, since a recipient
     * list is the sort of thing that differs between staging and production.
     *
     * @return string[] The addresses.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _recipients(): array
    {
        $raw = trim(App::parseEnv($this->_settings()->notifyEmailRecipients));
        $parts = preg_split('/[,\r\n]+/', $raw) ?: [];

        return array_values(array_filter(
            array_map('trim', $parts),
            static fn(string $address): bool => $address !== '',
        ));
    }

    /**
     * The plugin's settings.
     *
     * @return SettingsModel The settings.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _settings(): SettingsModel
    {
        return LinkAudit::$plugin->getSettings();
    }

    /**
     * Escapes the three characters Slack reads as markup.
     *
     * @param string $text The text.
     * @return string The escaped text.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _slackEscape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    /**
     * The subject line.
     *
     * @param int $total How many broken links were counted.
     * @return string The subject.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _subject(int $total): string
    {
        return Craft::t('link-audit', '{count, plural, =1{One broken link} other{# broken links}} on {site}', [
            'count' => $total,
            'site' => Craft::$app->getSystemName(),
        ]);
    }
}
