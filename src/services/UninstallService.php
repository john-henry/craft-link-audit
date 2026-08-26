<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\services;

use Craft;
use craft\base\Component;
use craft\db\Table;
use craft\helpers\Db;
use johnhenry\linkaudit\helpers\QueueJobs;
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
 * The rest are quieter but no tidier: a dashboard tile on every user who added
 * one, and a cached set of counts the navigation badges read.
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
        $widgets = $this->forgetDashboardWidgets();

        LinkAudit::$plugin->getReportService()->invalidateCounts();

        Craft::info(
            "Uninstall cleanup: released $jobs queued job(s) and removed $widgets dashboard widget(s).",
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
     * Releases every queued job belonging to the plugin.
     *
     * The finding of them lives in {@see QueueJobs}, because cancelling a scan
     * wants exactly the same sweep for the opposite reason, and two copies of a
     * byte match against a serialised blob would drift the day a job class moves
     * namespace.
     *
     * @return int How many jobs were released.
     * @throws InvalidConfigException If the queue component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function releaseQueuedJobs(): int
    {
        return QueueJobs::release();
    }
}
