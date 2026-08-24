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
 * Closes a scan out: recount, tidy away what is no longer referenced, and mark
 * the run finished.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class FinaliseScan extends BaseJob
{
    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @var int The scan to close.
     */
    public int $scanId = 0;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param mixed $queue The queue running the job.
     * @return void
     * @throws Throwable If the tidy up cannot be completed.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function execute($queue): void
    {
        LinkAudit::$plugin->getScanService()->finalise($this->scanId);
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
        return Craft::t('link-audit', 'Finishing the link scan');
    }
}
