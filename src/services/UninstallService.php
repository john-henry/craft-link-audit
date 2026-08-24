<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\queue\Queue as CraftQueue;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\widgets\BrokenLinksWidget;
use yii\base\InvalidConfigException;

/**
 * Clears everything the plugin wrote that dropping its tables does not reach.
 *
 * Uninstalling runs the install migration backwards and Craft tidies up after
 * it: the plugin row, the migration history and the `plugins.link-audit` branch
 * of the project config all go on their own. Four things do not, because Craft
 * has no way of knowing they were ours.
 *
 * Queued jobs are the one that bites. A scan queued five minutes before the
 * uninstall is a row holding a serialised object whose class is about to stop
 * existing, and the next worker to reach it fatals on a class it cannot load.
 *
 * The rest are quieter but no tidier: a preference on every user who met the
 * tour, a dashboard tile on every user who added one, and a cached set of
 * counts the navigation badges read.
 *
 * Everything here is a plain database write on Craft's own connection, so it
 * takes part in the transaction the uninstall opened: a failure further down
 * puts all of it back.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class UninstallService extends Component
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var string The plugin's own namespace, which is how a queued job is told
     * apart from everybody else's.
     */
    private const _JOB_NAMESPACE = 'johnhenry\\linkaudit\\';

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Clears the lot, in the order that costs least if something further down
     * throws.
     *
     * @return void
     * @throws InvalidConfigException If the queue component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function clearAll(): void
    {
        $jobs = $this->releaseQueuedJobs();
        $preferences = $this->forgetUserPreferences();
        $widgets = $this->forgetDashboardWidgets();

        LinkAudit::$plugin->getReportService()->invalidateCounts();

        Craft::info(
            "Uninstall cleanup: released $jobs queued job(s), cleared the tour preference for "
            . "$preferences user(s) and removed $widgets dashboard widget(s).",
            'link-audit',
        );
    }

    /**
     * Removes the plugin's dashboard tiles.
     *
     * A widget row names the class that renders it, and Craft answers a class it
     * cannot load with its Missing Widget tile: an error box on the dashboard of
     * every user who had added one, saying nothing they can act on.
     *
     * The cost of clearing them is that reinstalling does not bring the tiles
     * back, which is the right way round. A tile somebody has to delete by hand
     * is worse than one they have to add again.
     *
     * @return int How many tiles were removed.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function forgetDashboardWidgets(): int
    {
        return Db::delete(Table::WIDGETS, [
            'type' => BrokenLinksWidget::class,
        ]);
    }

    /**
     * Removes the plugin's keys from every user's preferences.
     *
     * Preferences are one flat map per user, shared by Craft and every plugin on
     * the install, so this cannot drop the row: it reads each one, takes out the
     * keys under the plugin's prefix and writes back what is left.
     *
     * Read into memory and filtered there rather than matched in the database,
     * because the column is JSON on MySQL and JSONB on Postgres and neither
     * answers a LIKE the same way. There is one row per user who has ever set a
     * preference, which on any install is the control panel users rather than
     * the membership.
     *
     * Craft's own users service memoises preferences for the life of the
     * request. Nothing reads them again during an uninstall, but a caller doing
     * this outside one should not trust that memo afterwards.
     *
     * @return int How many users had a preference taken off them.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function forgetUserPreferences(): int
    {
        $schema = Craft::$app->getDb()->getSchema()->getTableSchema(Table::USERPREFERENCES);
        $column = $schema?->getColumn('preferences');

        if ($column === null) {
            return 0;
        }

        $rows = (new Query())
            ->select(['userId', 'preferences'])
            ->from(Table::USERPREFERENCES)
            ->all();

        $cleared = 0;

        foreach ($rows as $row) {
            $preferences = $row['preferences'];

            if (is_string($preferences)) {
                $preferences = Json::decodeIfJson($preferences);
            }

            if (!is_array($preferences)) {
                continue;
            }

            $kept = array_filter(
                $preferences,
                static fn(mixed $key): bool => !str_starts_with((string)$key, TourService::PREFERENCE_PREFIX),
                ARRAY_FILTER_USE_KEY,
            );

            if (count($kept) === count($preferences)) {
                continue;
            }

            Db::update(
                Table::USERPREFERENCES,
                ['preferences' => Db::prepareValueForDb($kept, $column->dbType)],
                ['userId' => $row['userId']],
            );

            $cleared++;
        }

        return $cleared;
    }

    /**
     * Releases every queued job belonging to the plugin.
     *
     * Craft's queue holds each job as a serialised object, and a serialised
     * object carries its class name in plain sight, so the rows are read and
     * matched here in PHP. The two obvious alternatives are both worse: asking
     * the database with a LIKE means matching against a column that is a blob
     * on MySQL and a bytea on Postgres, which the two do not answer the same
     * way, and asking Craft for each job's details unserialises every job in
     * the queue to read one string off it.
     *
     * Matching the bytes could in theory take a job of somebody else's that
     * happens to carry one of our class names in a property of its own. Nobody
     * writes that job, and if they did, it names a class that is about to stop
     * existing.
     *
     * A queue driver that is not Craft's own database one is left alone. There
     * is no table to read, and a plugin has no business guessing at whatever
     * has been configured in its place.
     *
     * @return int How many jobs were released.
     * @throws InvalidConfigException If the queue component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function releaseQueuedJobs(): int
    {
        $queue = Craft::$app->getQueue();

        if (!$queue instanceof CraftQueue) {
            return 0;
        }

        $rows = (new Query())
            ->select(['id', 'job'])
            ->from($queue->tableName)
            ->all();

        $released = 0;

        foreach ($rows as $row) {
            if (!str_contains($this->_payload($row['job']), self::_JOB_NAMESPACE)) {
                continue;
            }

            $queue->release((string)$row['id']);
            $released++;
        }

        return $released;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

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
    private function _payload(mixed $job): string
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
