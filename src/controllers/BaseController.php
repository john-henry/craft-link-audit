<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\controllers;

use Craft;
use craft\helpers\Cp;
use craft\models\Site;
use craft\web\Controller;
use johnhenry\linkaudit\LinkAudit;
use yii\base\InvalidConfigException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * Shared structure for every control panel screen the plugin has.
 *
 * Two things live here rather than in each controller. The permission handles,
 * because a handle is a contract string used by the registration, the nav item
 * and the gate alike, and a bare literal in three places drifts silently: a typo
 * still passes for an admin, who holds every permission, and refuses everybody
 * else. And the site fence, because a report is only ever read for one site at a
 * time and every screen has to arrive at that site the same way.
 *
 * Reading a report needs `viewReports`, so that is checked once here rather than
 * at the top of a dozen actions. Anything that changes something asks for more
 * on top of it.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
abstract class BaseController extends Controller
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var string Dismiss a URL, or bring a dismissed one back.
     *
     * A different decision from running a scan: an ignore changes what every
     * other editor sees from then on.
     */
    public const PERMISSION_MANAGE_IGNORES = 'link-audit:manageIgnores';

    /**
     * @var string Queue a scan, or ask for one URL to be checked again.
     */
    public const PERMISSION_RUN_SCANS = 'link-audit:runScans';

    /**
     * @var string Read the reports: the overview, the lists and the URL detail.
     */
    public const PERMISSION_VIEW_REPORTS = 'link-audit:viewReports';

    // =========================================================================
    // Protected Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = false;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param mixed $action The action about to run.
     * @return bool Whether the action may run.
     * @throws ForbiddenHttpException If the user may not read the reports.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(self::PERMISSION_VIEW_REPORTS);

        return true;
    }

    // =========================================================================
    // Protected Methods
    // =========================================================================

    /**
     * The ids of the sites this user may read a report for.
     *
     * @return int[] The site ids.
     * @throws ForbiddenHttpException If the user may not edit any site.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function allowedSiteIds(): array
    {
        return array_map(
            static fn(Site $site): int => (int)$site->id,
            $this->allowedSites(),
        );
    }

    /**
     * The sites this user may read a report for.
     *
     * A report is content, so the fence is the same one Craft puts around
     * content: the sites the user may edit. `viewReports` says whether somebody
     * may look at the reports at all, it does not say which sites' content they
     * are entitled to see.
     *
     * @return Site[] The sites.
     * @throws ForbiddenHttpException If the user may not edit any site, since a
     *                                report scoped to no sites is not a page
     *                                worth rendering.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function allowedSites(): array
    {
        $sites = array_values(Craft::$app->getSites()->getEditableSites());

        if ($sites === []) {
            throw new ForbiddenHttpException('You are not allowed to read a report for any site.');
        }

        return $sites;
    }

    /**
     * The list filters the request is asking for.
     *
     * Shared by the screens, the table endpoint behind them and the export, so
     * a download cannot honour a different set of filters from the list it was
     * asked for. Read with `getParam` rather than off the query string, so the
     * same method serves a page (where they are bookmarkable query parameters)
     * and an endpoint (where they are appended to its URL).
     *
     * The free text search is deliberately not here. It lives inside the table
     * component rather than in the filter bar, so it never reaches a page's own
     * URL; the one caller that has it adds it itself.
     *
     * @return array<string, string> The filters.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function reportFilters(): array
    {
        $filters = [];

        foreach (['host', 'elementType', 'source', 'permanent'] as $key) {
            $filters[$key] = trim((string)($this->request->getParam($key) ?? ''));
        }

        return $filters;
    }

    /**
     * The site the control panel is currently working with.
     *
     * Reads Craft's own `site` query parameter through {@see Cp::requestedSite()},
     * which validates the handle against the user's editable sites. Handles
     * rather than ids, because a site id is a per install auto increment: the
     * same id means a different site on another environment, and Craft's own
     * chrome reads the handle.
     *
     * @return Site The site.
     * @throws ForbiddenHttpException If the user may not edit any site.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function requestedSite(): Site
    {
        return Cp::requestedSite() ?? $this->allowedSites()[0];
    }

    /**
     * The id of the site the control panel is currently working with.
     *
     * @return int The site id.
     * @throws ForbiddenHttpException If the user may not edit any site.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function requestedSiteId(): int
    {
        return (int)$this->requestedSite()->id;
    }

    /**
     * Refuses a URL nothing on this user's own sites points at.
     *
     * A URL row is global, so the hash in a request is not fenced by anything on
     * its own: the fence is the references, which is where a site lives. Without
     * this, somebody entitled to one site could read another site's verdicts, or
     * act on them, by posting a hash they got hold of.
     *
     * The refusal is the same 404, carrying the same sentence, that an
     * unrecognised hash gets. Anything else would answer the question the fence
     * exists to refuse, which is whether that URL is on this installation at
     * all.
     *
     * An admin, and anybody else entitled to every site, is unaffected: their
     * allowed sites are all of them.
     *
     * @param int $urlId The URL row.
     * @return void
     * @throws ForbiddenHttpException If the user may not edit any site.
     * @throws NotFoundHttpException If nothing on the user's sites points at
     *                               that URL.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function requireReadableUrl(int $urlId): void
    {
        $referenceCount = LinkAudit::$plugin->getReportService()
            ->referenceCount($urlId, $this->allowedSiteIds());

        if ($referenceCount === 0) {
            throw new NotFoundHttpException(
                Craft::t('link-audit', 'No URL has been seen with that address.'),
            );
        }
    }

    /**
     * Clamps a requested site id to one this user is allowed to read.
     *
     * For the callers that genuinely hold an integer: the JSON endpoints and the
     * action posts, whose URLs are rebuilt on every page load and never
     * bookmarked. A navigable page uses {@see self::requestedSiteId()} and the
     * `site` handle instead.
     *
     * A site id the user may not edit falls back to the requested site rather
     * than being honoured, so a hand typed id cannot reach another site's
     * references.
     *
     * @param mixed $requested The site id from the request.
     * @return int The site id to work with.
     * @throws ForbiddenHttpException If the user may not edit any site.
     * @throws InvalidConfigException If the site cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function resolveSiteId(mixed $requested): int
    {
        $requestedId = (int)$requested;

        if ($requestedId !== 0 && in_array($requestedId, $this->allowedSiteIds(), true)) {
            return $requestedId;
        }

        return $this->requestedSiteId();
    }
}
