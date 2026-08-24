<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\controllers;

use Craft;
use craft\helpers\UrlHelper;
use johnhenry\linkaudit\enums\ScanMode;
use johnhenry\linkaudit\enums\ScanStatus;
use johnhenry\linkaudit\LinkAudit;
use yii\base\InvalidConfigException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The overview screen.
 *
 * One page answering the only question anybody opens this plugin with: how bad
 * is it, and where. The counts across the top, the last run underneath them, and
 * then the two lists that turn a number into an afternoon's work, which are the
 * hosts causing the most trouble and the pages carrying the most of it.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class DashboardController extends BaseController
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * The overview.
     *
     * @return Response The rendered page.
     * @throws ForbiddenHttpException If the user may not read the reports, or
     *                                may not edit any site.
     * @throws InvalidConfigException If a service, the site or the action URL
     *                                cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionIndex(): Response
    {
        $report = LinkAudit::$plugin->getReportService();
        $tour = LinkAudit::$plugin->getTourService();
        $site = $this->requestedSite();
        $siteId = (int)$site->id;
        $latestScan = $report->latestScan();
        $runningScan = $report->runningScan();
        $identity = Craft::$app->getUser()->getIdentity();
        $canRunScans = Craft::$app->getUser()->checkPermission(self::PERMISSION_RUN_SCANS);

        $this->registerJsTranslations();

        return $this->renderTemplate('link-audit/index', [
            'site' => $site,
            'siteId' => $siteId,
            'siteHandle' => $site->handle,
            'sites' => $this->allowedSites(),
            'canRunScans' => $canRunScans,
            'counts' => $report->verdictCounts($siteId),
            'latestScan' => $latestScan,
            'latestScanMode' => $latestScan !== null
                ? (ScanMode::tryFrom((string)$latestScan['mode'])?->label() ?? (string)$latestScan['mode'])
                : null,
            'runningScan' => $runningScan,
            'runningScanStatus' => $runningScan !== null
                ? (ScanStatus::tryFrom((string)$runningScan['status'])?->label() ?? (string)$runningScan['status'])
                : null,
            'topHosts' => $report->topHosts($siteId),
            'topPages' => $report->topPages($siteId),
            // The tour explains what the counts mean, so it runs itself the
            // first time somebody opens this and never again unasked. The link
            // at the bottom of the page is how it comes back.
            //
            // Not on an install that has never been scanned, though. The tiles
            // and the panes are the tour's whole subject and none of them are on
            // the page yet, so it would open on whatever is left, say nothing
            // worth reading, and record itself as seen: the one reader who
            // needed it would never be offered it again.
            'tourAutoStart' => $identity !== null
                && ($latestScan !== null || $runningScan !== null)
                && !$tour->hasSeen($identity),
            'tourSeenUrl' => UrlHelper::actionUrl('link-audit/tour/seen'),
            'tourSteps' => $tour->stepsForOverview($canRunScans),
        ]);
    }
}
