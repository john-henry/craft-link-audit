<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\jobs;

use Craft;
use craft\base\Batchable;
use craft\db\QueryBatcher;
use craft\helpers\Queue as QueueHelper;
use craft\queue\BaseBatchedJob;
use johnhenry\linkaudit\LinkAudit;
use Throwable;

/**
 * Fetches this installation's own pages and reads the links its templates put
 * there.
 *
 * Only ever queued when the rendered crawl is switched on, and it sits between
 * the content phases and the check phase for a reason: everything a template
 * hard-codes is stored before a single URL is asked about, so the footer link
 * that appears on every page of the site is checked once like any other.
 *
 * Twenty pages to a batch rather than a hundred. The other batched jobs are
 * reading rows out of the database; this one is making a request per item
 * against the same server that is serving the site, so smaller batches keep each
 * run comfortably inside its time to run and give the queue somewhere to breathe
 * between them.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class CrawlPages extends BaseBatchedJob
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var int Seconds added to the reservation on top of the fetching, for
     * the database work around it.
     */
    private const _TTR_MARGIN_SECONDS = 30;

    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public int $batchSize = 20;

    /**
     * @var int The scan this job belongs to.
     */
    public int $scanId = 0;

    /**
     * @var int[] The sites to crawl.
     */
    public array $siteIds = [];

    // =========================================================================
    // Private Properties
    // =========================================================================

    /**
     * @var int Pages read in the current batch, flushed to the scan row once
     * rather than once per page.
     */
    private int $_crawled = 0;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * A batch is twenty pages fetched one after another, each bounded by the
     * request timeout with the politeness delay between them, which comes to
     * more than the five minutes Craft reserves a job for by default. A job
     * that outlives its reservation is not lost, it is handed to the next
     * worker and begun again from the top: a slow site had the same twenty
     * pages crawled over and over and the scan never moved on.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function init(): void
    {
        // Set before the parent, which only fills it in when it is still null.
        $this->ttr ??= $this->_worstCaseSeconds();

        parent::init();
    }

    // =========================================================================
    // Protected Methods
    // =========================================================================

    /**
     * Hands the scan on to the check phase, once the last page has been read.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function after(): void
    {
        QueueHelper::push(new CheckUrls(['scanId' => $this->scanId]));
    }

    /**
     * Holds count-cache invalidation back for the batch, so it happens once
     * at the end rather than once per URL.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0-beta.8
     */
    protected function beforeBatch(): void
    {
        LinkAudit::$plugin->getReportService()->holdCountInvalidation();
    }

    /**
     * Writes the batch's page count to the scan row, before the runner spawns
     * the next batch.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function afterBatch(): void
    {
        LinkAudit::$plugin->getReportService()->releaseCountInvalidation();

        if ($this->_crawled === 0) {
            return;
        }

        LinkAudit::$plugin->getScanService()->recordPagesCrawled($this->scanId, $this->_crawled);
        $this->_crawled = 0;
    }

    /**
     * Says in the log how much of the site the page cap left out, before a page
     * is fetched.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function before(): void
    {
        LinkAudit::$plugin->getPageCrawler()->reportCappedPages($this->siteIds);
    }

    /**
     * @inheritdoc
     *
     * @return string|null The description shown in the queue.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('link-audit', 'Reading the links your templates put on the page');
    }

    /**
     * @inheritdoc
     *
     * @return Batchable The pages to fetch.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function loadData(): Batchable
    {
        return new QueryBatcher(LinkAudit::$plugin->getPageCrawler()->pageQuery($this->siteIds));
    }

    /**
     * @inheritdoc
     *
     * @param mixed $item One row from the page query.
     * @throws Throwable If the page's reference rows cannot be rebuilt.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function processItem(mixed $item): void
    {
        if (!is_array($item)) {
            return;
        }

        if (LinkAudit::$plugin->getPageCrawler()->crawlPage($item, $this->scanId)) {
            $this->_crawled++;
        }
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * The longest a batch of page fetches can take, in seconds.
     *
     * Read off the settings the fetching actually uses, so it follows them
     * rather than restating a number beside them. Never below the queue's own
     * setting: an install that raised it did so for a reason.
     *
     * @return int Seconds.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _worstCaseSeconds(): int
    {
        $settings = LinkAudit::$plugin->getSettings();

        $perPage = max(1, $settings->timeout)
            + (int)ceil(max(0, $settings->minHostDelayMs) / 1000);

        $seconds = max(1, $this->batchSize) * $perPage + self::_TTR_MARGIN_SECONDS;

        return max($seconds, (int)Craft::$app->getQueue()->ttr);
    }
}
