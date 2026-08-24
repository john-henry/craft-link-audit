<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\jobs;

use Craft;
use craft\base\Batchable;
use craft\helpers\Queue as QueueHelper;
use craft\queue\BaseBatchedJob;
use Exception;
use johnhenry\linkaudit\enums\ScanStatus;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\queue\ChunkedUrlBatcher;

/**
 * Asks every URL waiting to be checked whether it still works.
 *
 * One item is a chunk of URLs rather than a single one, so each call to
 * {@see self::processItem()} is one run of the request scheduler with its pool,
 * its per-host limits and its throttle. Checking URLs one at a time behind a
 * batched job looks tidy and is the single easiest way to build a link checker
 * that never finishes.
 *
 * Which URLs get asked is the time to live cache doing its work: a link checked
 * and found well last week is not in the pending set at all, so a rescan pays
 * only for what has gone stale.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class CheckUrls extends BaseBatchedJob
{
    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Chunks, not URLs: four chunks of twenty five is a hundred URLs per job
     * run, which is a comfortable amount of work to fit inside one time to run.
     */
    public int $batchSize = 4;

    /**
     * @var int How many URLs go into one chunk, and so into one run of the
     * request scheduler.
     */
    public int $chunkSize = ChunkedUrlBatcher::DEFAULT_CHUNK_SIZE;

    /**
     * @var int The highest URL id already offered, carried from batch to batch
     * so the chain works forward rather than starting over.
     */
    public int $cursorId = 0;

    /**
     * @var int The scan this job belongs to.
     */
    public int $scanId = 0;

    /**
     * @var int|null The chunk count worked out when the run started. Carried
     * from batch to batch so the total does not shrink underneath the job as
     * verdicts are written.
     */
    public ?int $totalChunks = null;

    // =========================================================================
    // Protected Methods
    // =========================================================================

    /**
     * Hands the scan on to the finish phase, once the last chunk has been
     * checked.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function after(): void
    {
        QueueHelper::push(new FinaliseScan(['scanId' => $this->scanId]));
    }

    /**
     * Carries the batcher's cursor onto the job, so that the copy spawned for
     * the next batch starts where this one stopped, and brings the run to an end
     * once the URLs have run out.
     *
     * The batch runner spawns another copy of the job whenever the offset has
     * not reached the total, and the total is pinned at the start so that it
     * cannot shrink underneath a run in progress. The pending set can and does
     * shrink, though: another scan, an on-save reread, an author ignoring a URL,
     * an element being deleted. When it has, the batcher hands back nothing and
     * every further copy would do the same, spawn another, and leave the scan
     * stuck in the check phase for ever. Moving the offset up to the total is
     * what tells the runner the work is done, so it calls
     * {@see self::after()} and the scan goes on to be finished.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function afterBatch(): void
    {
        $batcher = $this->data();
        assert($batcher instanceof ChunkedUrlBatcher);

        $this->cursorId = $batcher->getCursorId();

        if ($batcher->isExhausted()) {
            $this->itemOffset = max($this->itemOffset, $this->totalItems());
        }
    }

    /**
     * Moves the scan into the check phase and pins the chunk count.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function before(): void
    {
        LinkAudit::$plugin->getScanService()->markStatus($this->scanId, ScanStatus::Checking);

        $this->totalChunks = $this->totalItems();
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
        return Craft::t('link-audit', 'Checking the links found in your content');
    }

    /**
     * @inheritdoc
     *
     * @return Batchable The chunks of URLs to check.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function loadData(): Batchable
    {
        return new ChunkedUrlBatcher(
            query: LinkAudit::$plugin->getUrlStore()->pendingQuery(),
            chunkSize: max(1, $this->chunkSize),
            cursorId: $this->cursorId,
            total: $this->totalChunks,
        );
    }

    /**
     * @inheritdoc
     *
     * @param mixed $item One chunk of URL rows.
     * @throws Exception If a time to live setting cannot be turned into an
     *                   interval.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function processItem(mixed $item): void
    {
        if (!is_array($item) || $item === []) {
            return;
        }

        LinkAudit::$plugin->getScanService()->checkChunk($item, $this->scanId);
    }
}
