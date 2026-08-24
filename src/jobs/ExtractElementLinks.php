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
 * Rereads one element's links, because somebody just saved it.
 *
 * Deliberately not a batched job and deliberately not a scan. One element is a
 * field walk and a handful of small writes, so it wants the lightest thing that
 * will carry it: no scan row, no counters, and nothing pushed behind it.
 *
 * What it does not do is check the URLs it finds. A link an author has just
 * pasted in lands as pending and waits for the next check phase, whether that is
 * the nightly cron or somebody pressing the button. That keeps a save cheap and
 * predictable: nobody wants an editor's save to turn into twenty outbound
 * requests, and the verdict is worth more once the host has stopped being
 * hammered by the save that caused it.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class ExtractElementLinks extends BaseJob
{
    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @var int The element to reread.
     */
    public int $elementId = 0;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param mixed $queue The queue running the job.
     * @return void
     * @throws Throwable If the element's reference rows cannot be rebuilt.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function execute($queue): void
    {
        LinkAudit::$plugin->getScanService()->refreshElement($this->elementId);
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
        return Craft::t('link-audit', 'Rereading the links on something you just saved');
    }
}
