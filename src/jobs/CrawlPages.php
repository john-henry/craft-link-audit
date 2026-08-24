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
 * @author John Henry Donovan
 * @since 1.0.0
 */
class CrawlPages extends BaseBatchedJob
{
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
    // Protected Methods
    // =========================================================================

    /**
     * Hands the scan on to the check phase, once the last page has been read.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function after(): void
    {
        QueueHelper::push(new CheckUrls(['scanId' => $this->scanId]));
    }

    /**
     * Writes the batch's page count to the scan row, before the runner spawns
     * the next batch.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function afterBatch(): void
    {
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
     * @author John Henry Donovan
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
     * @author John Henry Donovan
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
     * @author John Henry Donovan
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
     * @author John Henry Donovan
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
}
