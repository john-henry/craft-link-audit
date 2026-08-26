<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\console\controllers;

use Craft;
use craft\console\Controller;
use craft\db\Query;
use craft\helpers\Console;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use johnhenry\linkaudit\enums\ScanMode;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\exceptions\ScanInProgressException;
use johnhenry\linkaudit\helpers\QueueJobs;
use johnhenry\linkaudit\helpers\UrlNormaliser;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\records\ReferenceRecord;
use johnhenry\linkaudit\records\ScanRecord;
use johnhenry\linkaudit\records\UrlRecord;
use Throwable;
use yii\base\InvalidConfigException;
use yii\console\Exception as ConsoleException;
use yii\console\ExitCode;

/**
 * Link audit console commands.
 *
 * Usage:
 *   php craft link-audit/scan/all --site=default
 *   php craft link-audit/scan/incremental
 *   php craft link-audit/scan/element --element-id=42
 *   php craft link-audit/scan/check-pending
 *   php craft link-audit/scan/recheck-broken [--all]
 *   php craft link-audit/scan/recheck-url --url=https://example.com/page
 *   php craft link-audit/scan/cancel
 *   php craft link-audit/scan/prune --days=90
 *   php craft link-audit/scan/reset --force
 *   php craft link-audit/scan/report
 *
 * The scanning commands queue work rather than doing it, so a scan of a large
 * site is not one command hanging on a terminal for an hour. Run the queue after
 * them, or leave it to a worker.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class ScanController extends Controller
{
    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @var string The default action.
     */
    public $defaultAction = 'report';

    /**
     * @var bool Bring every URL forward on a recheck, working links included,
     * rather than only the broken and unanswered ones.
     */
    public bool $all = false;

    /**
     * @var int Days of scan history to keep when pruning. Defaults to the
     * configured retention.
     */
    public int $days = 0;

    /**
     * @var int|null The element to read, for a single element scan.
     */
    public ?int $elementId = null;

    /**
     * @var bool Skip the confirmation on a reset, for a script that has nobody
     * sitting in front of it.
     */
    public bool $force = false;

    /**
     * @var string|null The handle of the site to cover. Every site when it is
     * not given.
     */
    public ?string $site = null;

    /**
     * @var string|null The URL to check again, for a single URL recheck.
     */
    public ?string $url = null;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Queues a full scan: every scannable element, then every URL that has gone
     * stale.
     *
     * @return int The exit code.
     * @throws ConsoleException If the site handle does not belong to a site.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionAll(): int
    {
        return $this->_queue(ScanMode::Full);
    }

    /**
     * Calls off the run that is going, and takes its queued work out of the
     * queue.
     *
     * @return int The exit code.
     * @throws InvalidConfigException If the queue component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionCancel(): int
    {
        // Counted before rather than after, because by the time the cancel comes
        // back the jobs it released are gone and there is nothing left to count.
        $queued = QueueJobs::count();
        $scan = LinkAudit::$plugin->getScanService()->cancelScan();

        if ($scan === null) {
            $this->stdout("Nothing is running, so there was nothing to call off.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $this->stdout(sprintf(
            "Cancelled scan %d (%s): released %d queued job(s).\n",
            $scan->id,
            $scan->mode,
            $queued,
        ), Console::FG_GREEN);
        $this->stdout(
            "Everything it had already checked keeps its verdict. The content it never\n"
            . "reached is read by the next scan.\n",
        );

        return ExitCode::OK;
    }

    /**
     * Queues the check phase on its own, for the URLs already waiting.
     *
     * @return int The exit code.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionCheckPending(): int
    {
        $waiting = (int)LinkAudit::$plugin->getUrlStore()->pendingQuery()->count();

        if ($waiting === 0) {
            $this->stdout("Nothing is waiting to be checked.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $scan = LinkAudit::$plugin->getScanService()->startScan(ScanMode::CheckOnly);

        $this->stdout(
            "Queued scan $scan->id: $waiting URLs waiting to be checked.\n",
            Console::FG_GREEN,
        );

        return ExitCode::OK;
    }

    /**
     * Reads one element now, rather than queueing anything.
     *
     * @return int The exit code.
     * @throws ConsoleException If the site handle does not belong to a site.
     * @throws Throwable If the element's reference rows cannot be rebuilt.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionElement(): int
    {
        if ($this->elementId === null) {
            $this->stderr("Pass --element-id.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $siteId = $this->_siteId();
        $found = LinkAudit::$plugin->getScanService()->scanElement($this->elementId, $siteId);

        $this->stdout(
            sprintf("Read element %d: %d links stored.\n", $this->elementId, $found),
            Console::FG_GREEN,
        );

        return ExitCode::OK;
    }

    /**
     * Queues a scan of the elements edited since the last completed run.
     *
     * @return int The exit code.
     * @throws ConsoleException If the site handle does not belong to a site.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionIncremental(): int
    {
        $since = LinkAudit::$plugin->getScanService()->lastCompletedScanStart();

        if ($since === null) {
            $this->stdout(
                "No completed scan to work from, so this one covers everything.\n",
                Console::FG_YELLOW,
            );
        } else {
            $this->stdout('Reading everything edited since ' . $since->format('Y-m-d H:i') . " UTC.\n");
        }

        return $this->_queue(ScanMode::Incremental);
    }

    /**
     * Deletes scan history past the retention window, and any URL row nothing
     * points at.
     *
     * @return int The exit code.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionPrune(): int
    {
        $days = $this->days > 0 ? $this->days : LinkAudit::$plugin->getSettings()->retainDays;

        if ($days <= 0) {
            $this->stdout("Retention is off, so no scan history was pruned.\n", Console::FG_YELLOW);
        } else {
            $cutOff = DateTimeHelper::now()->modify("-$days days");
            $scans = Craft::$app->getDb()->createCommand()
                ->delete(ScanRecord::tableName(), ['<', 'dateCreated', Db::prepareDateForDb($cutOff)])
                ->execute();

            $this->stdout(
                sprintf("Removed %d scans older than %d days.\n", $scans, $days),
                Console::FG_GREEN,
            );
        }

        $urls = LinkAudit::$plugin->getScanService()->pruneOrphanUrls();

        $this->stdout(sprintf("Removed %d URLs nothing points at.\n", $urls), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Brings every broken URL forward so the next check phase asks it again,
     * then queues that check.
     *
     * With `--all`, everything but the ignored comes forward, working links
     * included: the sweep for after a migration or a hosting move, when every
     * verdict deserves a fresh answer without throwing the history away the
     * way a reset would.
     *
     * @return int The exit code.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionRecheckBroken(): int
    {
        // Dragging the next check date into the past is what puts these back in
        // front of the check phase: the status alone would not, since a broken
        // URL is only offered again once its recheck window has run out.
        $condition = $this->all
            ? ['not', ['status' => UrlStatus::Ignored->value]]
            : ['status' => [UrlStatus::Broken->value, UrlStatus::Unreachable->value]];

        $reset = Craft::$app->getDb()->createCommand()
            ->update(
                UrlRecord::tableName(),
                ['nextCheckAfter' => Db::prepareDateForDb(DateTimeHelper::now()->modify('-1 minute'))],
                $condition,
            )
            ->execute();

        if ($reset === 0) {
            $this->stdout("Nothing is broken, so there is nothing to ask again.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $scan = LinkAudit::$plugin->getScanService()->startScan(ScanMode::CheckOnly);

        $this->stdout(
            $this->all
                ? "Queued scan $scan->id: $reset URLs will be asked again, everything but the ignored.\n"
                : "Queued scan $scan->id: $reset broken or unreachable URLs will be asked again.\n",
            Console::FG_GREEN,
        );

        return ExitCode::OK;
    }

    /**
     * Checks one URL again, there and then, and prints what came back.
     *
     * Inline rather than queued, the same as the Check again button in the
     * control panel: it is one URL and somebody is sitting at a terminal
     * waiting on the answer.
     *
     * @return int The exit code.
     * @throws Throwable If the check cannot be run.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionRecheckUrl(): int
    {
        if ($this->url === null || trim($this->url) === '') {
            $this->stderr("Pass --url.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $settings = LinkAudit::$plugin->getSettings();
        $normalised = UrlNormaliser::normalise(trim($this->url), null, $settings->stripTrackingParams);

        if ($normalised === null) {
            $this->stderr("That is not a checkable URL. Give the full address, scheme and all.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $report = LinkAudit::$plugin->getReportService();
        $hash = UrlNormaliser::hash($normalised);
        $row = $report->urlByHash($hash);

        if ($row === null) {
            $this->stderr("No URL has been seen with that address. It has to be found by a scan before it can be rechecked.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        if ((string)$row['status'] === UrlStatus::Ignored->value) {
            $this->stdout("That URL is ignored, so it is not checked. Restore it first.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $checked = LinkAudit::$plugin->getScanService()->checkChunk([$row]);

        if ($checked === 0) {
            $this->stdout("The host asked to be left alone for a while, so nothing was checked. Try again shortly.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $fresh = $report->urlByHash($hash) ?? $row;
        $status = UrlStatus::tryFrom((string)$fresh['status']) ?? UrlStatus::Pending;
        $code = $fresh['httpStatus'] !== null ? ' (' . (int)$fresh['httpStatus'] . ')' : '';

        $this->stdout('Checked: ' . $status->label() . "$code.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Prints what the last scan found.
     *
     * @return int The exit code.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionReport(): int
    {
        $this->_reportScans();
        $this->_reportVerdicts();
        $this->_reportHosts();

        return ExitCode::OK;
    }

    /**
     * Empties every scan result and leaves the ignores where they are.
     *
     * There is no button for this anywhere in the control panel, deliberately.
     * It is the sort of thing somebody does once after changing what gets
     * scanned, with a backup behind them, and a screen full of report is not
     * where it belongs.
     *
     * @return int The exit code.
     * @throws InvalidConfigException If the queue component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionReset(): int
    {
        // Console::confirm() rather than the controller's own confirm(), which
        // answers yes to everything when the command is run with
        // --interactive=0. A destructive command that says yes on somebody's
        // behalf is exactly what --force is for.
        if (!$this->force && !Console::confirm($this->_resetWarning())) {
            $this->stdout("Left everything as it was.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $removed = LinkAudit::$plugin->getScanService()->resetAll();

        $this->_printTable('Rows removed', [
            'References' => $removed['references'],
            'URLs' => $removed['urls'],
            'Scans' => $removed['scans'],
            'Host records' => $removed['hosts'],
        ]);

        $this->stdout(
            "\nYour ignore decisions were kept, and a URL somebody dismissed comes back\n"
            . "ignored the moment it is found again. Nothing in your content was touched.\n",
        );
        $this->stdout(
            "The next scan rebuilds the lot: run `craft link-audit/scan/all`.\n",
            Console::FG_GREEN,
        );

        return ExitCode::OK;
    }

    /**
     * @inheritdoc
     *
     * @param string $actionID The action being run.
     * @return string[] The options it takes.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'all', 'incremental', 'report' => ['site'],
            'element' => ['elementId', 'site'],
            'prune' => ['days'],
            'recheck-broken' => ['all'],
            'recheck-url' => ['url'],
            'reset' => ['force'],
            default => [],
        });
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Prints a two column table under a heading.
     *
     * @param string $heading The heading.
     * @param array<string, int|string> $rows The rows, as label to value.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _printTable(string $heading, array $rows): void
    {
        $this->stdout("\n$heading\n", Console::FG_YELLOW);

        if ($rows === []) {
            $this->stdout("  Nothing yet.\n");

            return;
        }

        foreach ($rows as $label => $value) {
            $this->stdout(sprintf("  %-26s %s\n", $label, $value));
        }
    }

    /**
     * Opens a scan and says what was queued.
     *
     * A run asked for while another one is going is refused rather than queued,
     * and says which run to wait for: two of them at once fight over the same
     * rows.
     *
     * @param ScanMode $mode What the run is for.
     * @return int The exit code.
     * @throws ConsoleException If the site handle does not belong to a site.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _queue(ScanMode $mode): int
    {
        $siteId = $this->_siteId();
        $service = LinkAudit::$plugin->getScanService();
        $siteIds = $service->siteIds($siteId);

        $since = $mode === ScanMode::Incremental ? $service->lastCompletedScanStart() : null;
        $elements = (int)$service->elementQuery($siteIds, $since)->count();

        try {
            $scan = $service->startScan($mode, $siteId);
        } catch (ScanInProgressException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $this->stdout(sprintf(
            "Queued scan %d (%s): %d elements to read across %d site(s).\n",
            $scan->id,
            $mode->value,
            $elements,
            count($siteIds),
        ), Console::FG_GREEN);
        $this->stdout("Run `craft queue/run` to work through it.\n");

        return ExitCode::OK;
    }

    /**
     * Prints the top offending hosts.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _reportHosts(): void
    {
        $rows = (new Query())
            ->select(['host', 'total' => 'COUNT(*)'])
            ->from([UrlRecord::tableName()])
            ->where(['status' => [UrlStatus::Broken->value, UrlStatus::Unreachable->value]])
            ->groupBy(['host'])
            ->orderBy(['total' => SORT_DESC])
            ->limit(10)
            ->all();

        $table = [];

        foreach ($rows as $row) {
            $table[(string)$row['host']] = (int)$row['total'];
        }

        $this->_printTable('Hosts with the most trouble', $table);
    }

    /**
     * Prints the last few scans.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _reportScans(): void
    {
        $scans = (new Query())
            ->from([ScanRecord::tableName()])
            ->orderBy(['id' => SORT_DESC])
            ->limit(5)
            ->all();

        $this->stdout("\nRecent scans\n", Console::FG_YELLOW);

        if ($scans === []) {
            $this->stdout("  Nothing has run yet.\n");

            return;
        }

        $this->stdout(sprintf(
            "  %-6s %-13s %-11s %-9s %-7s %-8s %-8s %-8s %s\n",
            'ID',
            'MODE',
            'STATUS',
            'ELEMENTS',
            'PAGES',
            'URLS',
            'CHECKED',
            'BROKEN',
            'FINISHED',
        ));

        foreach ($scans as $scan) {
            $this->stdout(sprintf(
                "  %-6d %-13s %-11s %-9d %-7d %-8d %-8d %-8d %s\n",
                (int)$scan['id'],
                (string)$scan['mode'],
                (string)$scan['status'],
                (int)$scan['elementsScanned'],
                (int)$scan['pagesCrawled'],
                (int)$scan['urlsTotal'],
                (int)$scan['urlsChecked'],
                (int)$scan['urlsBroken'],
                $scan['dateFinished'] !== null ? (string)$scan['dateFinished'] : '-',
            ));
        }

        // Said out loud, because CHECKED reads like the whole story and is not:
        // a link to one of your own pages that an element or a route answers for
        // is settled out of the database as it is read, so it never reaches the
        // check phase to be counted there.
        $this->stdout(
            "\n  PAGES is the rendered crawl. CHECKED is what went out over HTTP: a link to\n"
            . "  one of your own pages that an element or a route answers for is settled as it\n"
            . "  is read, so it never reaches that column.\n",
        );
    }

    /**
     * Prints how many URLs hold each verdict, and how many references there are
     * to them.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _reportVerdicts(): void
    {
        $counts = [];

        foreach (UrlStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }

        $rows = (new Query())
            ->select(['status', 'total' => 'COUNT(*)'])
            ->from([UrlRecord::tableName()])
            ->groupBy(['status'])
            ->all();

        foreach ($rows as $row) {
            $counts[(string)$row['status']] = (int)$row['total'];
        }

        $counts['-- references'] = (int)(new Query())->from([ReferenceRecord::tableName()])->count();
        $counts['-- on other sites'] = $this->_urlCount(false);
        $counts['-- on your own sites'] = $this->_urlCount(true);
        $counts['-- waiting to be checked'] = (int)LinkAudit::$plugin->getUrlStore()->pendingQuery()->count();

        $this->_printTable('URLs by verdict', $counts);
    }

    /**
     * What a reset is about to do, said plainly enough to answer no to.
     *
     * Spelled out in full rather than summarised: somebody typing this has a
     * report they are unhappy with, and the one thing they need to be sure of
     * before pressing return is that their content and their ignore decisions
     * are not part of the deal.
     *
     * @return string The warning.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _resetWarning(): string
    {
        return "This deletes every URL, every reference, every scan and every host record\n"
            . "Link Audit holds, and calls off anything that is running.\n\n"
            . "Your ignore decisions are kept, and nothing in your content is touched.\n"
            . "The next scan builds it all again from scratch.\n\n"
            . 'Go ahead?';
    }

    /**
     * The site the command was pointed at.
     *
     * @return int|null The site id, or null for every site.
     * @throws ConsoleException If the handle does not belong to a site. Better
     *                          than quietly covering every site, which is not
     *                          what anybody who typed a handle wanted.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _siteId(): ?int
    {
        if ($this->site === null) {
            return null;
        }

        $site = Craft::$app->getSites()->getSiteByHandle($this->site);

        if ($site === null) {
            throw new ConsoleException("No site with the handle '$this->site'.");
        }

        return (int)$site->id;
    }

    /**
     * How many URL rows belong to this installation, or to somebody else's.
     *
     * The honest half of the CHECKED column: an internal URL an element or a
     * route answers for is settled out of the database as it is stored, so it
     * never goes out on the wire and is never counted as a check.
     *
     * @param bool $internal Whether to count this installation's own URLs.
     * @return int The count.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _urlCount(bool $internal): int
    {
        return (int)(new Query())
            ->from([UrlRecord::tableName()])
            ->where(['isInternal' => $internal])
            ->count();
    }
}
