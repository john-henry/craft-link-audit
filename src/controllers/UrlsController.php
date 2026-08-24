<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\controllers;

use Craft;
use craft\helpers\AdminTable;
use craft\helpers\DateTimeHelper;
use craft\helpers\UrlHelper;
use craft\i18n\Locale;
use DateTime;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\helpers\UrlSafety;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\ExtractedLink;
use johnhenry\linkaudit\models\Verdict;
use johnhenry\linkaudit\services\ReportService;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The list screens, and the detail page behind each row.
 *
 * One verdict per screen, and one endpoint feeding all of them. The screens
 * differ in the verdict they fix and the sentence at the top explaining what
 * that verdict means; everything else, including the paging, the sorting and the
 * filters, is the same question with a different value in it.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class UrlsController extends BaseController
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var int How many rows a page of a list holds.
     *
     * Sent to the table as `perPage` and used as the default here, so the server
     * pages by the same size the pagination is drawn for.
     */
    public const PER_PAGE = 50;

    /**
     * @var string Matches the documentation link a cURL failure carries on the
     * end of it, whichever of the two hosts libcurl's own text is using.
     */
    private const _CURL_DOCS_PATTERN = '/\s*\(see https?:\/\/curl\.(?:haxx\.)?se\/\S*\)\s*$/i';

    /**
     * @var string Matches the transport prefix a cURL failure is wrapped in.
     */
    private const _CURL_PREFIX_PATTERN = '/^cURL error \d+:\s*/i';

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * The unverifiable list: URLs that refused an automated request.
     *
     * @return Response The rendered page.
     * @throws ForbiddenHttpException If the user may not read the reports.
     * @throws InvalidConfigException If a service or the site cannot be
     *                                resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionBlocked(): Response
    {
        return $this->_list(UrlStatus::Blocked, 'link-audit/blocked');
    }

    /**
     * The broken list.
     *
     * @return Response The rendered page.
     * @throws ForbiddenHttpException If the user may not read the reports.
     * @throws InvalidConfigException If a service or the site cannot be
     *                                resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionBroken(): Response
    {
        return $this->_list(UrlStatus::Broken, 'link-audit/broken');
    }

    /**
     * One URL: its verdict, and everywhere it appears.
     *
     * @return Response The rendered page.
     * @throws ForbiddenHttpException If the user may not read the reports.
     * @throws InvalidConfigException If a service or the site cannot be
     *                                resolved.
     * @throws NotFoundHttpException If no URL has been seen with that hash, or
     *                               nothing on the user's sites points at it.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionDetail(): Response
    {
        $report = LinkAudit::$plugin->getReportService();
        $hash = trim((string)$this->request->getParam('hash', ''));
        $url = $hash !== '' ? $report->urlByHash($hash) : null;

        if ($url === null) {
            // A hash nobody recognises is a page that does not exist, not an
            // error: a URL is pruned once nothing points at it any more, so an
            // old bookmark lands here as a matter of course.
            throw new NotFoundHttpException(Craft::t('link-audit', 'No URL has been seen with that address.'));
        }

        $this->requireReadableUrl((int)$url['id']);

        $site = $this->requestedSite();
        $siteIds = $this->allowedSiteIds();
        $status = UrlStatus::tryFrom((string)$url['status']) ?? UrlStatus::Pending;
        $identity = Craft::$app->getUser()->getIdentity();
        $referenceCount = $report->referenceCount((int)$url['id'], $siteIds);

        foreach (['dateFirstSeen', 'dateLastChecked', 'dateLastOk', 'dateLastBroken'] as $column) {
            $url[$column] = $this->_toDate($url[$column]);
        }

        // Cleaned for the page, not for the row: what is stored stays exactly as
        // the checker recorded it, and the untouched string is handed to the
        // template alongside so the technical detail can still carry it.
        $rawMessage = trim((string)($url['message'] ?? ''));
        $url['message'] = $this->_plainMessage($rawMessage);

        return $this->renderTemplate('link-audit/url', [
            // Set here rather than in the template: which list this URL belongs
            // to depends on the verdict it is carrying.
            'selectedSubnavItem' => $this->_subnavItemFor($status),
            'canRunScans' => Craft::$app->getUser()->checkPermission(self::PERMISSION_RUN_SCANS),
            'canManageIgnores' => Craft::$app->getUser()->checkPermission(self::PERMISSION_MANAGE_IGNORES),
            'isIgnored' => $status === UrlStatus::Ignored,
            'isOpenable' => $this->_isOpenable($url),
            'site' => $site,
            'siteHandle' => $site->handle,
            'sites' => $this->allowedSites(),
            'url' => $url,
            'statusLabel' => $status->label(),
            'statusColour' => $status->colour(),
            'reasonLabel' => Verdict::reasonLabel($url['reason'] !== null ? (string)$url['reason'] : null),
            'codeTitle' => Verdict::httpStatusLabel($url['httpStatus'] !== null ? (int)$url['httpStatus'] : null),
            'redirectCodeTitle' => Verdict::httpStatusLabel($url['redirectStatus'] !== null ? (int)$url['redirectStatus'] : null),
            'rawMessage' => $rawMessage !== '' && $rawMessage !== $url['message'] ? $rawMessage : null,
            'references' => $report->references((int)$url['id'], $siteIds, $identity),
            'referenceCount' => $referenceCount,
            'referencesShown' => min($referenceCount, ReportService::MAX_REFERENCES),
            'sourceOptions' => $report->sourceOptions(),
        ]);
    }

    /**
     * The ignored list: URLs somebody decided not to report any more.
     *
     * Rendered whole rather than through the table endpoint the verdict lists
     * use. A dismissal is a deliberate act, so there are never thousands of
     * them, and a plain table can carry the note and the restore button without
     * any of the paging machinery.
     *
     * @return Response The rendered page.
     * @throws ForbiddenHttpException If the user may not read the reports.
     * @throws InvalidConfigException If a service or the site cannot be
     *                                resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionIgnored(): Response
    {
        $site = $this->requestedSite();
        $rows = LinkAudit::$plugin->getIgnoreService()->ignoredUrls(siteIds: $this->allowedSiteIds());

        foreach ($rows as $i => $row) {
            $rows[$i]['dateIgnored'] = $this->_toDate($row['dateIgnored']);
            $rows[$i]['detailUrl'] = $row['url'] !== null
                ? UrlHelper::cpUrl('link-audit/url', ['hash' => (string)$row['urlHash']])
                : null;
        }

        return $this->renderTemplate('link-audit/ignored', [
            'site' => $site,
            'siteHandle' => $site->handle,
            'sites' => $this->allowedSites(),
            'canManageIgnores' => Craft::$app->getUser()->checkPermission(self::PERMISSION_MANAGE_IGNORES),
            'rows' => $rows,
        ]);
    }

    /**
     * The no answer list: URLs that did not answer when they were last asked.
     *
     * @return Response The rendered page.
     * @throws ForbiddenHttpException If the user may not read the reports.
     * @throws InvalidConfigException If a service or the site cannot be
     *                                resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionNoAnswer(): Response
    {
        return $this->_list(UrlStatus::Unreachable, 'link-audit/no-answer');
    }

    /**
     * The redirects list.
     *
     * @return Response The rendered page.
     * @throws ForbiddenHttpException If the user may not read the reports.
     * @throws InvalidConfigException If a service or the site cannot be
     *                                resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionRedirects(): Response
    {
        return $this->_list(UrlStatus::Redirect, 'link-audit/redirects');
    }

    /**
     * A page of rows for whichever list is asking.
     *
     * @return Response The rows, as JSON.
     * @throws BadRequestHttpException If the request does not accept JSON.
     * @throws ForbiddenHttpException If the user may not read the reports.
     * @throws InvalidConfigException If a service or the site cannot be
     *                                resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionTable(): Response
    {
        $this->requireAcceptsJson();

        $status = UrlStatus::tryFrom((string)$this->request->getParam('verdict', '')) ?? UrlStatus::Broken;
        $siteId = $this->resolveSiteId($this->request->getParam('siteId'));
        $page = max(1, (int)$this->request->getParam('page', 1));
        $perPage = max(1, (int)$this->request->getParam('per_page', self::PER_PAGE));

        $filters = $this->reportFilters();
        $filters['search'] = trim((string)($this->request->getParam('search') ?? ''));

        // The table's own column names on the left, the service's on the right.
        // The default is the last checked date rather than the reference count:
        // the count is a correlated subquery, so sorting on it is something the
        // reader asks for rather than something every page load pays for.
        $sort = match ($this->request->getParam('sort.0.field')) {
            'url' => 'url',
            'host' => 'host',
            'code' => 'httpStatus',
            'refs' => 'refCount',
            default => 'lastChecked',
        };
        $direction = $this->request->getParam('sort.0.direction') === 'asc' ? SORT_ASC : SORT_DESC;

        $result = LinkAudit::$plugin->getReportService()
            ->urlTable($status, $siteId, $filters, $page, $perPage, $sort, $direction);

        return $this->asJson([
            'success' => true,
            'pagination' => AdminTable::paginationLinks($page, $result['total'], $perPage),
            'data' => array_map(fn(array $row): array => $this->_row($row), $result['rows']),
        ]);
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * A stored date, formatted for the reader.
     *
     * @param mixed $value The stored value.
     * @return string The formatted date, or an empty string when there is none.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _formatDate(mixed $value): string
    {
        $date = $this->_toDate($value);

        if ($date === null) {
            return '';
        }

        // Date only: in a list, "checked this morning or last month" is the
        // question, and the clock time is clutter at fifty rows a page. The
        // detail page keeps the full timestamp.
        return (string)Craft::$app->getFormatter()->asDate($date, Locale::LENGTH_SHORT);
    }

    /**
     * Whether a stored URL may be drawn as a link somebody can follow.
     *
     * A URL the plugin never checks is still stored, raw href and all, so the
     * author can see it was met, and a raw href is whatever somebody typed into
     * a field. Rendering one as a live anchor would put `javascript:` and
     * `vbscript:` values a click away inside the control panel, under the
     * privileges of whoever is reading the report.
     *
     * The list is {@see UrlSafety::ALLOWED_SCHEMES}, and deliberately so: the
     * schemes this plugin is willing to fetch are the schemes it is willing to
     * hand somebody a link to.
     *
     * @param array<string, mixed> $url The URL row.
     * @return bool Whether it may be drawn as an anchor.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _isOpenable(array $url): bool
    {
        $scheme = strtolower(trim((string)($url['scheme'] ?? '')));

        return in_array($scheme, UrlSafety::ALLOWED_SCHEMES, true);
    }

    /**
     * Renders one of the list screens.
     *
     * @param UrlStatus $status The verdict the screen lists.
     * @param string $template The template to render.
     * @return Response The rendered page.
     * @throws ForbiddenHttpException If the user may not read the reports.
     * @throws InvalidConfigException If a service or the site cannot be
     *                                resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _list(UrlStatus $status, string $template): Response
    {
        $report = LinkAudit::$plugin->getReportService();
        $site = $this->requestedSite();
        $siteId = (int)$site->id;
        $filters = $this->reportFilters();
        $user = Craft::$app->getUser();

        return $this->renderTemplate($template, [
            'canRecheck' => $user->checkPermission(self::PERMISSION_RUN_SCANS),
            'canIgnore' => $user->checkPermission(self::PERMISSION_MANAGE_IGNORES),
            'site' => $site,
            'siteId' => $siteId,
            'siteHandle' => $site->handle,
            'sites' => $this->allowedSites(),
            'verdict' => $status->value,
            'filters' => $filters,
            'hostOptions' => $report->hostOptions($status, $siteId),
            'elementTypeOptions' => $report->elementTypeOptions($status, $siteId),
            'sourceOptions' => $report->sourceOptions(),
            'perPage' => self::PER_PAGE,
            // Counted up front, without the filters, so the empty state only
            // appears when the site genuinely has nothing with this verdict.
            // A filter that matches nothing must keep the filter bar on the
            // page, or the reader has no way to loosen it again; the table's
            // own empty message covers that case. Counted rather than read:
            // asking for one row would pay for the whole sort and then throw
            // the row away.
            'total' => $report->urlCount($status, $siteId, []),
        ]);
    }

    /**
     * A stored check message, said plainly.
     *
     * A transport failure arrives wrapped in libcurl's own vocabulary: an error
     * number nobody outside a terminal recognises, and a link to the libcurl
     * error list on the end of it. What is left once both are off is the part an
     * editor can act on, which is usually a sentence saying the host could not be
     * resolved.
     *
     * Display only. The stored row keeps every character the checker recorded,
     * and the untouched string is put behind the technical detail on the page, so
     * a developer chasing a TLS failure still has the whole of it.
     *
     * @param string $message The stored message, already trimmed.
     * @return string|null The message worth showing, or null when there is none.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _plainMessage(string $message): ?string
    {
        if ($message === '') {
            return null;
        }

        if (preg_match(self::_CURL_PREFIX_PATTERN, $message) !== 1) {
            return $message;
        }

        $plain = (string)preg_replace(self::_CURL_PREFIX_PATTERN, '', $message);
        $plain = trim((string)preg_replace(self::_CURL_DOCS_PATTERN, '', $plain));

        // A message that is nothing but the wrapper says less than the wrapper
        // did, so the original stands rather than an empty row.
        return $plain !== '' ? $plain : $message;
    }

    /**
     * One URL row in the shape the table wants.
     *
     * @param array<string, mixed> $row The row from the report service.
     * @return array<string, mixed> The row for the table.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _row(array $row): array
    {
        $status = UrlStatus::tryFrom((string)$row['status']) ?? UrlStatus::Pending;
        $url = (string)$row['url'];

        return [
            'id' => (int)$row['id'],
            'url' => [
                'full' => $url,
                'detailUrl' => UrlHelper::cpUrl('link-audit/url', ['hash' => (string)$row['urlHash']]),
                'openable' => $this->_isOpenable($row),
                // Stand-in rows for element links hold an internal marker, not
                // an address; offering to copy one hands the reader a string
                // that means nothing outside this plugin.
                'copyable' => ExtractedLink::standInScheme($url) === null,
            ],
            'status' => [
                'colour' => $status->colour(),
                'label' => $status->label(),
            ],
            // A payload rather than the bare number, because the table hands a
            // column callback its own value and nothing else, and the code cell
            // draws the redirect's own status alongside the final one.
            'code' => [
                'status' => $row['httpStatus'] !== null ? (int)$row['httpStatus'] : null,
                'statusTitle' => Verdict::httpStatusLabel($row['httpStatus'] !== null ? (int)$row['httpStatus'] : null),
                'redirectStatus' => $row['redirectStatus'] !== null ? (int)$row['redirectStatus'] : null,
                'redirectTitle' => Verdict::httpStatusLabel($row['redirectStatus'] !== null ? (int)$row['redirectStatus'] : null),
            ],
            'host' => (string)$row['host'],
            'refs' => (int)$row['refCount'],
            'checked' => $this->_formatDate($row['dateLastChecked']),
            'redirect' => [
                'finalUrl' => $row['finalUrl'] !== null ? (string)$row['finalUrl'] : null,
                'permanent' => $row['redirectPermanent'] !== null ? (bool)$row['redirectPermanent'] : null,
                'hops' => (int)$row['redirectCount'],
            ],
            'actions' => $this->_rowActions((string)$row['urlHash']),
        ];
    }

    /**
     * What this reader is allowed to do to a row.
     *
     * Decided here rather than in the template, so a reader holding neither
     * permission is never sent the buttons in the first place. A control the
     * server would refuse has no business being drawn, and hiding it in
     * JavaScript only hides it.
     *
     * @param string $hash The sha1 of the normalised URL.
     * @return array<string, mixed>|null What they may do, or null when the answer
     *                                   is nothing.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _rowActions(string $hash): ?array
    {
        $user = Craft::$app->getUser();
        $recheck = $user->checkPermission(self::PERMISSION_RUN_SCANS);
        $ignore = $user->checkPermission(self::PERMISSION_MANAGE_IGNORES);

        if (!$recheck && !$ignore) {
            return null;
        }

        return [
            'hash' => $hash,
            'recheck' => $recheck,
            'ignore' => $ignore,
        ];
    }

    /**
     * Which list a verdict belongs to, so the detail page lights up the subnav
     * item the reader arrived through.
     *
     * @param UrlStatus $status The verdict.
     * @return string The subnav item key.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _subnavItemFor(UrlStatus $status): string
    {
        return match ($status) {
            UrlStatus::Redirect => 'redirects',
            UrlStatus::Blocked => 'blocked',
            UrlStatus::Unreachable => 'no-answer',
            default => 'broken',
        };
    }

    /**
     * A stored date as a real date.
     *
     * The stored strings are UTC with nothing on them to say so, and handing one
     * straight to a template reads it as local time, which is quietly wrong by
     * the server's offset.
     *
     * @param mixed $value The stored value.
     * @return DateTime|null The date, or null when there is none.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _toDate(mixed $value): ?DateTime
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = DateTimeHelper::toDateTime((string)$value);

        return $date instanceof DateTime ? $date : null;
    }
}
