<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\controllers;

use Generator;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\LinkAudit;
use yii\base\InvalidConfigException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The Download CSV button behind every list screen.
 *
 * The same question the list is asking, answered as a file instead of a table.
 * The verdict, the filters and the site all come off the request exactly as the
 * table endpoint reads them, so what lands in the download is what was on the
 * screen the reader pressed the button on, and nothing else.
 *
 * The file is streamed rather than built. A CSV assembled in memory and then
 * handed over works beautifully on a development site with forty broken links
 * and falls over on the site that actually needed the export, so the rows come
 * out of a generator and go straight down the wire a batch at a time.
 *
 * The site fence is {@see BaseController::resolveSiteId()}, which is the same
 * clamp the table endpoint uses: a site id this reader may not edit falls back
 * to one they may, rather than being honoured. Reading the report at all needs
 * `viewReports`, which the base controller has already asked for.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class ExportController extends BaseController
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Streams one list screen as a CSV.
     *
     * @return Response The file.
     * @throws ForbiddenHttpException If the user may not read the reports, or
     *                                may not edit any site.
     * @throws InvalidConfigException If a service or the site cannot be
     *                                resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionCsv(): Response
    {
        $status = UrlStatus::tryFrom((string)$this->request->getParam('verdict', '')) ?? UrlStatus::Broken;
        $siteId = $this->resolveSiteId($this->request->getParam('siteId'));
        $filters = $this->reportFilters();
        $export = LinkAudit::$plugin->getExportService();

        $response = $this->response;
        $response->setNoCacheHeaders();
        $response->getHeaders()
            ->set('Content-Type', 'text/csv; charset=UTF-8')
            ->set('Content-Disposition', sprintf('attachment; filename="%s"', $export->filename($status)))
            // The browser is told what this is and asked not to go guessing. A
            // download whose first row came out of somebody's content is not a
            // file worth letting anything sniff a type off.
            ->set('X-Content-Type-Options', 'nosniff');

        // A closure rather than the generator itself, so nothing is read until
        // the response is actually being sent. Yii walks whatever this returns
        // and echoes each piece as it comes, which is the whole point: the
        // export never exists in one place at one time.
        $response->stream = static fn(): Generator => $export->csv($status, [$siteId], $filters);

        return $response;
    }
}
