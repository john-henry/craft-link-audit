<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use johnhenry\linkaudit\helpers\ScannableElementTypes;
use johnhenry\linkaudit\LinkAudit;

/**
 * Stores the plugin's settings.
 *
 * Every value a scan reads lives here, grouped the way the settings screen is
 * tabbed: what gets scanned, how the HTTP checker behaves, how long a verdict
 * is trusted, what is ignored, who hears about it, and when a scan runs by
 * itself.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class SettingsModel extends Model
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * The identifying token carried by the default checker User-Agent, so a
     * single WAF or firewall rule matching this substring allow-lists the whole
     * checker at once.
     */
    public const USER_AGENT_TOKEN = 'CraftLinkAudit';

    /**
     * The information URL appended to the default User-Agent, so a host admin
     * who spots the token in their logs can look up what it is.
     */
    private const _USER_AGENT_INFO_URL = 'https://plugins.craftcms.com/link-audit';

    // =========================================================================
    // Public Properties
    // =========================================================================

    // Scanning
    // -------------------------------------------------------------------------

    /**
     * @var string[]|null The element type classes to scan, or null for every
     * native type that can carry links. Resolve the effective list with
     * {@see self::resolvedScannedElementTypes()}; never read this raw.
     */
    public ?array $scannedElementTypes = null;

    /**
     * @var bool Whether relation fields (Entries, Assets, Categories, Users)
     * are recorded as references. Off by default: Craft's own foreign keys
     * already guarantee the target row exists, so this only earns its keep when
     * you also want to catch targets that are disabled or have no URL.
     */
    public bool $scanRelationFields = false;

    /**
     * @var bool Whether plain text and table cell values are searched for
     * anything that parses as an http(s) URL. Off by default: it is the noisiest
     * source of false positives.
     */
    public bool $scanPlainTextUrls = false;

    /**
     * @var bool Whether image sources found in rich text are checked.
     */
    public bool $checkImages = true;

    /**
     * @var bool Whether iframe sources found in rich text are checked. Off by
     * default: embeds are routinely bot-hostile and rarely actionable.
     */
    public bool $checkIframes = false;

    /**
     * @var bool Whether links found in verbb/navigation nav nodes are scanned.
     * Navigations are a classic source of broken links because nothing in the
     * field walker ever sees them.
     */
    public bool $scanNavigationNodes = true;

    /**
     * @var bool Whether fragment links (#section) are validated against the ids
     * present in the rendered page. Needs [[renderedCrawlEnabled]] to be on,
     * since only rendered HTML can answer the question.
     */
    public bool $checkAnchorFragments = false;

    /**
     * @var bool Whether each element's own URL is fetched and its rendered HTML
     * parsed for links, on top of the stored field values. Catches links
     * hard-coded into templates (footers, navs) at the cost of one request per
     * page.
     */
    public bool $renderedCrawlEnabled = false;

    /**
     * @var int The ceiling on pages fetched in a single rendered crawl, so a
     * large site cannot turn one scan into tens of thousands of self-requests.
     */
    public int $maxPagesToCrawl = 500;

    /**
     * @var bool Whether saving an element re-extracts its links.
     */
    public bool $scanOnSave = true;

    /**
     * @var array<int, array<string, string>> URI patterns excluded from
     * scanning, as editable table rows.
     */
    public array $excludedUriPatterns = [];

    /**
     * @var string[] Section UIDs excluded from the audit altogether.
     *
     * Stored by UID rather than id, because settings travel through project
     * config and a section's UID is the one name it keeps across environments.
     * An excluded section is fenced both ways: its entries' fields are never
     * read, and an element link or relation pointing at one of its entries is
     * recorded as ignored rather than judged. That second half is what the
     * Excluded URI Patterns setting cannot do for a section whose entries have
     * no URLs at all.
     */
    public array $excludedSectionUids = [];

    /**
     * @var string[] Category group UIDs excluded from the audit altogether.
     *
     * The same fence as [[excludedSectionUids]], for the taxonomy case: a
     * category group without a URI format is the norm rather than the
     * exception, and a link or relation pointing at one of its categories
     * should not be judged broken for having no page.
     */
    public array $excludedCategoryGroupUids = [];

    /**
     * @var bool Whether internal links are resolved against the site's own
     * elements and routes.
     */
    public bool $checkInternalLinks = true;

    /**
     * @var array<int, array<string, string>> Internal URL patterns always
     * treated as valid, as editable table rows. The escape hatch for
     * template-only routes the resolver cannot see.
     */
    public array $internalUrlAllowPatterns = [];

    /**
     * @var bool Whether tracking parameters (utm_*, fbclid, gclid and friends)
     * are stripped before a URL is hashed, so the same link shared with three
     * different campaign tags is checked once.
     */
    public bool $stripTrackingParams = true;

    // HTTP
    // -------------------------------------------------------------------------

    /**
     * @var int How many requests are in flight across all hosts at once.
     */
    public int $concurrency = 10;

    /**
     * @var int How many requests are in flight against any one host at once.
     * Keep it low: this is what stops a scan reading like an attack.
     */
    public int $maxConcurrentPerHost = 2;

    /**
     * @var int The minimum gap in milliseconds between two requests to the same
     * host. Raised automatically for a host that answers with 429.
     */
    public int $minHostDelayMs = 250;

    /**
     * @var int Seconds allowed for the connection to be established.
     */
    public int $connectTimeout = 10;

    /**
     * @var int Seconds allowed for the whole request.
     */
    public int $timeout = 20;

    /**
     * @var int How many redirect hops are followed before the chain is called
     * off.
     */
    public int $maxRedirects = 5;

    /**
     * @var int How many times a connect error, timeout or 5xx is retried before
     * a verdict is recorded.
     */
    public int $retryCount = 2;

    /**
     * @var int How many consecutive failed checks a URL takes before it is
     * promoted from unreachable to broken, so one bad afternoon on someone
     * else's server does not flood the report.
     */
    public int $brokenAfterFailures = 3;

    /**
     * @var int How many consecutive failures a URL whose host does not resolve
     * takes before it is promoted from unreachable to broken. Lower than
     * {@see self::$brokenAfterFailures} on purpose: a timeout or a 500 is often
     * a server having a bad minute, but a domain that no longer resolves is
     * usually a domain that is gone.
     */
    public int $brokenAfterDnsFailures = 2;

    /**
     * @var string Overrides the User-Agent sent on every check. Supports
     * environment variable references. Empty uses the branded default.
     */
    public string $userAgent = '';

    /**
     * @var bool Whether TLS certificates are verified. Leave it on: turning it
     * off makes every https verdict meaningless.
     */
    public bool $verifySsl = true;

    /**
     * @var string An outbound HTTP proxy, e.g. 'http://proxy:8080'. Supports
     * environment variable references. Empty sends requests direct.
     */
    public string $proxy = '';

    // Caching and retention
    // -------------------------------------------------------------------------

    /**
     * @var int Days an ok verdict is trusted before the URL is checked again.
     */
    public int $okTtlDays = 30;

    /**
     * @var int Days a redirect verdict is trusted before the URL is checked
     * again.
     */
    public int $redirectTtlDays = 30;

    /**
     * @var int Hours before a broken URL is checked again. Short on purpose, so
     * a fix shows up in the report the same day.
     */
    public int $brokenRecheckHours = 24;

    /**
     * @var int Hours before an unreachable URL is checked again.
     */
    public int $unreachableRecheckHours = 6;

    /**
     * @var int Days before a bot-blocked URL is checked again. Long on purpose:
     * rechecking it sooner will not change the answer.
     */
    public int $blockedTtlDays = 14;

    /**
     * @var int Days of scan history kept. 0 keeps everything.
     */
    public int $retainDays = 90;

    /**
     * @var bool Whether URL rows with no remaining references are deleted when a
     * scan finishes.
     */
    public bool $pruneOrphanUrls = true;

    // Ignore rules
    // -------------------------------------------------------------------------

    /**
     * @var array<int, array<string, string>> URL patterns never checked, as
     * editable table rows of pattern and note.
     */
    public array $ignorePatterns = [];

    /**
     * @var array<int, array<string, string>> Hosts never checked, as editable
     * table rows.
     */
    public array $ignoreHosts = [];

    /**
     * @var array<int, array<string, string>> Extra hosts known to block
     * automated requests, as editable table rows. Added to the built-in list, so
     * their refusals are reported as unverifiable rather than broken.
     */
    public array $botHostileHosts = [];

    // Notifications
    // -------------------------------------------------------------------------

    /**
     * @var bool Whether an email is sent when a scan finishes with new broken
     * links.
     */
    public bool $notifyEmailEnabled = false;

    /**
     * @var string Comma separated email recipients. Supports environment
     * variable references.
     */
    public string $notifyEmailRecipients = '';

    /**
     * @var bool Whether a Slack message is posted when a scan finishes with new
     * broken links.
     */
    public bool $notifySlackEnabled = false;

    /**
     * @var string The Slack incoming webhook URL. Supports environment variable
     * references.
     */
    public string $notifySlackWebhookUrl = '';

    /**
     * @var bool Whether a notification counts only the links this scan itself
     * found broken, rather than everything on the site that is still broken.
     */
    public bool $notifyOnNewBroken = true;

    /**
     * @var int How many broken links have to be counted before a notification is
     * sent.
     */
    public int $notifyBrokenThreshold = 1;

    // Schedule
    // -------------------------------------------------------------------------

    /**
     * @var bool Whether the plugin queues its own incremental scan when one has
     * not run in a while. Best effort only, and no substitute for a cron entry:
     * it rides on Craft's garbage collection, which runs when it runs.
     */
    public bool $scheduledScanEnabled = false;

    /**
     * @var int Hours between scheduled incremental scans.
     */
    public int $scheduledScanIntervalHours = 24;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return array The validation rules.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function rules(): array
    {
        return [
            [
                [
                    'checkAnchorFragments',
                    'checkIframes',
                    'checkImages',
                    'checkInternalLinks',
                    'notifyEmailEnabled',
                    'notifyOnNewBroken',
                    'notifySlackEnabled',
                    'pruneOrphanUrls',
                    'renderedCrawlEnabled',
                    'scanNavigationNodes',
                    'scanOnSave',
                    'scanPlainTextUrls',
                    'scanRelationFields',
                    'scheduledScanEnabled',
                    'stripTrackingParams',
                    'verifySsl',
                ],
                'boolean',
            ],
            [['concurrency'], 'integer', 'min' => 1, 'max' => 50],
            [['maxConcurrentPerHost'], 'integer', 'min' => 1, 'max' => 10],
            [['minHostDelayMs'], 'integer', 'min' => 0, 'max' => 60000],
            [['connectTimeout'], 'integer', 'min' => 1, 'max' => 120],
            [['timeout'], 'integer', 'min' => 1, 'max' => 300],
            [['maxRedirects'], 'integer', 'min' => 0, 'max' => 20],
            [['retryCount'], 'integer', 'min' => 0, 'max' => 10],
            [['brokenAfterDnsFailures', 'brokenAfterFailures'], 'integer', 'min' => 1, 'max' => 20],
            [['maxPagesToCrawl'], 'integer', 'min' => 1, 'max' => 100000],
            [
                [
                    'blockedTtlDays',
                    'brokenRecheckHours',
                    'okTtlDays',
                    'redirectTtlDays',
                    'retainDays',
                    'unreachableRecheckHours',
                ],
                'integer',
                'min' => 0,
            ],
            [['notifyBrokenThreshold'], 'integer', 'min' => 1],
            [['scheduledScanIntervalHours'], 'integer', 'min' => 1, 'max' => 8760],
            [
                [
                    'notifyEmailRecipients',
                    'notifySlackWebhookUrl',
                    'proxy',
                    'userAgent',
                ],
                'string',
            ],
            [['scannedElementTypes'], 'each', 'rule' => ['string'], 'skipOnEmpty' => true],
            [['excludedCategoryGroupUids', 'excludedSectionUids'], 'each', 'rule' => ['string'], 'skipOnEmpty' => true],
            [
                [
                    'botHostileHosts',
                    'excludedUriPatterns',
                    'ignoreHosts',
                    'ignorePatterns',
                    'internalUrlAllowPatterns',
                ],
                'safe',
            ],
        ];
    }

    /**
     * @inheritdoc
     *
     * @return array The attribute labels.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function attributeLabels(): array
    {
        return [
            'blockedTtlDays' => 'Recheck Unverifiable Links After (days)',
            'botHostileHosts' => 'Extra Bot-Hostile Hosts',
            'brokenAfterDnsFailures' => 'Failures Before Broken (host does not resolve)',
            'brokenAfterFailures' => 'Failures Before Broken',
            'brokenRecheckHours' => 'Recheck Broken Links After (hours)',
            'checkAnchorFragments' => 'Validate Anchor Fragments',
            'checkIframes' => 'Check Iframe Sources',
            'checkImages' => 'Check Image Sources',
            'checkInternalLinks' => 'Check Internal Links',
            'concurrency' => 'Concurrent Requests',
            'connectTimeout' => 'Connect Timeout (seconds)',
            'excludedCategoryGroupUids' => 'Excluded Category Groups',
            'excludedSectionUids' => 'Excluded Sections',
            'excludedUriPatterns' => 'Excluded URI Patterns',
            'ignoreHosts' => 'Ignored Hosts',
            'ignorePatterns' => 'Ignored URL Patterns',
            'internalUrlAllowPatterns' => 'Always Valid Internal URLs',
            'maxConcurrentPerHost' => 'Concurrent Requests Per Host',
            'maxPagesToCrawl' => 'Maximum Pages Per Crawl',
            'maxRedirects' => 'Maximum Redirects',
            'minHostDelayMs' => 'Minimum Delay Per Host (ms)',
            'notifyBrokenThreshold' => 'Notify From (broken links)',
            'notifyEmailEnabled' => 'Email Notifications',
            'notifyEmailRecipients' => 'Email Recipients',
            'notifyOnNewBroken' => 'Only Count What This Scan Found',
            'notifySlackEnabled' => 'Slack Notifications',
            'notifySlackWebhookUrl' => 'Slack Webhook URL',
            'okTtlDays' => 'Trust Working Links For (days)',
            'pruneOrphanUrls' => 'Delete Unreferenced URLs',
            'redirectTtlDays' => 'Trust Redirects For (days)',
            'renderedCrawlEnabled' => 'Crawl Rendered Pages',
            'retainDays' => 'Retain Scan History (days)',
            'retryCount' => 'Retries',
            'scanNavigationNodes' => 'Scan Navigation Nodes',
            'scanOnSave' => 'Scan On Element Save',
            'scanPlainTextUrls' => 'Scan Plain Text Fields',
            'scanRelationFields' => 'Scan Relation Fields',
            'scannedElementTypes' => 'Scanned Element Types',
            'scheduledScanEnabled' => 'Scheduled Scans',
            'scheduledScanIntervalHours' => 'Scan Every (hours)',
            'stripTrackingParams' => 'Strip Tracking Parameters',
            'timeout' => 'Request Timeout (seconds)',
            'unreachableRecheckHours' => 'Recheck Unreachable Links After (hours)',
            'userAgent' => 'Checker User-Agent',
            'verifySsl' => 'Verify TLS Certificates',
        ];
    }

    /**
     * Whether anchor fragments are being validated.
     *
     * Both switches, always, and in one place so nothing can read one without
     * the other. Validating a fragment means fetching the page it points at and
     * looking for the id, which is the rendered crawl's business: an install
     * that has not asked for its own pages to be fetched has not asked for this
     * either, whatever a switch hidden behind a closed toggle still says.
     *
     * @return bool Whether a `#fragment` on an internal link is checked.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function checksAnchorFragments(): bool
    {
        return $this->renderedCrawlEnabled && $this->checkAnchorFragments;
    }

    /**
     * The User-Agent sent on every check: the configured override when set,
     * otherwise a branded default carrying the [[USER_AGENT_TOKEN]], the plugin
     * version, and an information URL.
     *
     * @return string The resolved User-Agent.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getCheckerUserAgent(): string
    {
        $override = trim(App::parseEnv($this->userAgent));

        if ($override !== '') {
            return $override;
        }

        return self::USER_AGENT_TOKEN . '/' . $this->_pluginVersion()
            . ' (+' . self::_USER_AGENT_INFO_URL . ')';
    }

    /**
     * The ids of the excluded sections on this install.
     *
     * The setting stores UIDs, because those survive the trip through project
     * config; everything that fences by section compares ids, because that is
     * what an entry carries. A UID naming a section that no longer exists is
     * simply dropped, the same way [[resolvedScannedElementTypes()]] drops a
     * dead class.
     *
     * @return int[] The section ids.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function excludedSectionIds(): array
    {
        if ($this->excludedSectionUids === []) {
            return [];
        }

        $ids = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if (in_array($section->uid, $this->excludedSectionUids, true)) {
                $ids[] = (int)$section->id;
            }
        }

        return $ids;
    }

    /**
     * The ids of the excluded category groups on this install.
     *
     * The category-group half of [[excludedSectionIds()]], with the same
     * shape and the same reasons.
     *
     * @return int[] The group ids.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function excludedCategoryGroupIds(): array
    {
        if ($this->excludedCategoryGroupUids === []) {
            return [];
        }

        $ids = [];

        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            if (in_array($group->uid, $this->excludedCategoryGroupUids, true)) {
                $ids[] = (int)$group->id;
            }
        }

        return $ids;
    }

    /**
     * The element type classes a scan should read, with anything no longer
     * registered dropped.
     *
     * Always read the scan set through here rather than off
     * [[scannedElementTypes]] directly: null means "the sensible default", and
     * a class left behind by an uninstalled plugin would otherwise put a dead
     * class name into the scan query.
     *
     * @return string[] The element type classes.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function resolvedScannedElementTypes(): array
    {
        $chosen = $this->scannedElementTypes ?? ScannableElementTypes::native();

        return array_values(array_intersect($chosen, array_keys(ScannableElementTypes::all())));
    }

    /**
     * The outbound proxy with environment variable references parsed, or null
     * when none is configured.
     *
     * @return string|null The resolved proxy URL.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getResolvedProxy(): ?string
    {
        $proxy = trim(App::parseEnv($this->proxy));

        return $proxy !== '' ? $proxy : null;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * The plugin's installed version, for stamping into the default User-Agent.
     * Falls back to a bare major when the instance is somehow unavailable.
     *
     * @return string The plugin version.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _pluginVersion(): string
    {
        return LinkAudit::getInstance()?->version ?? '1.0';
    }
}
