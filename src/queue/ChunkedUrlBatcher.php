<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\queue;

use craft\base\Batchable;
use craft\db\Query;
use Generator;

/**
 * Hands the check phase whole chunks of URLs rather than one URL at a time.
 *
 * `BaseBatchedJob::processItem()` is called once per item, so wrapping the
 * pending URLs in a plain query batcher would mean one request, waited on, per
 * item: no pool, no concurrency, and a scan that takes a day. Each item here is
 * an array of up to {@see self::DEFAULT_CHUNK_SIZE} URL rows, so one call to
 * `processItem()` is one run of the request scheduler.
 *
 * The interesting part is the paging. The pending set shrinks as verdicts are
 * written, so offset paging would let unseen rows slide down into positions the
 * job has already passed, and they would never be checked: the classic moving
 * goalposts bug. So this pages on the id instead, asking each time for the next
 * rows above the highest id it has already handed out. Rows leaving the set
 * behind the cursor cannot affect what comes next, and a URL that was set aside
 * rather than judged, a 429 for instance, keeps its pending status but sits
 * below the cursor and is simply passed over until the next run.
 *
 * Chunks are yielded rather than returned as a list on purpose. The batch runner
 * will stop part way through a batch if it is running short of memory or time,
 * and a generator means the cursor has only moved for the chunks that were
 * actually consumed.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class ChunkedUrlBatcher implements Batchable
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var int How many URLs go into one chunk, and so into one run of the
     * request scheduler.
     */
    public const DEFAULT_CHUNK_SIZE = 25;

    // =========================================================================
    // Private Properties
    // =========================================================================

    /**
     * @var bool Whether the query has run out of rows above the cursor.
     */
    private bool $_exhausted = false;

    /**
     * @var int Where the cursor stood when this batcher was built. The count is
     * taken from here rather than from the live cursor, so that asking twice
     * gives the same answer however far through the batch the caller is.
     */
    private int $_startCursorId = 0;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Constructor.
     *
     * @param Query $query The pending URL query, ordered by id.
     * @param int $chunkSize How many URLs go into one chunk.
     * @param int $cursorId The highest id already handed out, carried over from
     *                      the previous batch.
     * @param int|null $total The chunk count worked out at the start of the run.
     *                        Passed in from the second batch onwards so that the
     *                        total does not shrink underneath the job as it
     *                        works.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function __construct(
        private readonly Query $query,
        private readonly int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        private int $cursorId = 0,
        private ?int $total = null,
    ) {
        $this->_startCursorId = $cursorId;
    }

    /**
     * @inheritdoc
     *
     * @return int How many chunks there are to work through.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function count(): int
    {
        return $this->total ??= (int)ceil($this->_pendingCount() / max(1, $this->chunkSize));
    }

    /**
     * The highest URL id handed out so far.
     *
     * The job reads this after each batch and carries it into the next one, so
     * the chain of spawned jobs works forward through the ids instead of
     * starting over.
     *
     * @return int The cursor.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getCursorId(): int
    {
        return $this->cursorId;
    }

    /**
     * @inheritdoc
     *
     * The offset is deliberately ignored: this batcher pages on the id, for the
     * reason set out on the class.
     *
     * @param int $offset Where the batch runner thinks it is, which is not how
     *                    this batcher pages.
     * @param int $limit How many chunks to hand over.
     * @return Generator<int, array<int, array<string, mixed>>> The chunks.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getSlice(int $offset, int $limit): Generator
    {
        for ($i = 0; $i < $limit; $i++) {
            /** @var array<int, array<string, mixed>> $rows */
            $rows = (clone $this->query)
                ->andWhere(['>', 'id', $this->cursorId])
                ->limit($this->chunkSize)
                ->all();

            if ($rows === []) {
                $this->_exhausted = true;

                return;
            }

            $this->cursorId = max(array_map(static fn(array $row): int => (int)$row['id'], $rows));

            yield $rows;
        }
    }

    /**
     * Whether the query ran out of rows above the cursor.
     *
     * The count is pinned at the start of the run so the total cannot shrink
     * underneath the job, and the pending set very much can: a concurrent scan,
     * an on-save reread, an author ignoring a URL or an element being deleted
     * all take rows out of it. Once that has happened the batch runner would go
     * on spawning a copy of the job for every chunk the pinned total still
     * promises, each one handing back nothing, for ever. This is how the job
     * knows there is nothing left to come back for.
     *
     * @return bool Whether the rows have run out.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function isExhausted(): bool
    {
        return $this->_exhausted;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * How many URLs were waiting when this batcher was built.
     *
     * @return int The count.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _pendingCount(): int
    {
        return (int)(clone $this->query)
            ->andWhere(['>', 'id', $this->_startCursorId])
            ->count();
    }
}
