<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\jobs;

use Craft;
use craft\queue\BaseJob;
use johnhenry\linkaudit\LinkAudit;
use Throwable;

/**
 * Reads the links held on navigation nodes.
 *
 * Its own step rather than a tail on the last extract batch, so that a scan's
 * progress says what it is doing: a navigation with a broken link in it is one
 * of the most visible faults a site can have, and it is worth seeing that phase
 * go by.
 *
 * Small enough to be a plain job. Even a large site has hundreds of nodes, not
 * hundreds of thousands, and each one is a stored URL rather than a field walk.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class ExtractNavigation extends BaseJob
{
    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @var int The scan this job belongs to.
     */
    public int $scanId = 0;

    /**
     * @var int[] The sites to read.
     */
    public array $siteIds = [];

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param mixed $queue The queue running the job.
     * @return void
     * @throws Throwable If the reference rows cannot be rebuilt.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function execute($queue): void
    {
        $service = LinkAudit::$plugin->getScanService();

        $service->extractNavigation($this->siteIds, $this->scanId);
        $service->continueAfterExtraction($this->scanId, $this->siteIds);
    }

    // =========================================================================
    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return string|null The description shown in the queue.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('link-audit', 'Reading the links out of your navigations');
    }
}
