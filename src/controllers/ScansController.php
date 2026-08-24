<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\controllers;

use Craft;
use johnhenry\linkaudit\enums\ScanMode;
use johnhenry\linkaudit\enums\ScanStatus;
use johnhenry\linkaudit\exceptions\ScanInProgressException;
use johnhenry\linkaudit\LinkAudit;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Starting a scan from the control panel, and watching one that is running.
 *
 * Named for the plural so its controller id is `scans`, which keeps it clear of
 * the console `scan` commands: the two do the same work but one of them is
 * driven by a person who wants a page back afterwards.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class ScansController extends BaseController
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Calls off the run that is going.
     *
     * Whichever site the reader is looking at, this stops the run itself: a scan
     * is one job chain across whatever sites it was started for, and there is no
     * halfway house where it carries on for the other sites. `runScans` is the
     * gate for that reason, the same permission that started it.
     *
     * @return Response A redirect back to wherever the button was.
     * @throws BadRequestHttpException If the request is not a POST.
     * @throws ForbiddenHttpException If the user may not run scans.
     * @throws InvalidConfigException If the queue component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionCancel(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_RUN_SCANS);

        $scan = LinkAudit::$plugin->getScanService()->cancelScan();

        if ($scan === null) {
            $this->setFailFlash(Craft::t('link-audit', 'Nothing was running, so there was nothing to stop.'));

            return $this->redirectToPostedUrl();
        }

        $this->setSuccessFlash(Craft::t(
            'link-audit',
            'The scan has been stopped. Everything it checked before it stopped is on the report as normal, and the next scan reads the rest.',
        ));

        return $this->redirectToPostedUrl();
    }

    /**
     * Queues a scan of the site being viewed.
     *
     * One site rather than the lot. The button sits under a site switcher, so
     * the run it starts is the one the reader is looking at, and a reader who
     * may only edit one site cannot start work across the others from here. Use
     * `craft link-audit/scan/all` for every site at once.
     *
     * A second press while a run is going is refused rather than queued, and
     * says so in a flash message: two runs at once fight over the same rows.
     *
     * @return Response A redirect back to wherever the button was.
     * @throws BadRequestHttpException If the request is not a POST.
     * @throws ForbiddenHttpException If the user may not run scans, or may not
     *                                edit any site.
     * @throws InvalidConfigException If the site cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionStart(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_RUN_SCANS);

        $mode = $this->request->getBodyParam('mode') === ScanMode::Incremental->value
            ? ScanMode::Incremental
            : ScanMode::Full;
        $siteId = $this->resolveSiteId($this->request->getBodyParam('siteId'));

        try {
            LinkAudit::$plugin->getScanService()->startScan($mode, $siteId);
        } catch (ScanInProgressException) {
            $this->setFailFlash(Craft::t(
                'link-audit',
                'A scan is already running. Wait for it to finish before starting another.',
            ));

            return $this->redirectToPostedUrl();
        }

        $this->setSuccessFlash(Craft::t(
            'link-audit',
            'The scan has started. The counts on this page will fill in as it works through your content.',
        ));

        return $this->redirectToPostedUrl();
    }

    /**
     * What the scan that is running has got through so far.
     *
     * Read by the overview while a scan is going. Answers with the running scan
     * when there is one and nothing when there is not, so the page knows to stop
     * asking.
     *
     * @return Response The progress, as JSON.
     * @throws ForbiddenHttpException If the user may not read the reports.
     * @throws BadRequestHttpException If the request does not accept JSON.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionStatus(): Response
    {
        $this->requireAcceptsJson();

        $scan = LinkAudit::$plugin->getReportService()->runningScan();

        if ($scan === null) {
            return $this->asJson(['success' => true, 'scan' => null]);
        }

        $status = ScanStatus::tryFrom((string)$scan['status']);

        return $this->asJson([
            'success' => true,
            'scan' => [
                'id' => (int)$scan['id'],
                'status' => $status?->value ?? (string)$scan['status'],
                'statusLabel' => $status?->label() ?? (string)$scan['status'],
                'mode' => (string)$scan['mode'],
                'elementsScanned' => (int)$scan['elementsScanned'],
                'urlsTotal' => (int)$scan['urlsTotal'],
                'urlsChecked' => (int)$scan['urlsChecked'],
                'urlsBroken' => (int)$scan['urlsBroken'],
            ],
        ]);
    }
}
