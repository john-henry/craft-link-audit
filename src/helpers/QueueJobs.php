<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\helpers;

use Craft;
use craft\db\Query;
use craft\helpers\StringHelper;
use craft\queue\Queue as CraftQueue;
use yii\base\InvalidConfigException;

/**
 * Finds the plugin's own jobs in Craft's queue, and takes them out of it.
 *
 * Two callers want the same thing for opposite reasons. Uninstalling has to
 * clear them because their classes are about to stop existing, and the next
 * worker to reach one fatals on a class it cannot load. Cancelling a scan has to
 * clear them because a cancelled run whose jobs are still queued is not
 * cancelled at all: the next worker carries on where it left off and writes to a
 * scan row that says it stopped.
 *
 * Craft's queue holds each job as a serialised object, and a serialised object
 * carries its class name in plain sight, so the rows are read and matched here
 * in PHP. The two obvious alternatives are both worse: asking the database with
 * a LIKE means matching against a column that is a blob on MySQL and a bytea on
 * Postgres, which the two do not answer the same way, and asking Craft for each
 * job's details unserialises every job in the queue to read one string off it.
 *
 * Matching the bytes could in theory take a job of somebody else's that happens
 * to carry one of our class names in a property of its own. Nobody writes that
 * job, and if they did, it is naming work that is being called off either way.
 *
 * A queue driver that is not Craft's own database one is left alone. There is no
 * table to read, and a plugin has no business guessing at whatever has been
 * configured in its place.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class QueueJobs
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var int How many queue rows are read at a time.
     *
     * Small enough that a queue of any size is a bounded amount of memory, big
     * enough that the read is not a round trip per job.
     */
    private const _BATCH_SIZE = 200;

    /**
     * @var string The plugin's own namespace, which is how a queued job is told
     * apart from everybody else's.
     */
    private const _NAMESPACE = 'johnhenry\\linkaudit\\';

    // =========================================================================
    // Static Methods
    // =========================================================================

    /**
     * How many of the plugin's jobs are sitting in the queue.
     *
     * For a caller that has to say out loud what it is about to take out.
     * Reading the queue is the only way to know, and the number is true for the
     * moment it was read: a worker picking one up in the same instant makes it
     * one job out, which is a line of output rather than a wrong decision.
     *
     * @return int How many jobs are ours.
     * @throws InvalidConfigException If the queue component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function count(): int
    {
        $counted = 0;

        foreach (self::_ourRowIds() as $ids) {
            $counted += count($ids);
        }

        return $counted;
    }

    /**
     * Releases every queued job belonging to the plugin.
     *
     * Read a batch at a time rather than all at once. A queue on a busy install
     * is tens of thousands of rows and every one of them carries a serialised
     * object in a blob, so reading the table whole is the whole queue in memory
     * at once, and an uninstall does it inside the transaction Craft opened.
     *
     * @return int How many jobs were released.
     * @throws InvalidConfigException If the queue component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function release(): int
    {
        $queue = Craft::$app->getQueue();

        if (!$queue instanceof CraftQueue) {
            return 0;
        }

        $released = 0;

        foreach (self::_ourRowIds() as $ids) {
            foreach ($ids as $id) {
                $queue->release($id);
                $released++;
            }
        }

        return $released;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * The ids of the queue rows holding one of the plugin's jobs, a batch at a
     * time.
     *
     * @return iterable<int, string[]> Batches of queue row ids.
     * @throws InvalidConfigException If the queue component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private static function _ourRowIds(): iterable
    {
        $queue = Craft::$app->getQueue();

        if (!$queue instanceof CraftQueue) {
            return;
        }

        $query = (new Query())
            ->select(['id', 'job'])
            ->from($queue->tableName);

        foreach ($query->batch(self::_BATCH_SIZE) as $rows) {
            $ids = [];

            foreach ($rows as $row) {
                if (!str_contains(self::_payload($row['job']), self::_NAMESPACE)) {
                    continue;
                }

                $ids[] = (string)$row['id'];
            }

            if ($ids === []) {
                continue;
            }

            yield $ids;
        }
    }

    /**
     * A queued job's stored bytes as a string.
     *
     * The column comes back differently depending on the driver and the client:
     * a string on MySQL, a stream on Postgres, and on some Postgres clients a
     * hexadecimal rendering of the bytes with an `x` in front of it. Craft
     * normalises the same three cases before it unserialises a job.
     *
     * @param mixed $job The stored column value.
     * @return string The bytes, or an empty string when they cannot be read.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private static function _payload(mixed $job): string
    {
        if (is_resource($job)) {
            $job = stream_get_contents($job);
        }

        if (!is_string($job)) {
            return '';
        }

        if (str_starts_with($job, 'x') && StringHelper::isHexadecimal(substr($job, 1))) {
            return (string)hex2bin(substr($job, 1));
        }

        return $job;
    }
}
