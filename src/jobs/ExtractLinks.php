<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\jobs;

use Craft;
use craft\base\Batchable;
use craft\db\QueryBatcher;
use craft\helpers\DateTimeHelper;
use craft\helpers\Queue as QueueHelper;
use craft\queue\BaseBatchedJob;
use DateTimeInterface;
use johnhenry\linkaudit\enums\ScanStatus;
use johnhenry\linkaudit\LinkAudit;
use Throwable;

/**
 * Reads the links out of every element a scan covers, a batch at a time.
 *
 * One job per batch rather than one job per element: on a site with ten thousand
 * pages the second shape means ten thousand queue rows, and the queue spends
 * longer bookkeeping than it does working. Craft's batch runner watches the
 * memory ceiling and the time to run and spawns the next batch itself.
 *
 * The last batch pushes the navigation phase, which pushes the rendered crawl
 * when the settings ask for one and the check phase when they do not, and the
 * check phase pushes the finish. Chaining through {@see self::after()} is what
 * keeps each phase inside its own time to run.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class ExtractLinks extends BaseBatchedJob
{
    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public int $batchSize = 100;

    /**
     * @var int[]|null Only these elements, for a rescan of a known set. Null
     * reads everything the scan covers.
     */
    public ?array $elementIds = null;

    /**
     * @var int The scan this job belongs to.
     */
    public int $scanId = 0;

    /**
     * @var string|null Only elements edited since this moment, as an ISO 8601
     * string so the job stays serialisable. Null reads everything.
     */
    public ?string $since = null;

    /**
     * @var int[] The sites to read.
     */
    public array $siteIds = [];

    // =========================================================================
    // Private Properties
    // =========================================================================

    /**
     * @var int Elements read in the current batch, flushed to the scan row once
     * rather than once per element.
     */
    private int $_scanned = 0;

    // =========================================================================
    // Protected Methods
    // =========================================================================

    /**
     * Hands the scan on to the navigation phase, once the last element of the
     * last batch has been read.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function after(): void
    {
        QueueHelper::push(new ExtractNavigation([
            'scanId' => $this->scanId,
            'siteIds' => $this->siteIds,
        ]));
    }

    /**
     * Writes the batch's element count to the scan row, before the runner
     * spawns the next batch.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function afterBatch(): void
    {
        if ($this->_scanned === 0) {
            return;
        }

        LinkAudit::$plugin->getScanService()->recordElementsScanned($this->scanId, $this->_scanned);
        $this->_scanned = 0;
    }

    /**
     * Moves the scan out of the queue and into the extract phase.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function before(): void
    {
        LinkAudit::$plugin->getScanService()->markStatus($this->scanId, ScanStatus::Extracting);
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
        return Craft::t('link-audit', 'Reading the links out of your content');
    }

    /**
     * @inheritdoc
     *
     * @return Batchable The elements to read.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function loadData(): Batchable
    {
        return new QueryBatcher(LinkAudit::$plugin->getScanService()->elementQuery(
            $this->siteIds,
            $this->_since(),
            $this->elementIds,
        ));
    }

    /**
     * @inheritdoc
     *
     * @param mixed $item One row from the element query.
     * @throws Throwable If the element's reference rows cannot be rebuilt.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function processItem(mixed $item): void
    {
        if (!is_array($item)) {
            return;
        }

        $scanned = LinkAudit::$plugin->getScanService()->extractElement(
            elementId: (int)$item['elementId'],
            elementType: ((string)$item['elementType']) ?: null,
            siteId: (int)$item['siteId'],
            scanId: $this->scanId,
            uri: $item['uri'] !== null ? (string)$item['uri'] : null,
        );

        if ($scanned !== null) {
            $this->_scanned++;
        }
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * The incremental cut-off as a date.
     *
     * @return DateTimeInterface|null The moment, or null for a full read.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _since(): ?DateTimeInterface
    {
        if ($this->since === null) {
            return null;
        }

        $date = DateTimeHelper::toDateTime($this->since);

        return $date !== false ? $date : null;
    }
}
