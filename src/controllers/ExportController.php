<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\controllers;

use Craft;
use Generator;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\services\ExportService;
use Throwable;
use yii\base\InvalidConfigException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The Download CSV button behind every list screen.
 *
 * The same question the list is asking, answered as a file instead of a table.
 * The verdict, the filters, the search and the site all come off the request
 * exactly as the table endpoint reads them, so what lands in the download is
 * what was on the screen the reader pressed the button on, and nothing else.
 * The search is the odd one out only in how it gets here: it belongs to the
 * table component rather than to the filter bar, so the screen's own JavaScript
 * puts it on the button's link as it is typed.
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
        // The free text search is not one of the bookmarkable filters: it lives
        // inside the table component, and the button's link is rewritten with it
        // by the screen's own JavaScript as it is typed. Read the same way the
        // table endpoint reads it, so a download and the rows it was pressed
        // over cannot disagree.
        $filters['search'] = trim((string)($this->request->getParam('search') ?? ''));
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
        $response->stream = static fn(): Generator => self::_stream($export, $status, $siteId, $filters);

        return $response;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * The file, in chunks, with nothing allowed out of it but chunks.
     *
     * By the time this runs the response has gone: the headers said 200 and
     * `text/csv`, and the browser is already writing what it has been handed to
     * a file. An exception escaping here would put Craft's error page down the
     * same pipe, so the reader would open a spreadsheet and find a page of HTML
     * at the bottom of it, under a name and a status that both say the download
     * worked.
     *
     * A truncated file is the better failure. It is obvious, it says nothing
     * untrue, and the real exception goes in the log where somebody can act on
     * it.
     *
     * @param ExportService $export The export service.
     * @param UrlStatus $status The verdict being exported.
     * @param int $siteId The site to read references on.
     * @param array<string, mixed> $filters The filters from the request.
     * @return Generator<int, string> The file, in chunks.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private static function _stream(
        ExportService $export,
        UrlStatus $status,
        int $siteId,
        array $filters,
    ): Generator {
        try {
            yield from $export->csv($status, [$siteId], $filters);
        } catch (Throwable $e) {
            Craft::error('Could not finish the CSV export: ' . $e->getMessage(), 'link-audit');
        }
    }
}
