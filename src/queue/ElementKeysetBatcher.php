<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\queue;

use craft\base\Batchable;
use craft\db\Query;
use Generator;

/**
 * Hands the extract phase the elements a scan covers, paging on the site and
 * element id rather than on an offset.
 *
 * Each batch of a batched job re-runs the query. With offset paging, an element
 * deleted, disabled or archived behind the cursor shifts every later row back
 * one place, and the first element of the next batch is never read. Paging from
 * the last (siteId, elementId) handed out is immune to rows leaving the set.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0-beta.8
 */
class ElementKeysetBatcher implements Batchable
{
    // =========================================================================
    // Private Properties
    // =========================================================================

    /**
     * @var bool Whether the query has run out of rows after the cursor.
     */
    private bool $_exhausted = false;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Constructor.
     *
     * @param Query $query The element query, ordered by `es.siteId` then
     *                     `es.elementId`.
     * @param int $cursorSiteId The site of the last row handed out.
     * @param int $cursorElementId The element of the last row handed out.
     * @param int|null $total The row count worked out at the start of the run,
     *                        so it doesn't shrink underneath the job.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0-beta.8
     */
    public function __construct(
        private readonly Query $query,
        private int $cursorSiteId = 0,
        private int $cursorElementId = 0,
        private ?int $total = null,
    ) {
    }

    /**
     * @inheritdoc
     *
     * @return int How many rows there are to work through.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0-beta.8
     */
    public function count(): int
    {
        return $this->total ??= (int)$this->_afterCursor(clone $this->query)->count();
    }

    /**
     * The site and element of the last row handed out.
     *
     * @return array{0: int, 1: int} The cursor.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0-beta.8
     */
    public function getCursor(): array
    {
        return [$this->cursorSiteId, $this->cursorElementId];
    }

    /**
     * @inheritdoc
     *
     * The offset is ignored: this batcher pages on the cursor.
     *
     * @param int $offset Where the batch runner thinks it is.
     * @param int $limit How many rows to hand over.
     * @return Generator<int, array<string, mixed>> The rows.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0-beta.8
     */
    public function getSlice(int $offset, int $limit): Generator
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->_afterCursor(clone $this->query)
            ->limit($limit)
            ->all();

        if (count($rows) < $limit) {
            $this->_exhausted = true;
        }

        foreach ($rows as $row) {
            // Moved before the yield, so a runner that stops part way through
            // has a cursor that covers exactly the rows it processed.
            $this->cursorSiteId = (int)$row['siteId'];
            $this->cursorElementId = (int)$row['elementId'];

            yield $row;
        }
    }

    /**
     * Whether the query ran out of rows after the cursor.
     *
     * @return bool Whether the rows have run out.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0-beta.8
     */
    public function isExhausted(): bool
    {
        return $this->_exhausted;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Narrows a query to the rows after the cursor.
     *
     * @param Query $query The query.
     * @return Query The narrowed query.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0-beta.8
     */
    private function _afterCursor(Query $query): Query
    {
        return $query->andWhere([
            'or',
            ['>', 'es.siteId', $this->cursorSiteId],
            ['and', ['es.siteId' => $this->cursorSiteId], ['>', 'es.elementId', $this->cursorElementId]],
        ]);
    }
}
