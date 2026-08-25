<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\controllers;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\i18n\Locale;
use DateTime;
use Exception;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\LinkAudit;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The three things a reader can do to one URL: check it again, ignore it, or
 * bring an ignored one back.
 *
 * Kept apart from {@see UrlsController} because these change something and those
 * do not. That is not tidiness for its own sake: the read screens are fenced by
 * `viewReports` alone, and every action here asks for more on top of it.
 *
 * Each action answers twice over. A button in a Vue table posts with fetch and
 * wants JSON back; the same action posted from a plain form on the detail page
 * wants a flash message and a redirect. Supporting both is what lets the detail
 * page work with JavaScript switched off.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class UrlActionsController extends BaseController
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Stops reporting one URL.
     *
     * @return Response JSON, or a redirect back to where the button was.
     * @throws BadRequestHttpException If the request is not a POST.
     * @throws ForbiddenHttpException If the user may not manage ignores.
     * @throws NotFoundHttpException If no URL has been seen with that hash, or
     *                               nothing on the user's sites points at it.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionIgnore(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_MANAGE_IGNORES);

        $hash = $this->_requestedHash();
        $this->_url($hash);

        $note = trim((string)($this->request->getBodyParam('note') ?? ''));
        $userId = Craft::$app->getUser()->getId();

        $ignored = LinkAudit::$plugin->getIgnoreService()->ignoreUrl(
            $hash,
            $note !== '' ? $note : null,
            $userId !== null ? (int)$userId : null,
        );

        if (!$ignored) {
            throw new NotFoundHttpException(Craft::t('link-audit', 'No URL has been seen with that address.'));
        }

        return $this->_respond(
            Craft::t('link-audit', 'That URL will not be reported again until somebody restores it.'),
        );
    }

    /**
     * Checks one URL again, there and then.
     *
     * Inline rather than queued: it is one URL and one request, and somebody is
     * sitting there waiting for the answer. It goes through the same code the
     * check phase uses, so an internal link is answered from the database, an
     * external one goes out through the scheduler, and the verdict lands on the
     * row the same way it would during a scan.
     *
     * @return Response JSON, or a redirect back to where the button was.
     * @throws BadRequestHttpException If the request is not a POST.
     * @throws Exception If a time to live setting cannot be turned into an
     *                   interval.
     * @throws ForbiddenHttpException If the user may not run scans.
     * @throws NotFoundHttpException If no URL has been seen with that hash, or
     *                               nothing on the user's sites points at it.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionRecheck(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_RUN_SCANS);

        $report = LinkAudit::$plugin->getReportService();
        $hash = $this->_requestedHash();
        $row = $this->_url($hash);

        if ((string)$row['status'] === UrlStatus::Ignored->value) {
            return $this->_respond(
                Craft::t('link-audit', 'That URL is ignored, so it is not checked. Restore it first.'),
                success: false,
            );
        }

        $checked = LinkAudit::$plugin->getScanService()->checkChunk([$row]);

        if ($checked === 0) {
            return $this->_respond(
                Craft::t('link-audit', 'The host asked to be left alone for a while, so nothing was checked. Try again shortly.'),
                success: false,
            );
        }

        $fresh = $report->urlByHash($hash) ?? $row;
        $status = UrlStatus::tryFrom((string)$fresh['status']) ?? UrlStatus::Pending;

        return $this->_respond(
            Craft::t('link-audit', 'Checked: {verdict}.', ['verdict' => $status->label()]),
            ['verdict' => $this->_verdictPayload($fresh, $status)],
        );
    }

    /**
     * Rereads one page and queues a fresh check of everything it links to.
     *
     * The links panel's button. Queued rather than inline, unlike the one-URL
     * recheck above: a page can carry dozens of links, and dozens of outbound
     * requests are the queue's job, not a browser request's. The panel says
     * so, and the counts catch up when the queue has been through them.
     *
     * @return Response JSON.
     * @throws BadRequestHttpException If the request is not a POST that accepts
     *                                 JSON.
     * @throws ForbiddenHttpException If the user may not run scans, or may not
     *                                edit the site.
     * @throws Throwable If the element's reference rows cannot be rebuilt.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionRecheckElement(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_RUN_SCANS);
        $this->requireAcceptsJson();

        $elementId = (int)$this->request->getRequiredBodyParam('elementId');
        $siteId = (int)$this->request->getRequiredBodyParam('siteId');

        if (!in_array($siteId, $this->allowedSiteIds(), true)) {
            throw new ForbiddenHttpException(
                Craft::t('link-audit', 'You are not allowed to edit that site.'),
            );
        }

        $count = LinkAudit::$plugin->getScanService()->recheckElementLinks($elementId, $siteId);

        if ($count === 0) {
            return $this->asJson([
                'success' => true,
                'count' => 0,
                'message' => Craft::t('link-audit', 'The page was read again and carries no links to check.'),
            ]);
        }

        return $this->asJson([
            'success' => true,
            'count' => $count,
            'message' => Craft::t(
                'link-audit',
                'The page was read again and {count, plural, one{its link is} other{its # links are}} queued for a fresh check. The panel catches up once the queue has been through them.',
                ['count' => $count],
            ),
        ]);
    }

    /**
     * Brings an ignored URL back into the reports and checks it there and then.
     *
     * The check is immediate, and deliberately not gated on the scan permission:
     * it is one request, made as a consequence of an action the reader was
     * entitled to take, and the alternative leaves the URL pending on no list at
     * all until the next scan happens by. The check goes through the same code
     * the check phase uses, so host politeness still applies; a host in a
     * backoff window leaves the URL pending with a plain word about it.
     *
     * @return Response JSON, or a redirect back to where the button was.
     * @throws BadRequestHttpException If the request is not a POST.
     * @throws Exception If a time to live setting cannot be turned into an
     *                   interval.
     * @throws ForbiddenHttpException If the user may not manage ignores.
     * @throws NotFoundHttpException If no URL has been seen with that hash,
     *                               nothing on the user's sites points at it, or
     *                               it was not ignored.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionRestore(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_MANAGE_IGNORES);

        $hash = $this->_requestedHash();
        $this->_url($hash);

        if (!LinkAudit::$plugin->getIgnoreService()->restoreUrl($hash)) {
            throw new NotFoundHttpException(Craft::t('link-audit', 'That URL was not ignored.'));
        }

        $report = LinkAudit::$plugin->getReportService();
        $row = $report->urlByHash($hash);
        $checked = $row !== null ? LinkAudit::$plugin->getScanService()->checkChunk([$row]) : 0;

        if ($checked === 0) {
            return $this->_respond(
                Craft::t('link-audit', 'Restored. The host asked to be left alone for a while, so it gets its verdict at the next check.'),
            );
        }

        $fresh = $report->urlByHash($hash) ?? $row;
        $status = UrlStatus::tryFrom((string)$fresh['status']) ?? UrlStatus::Pending;

        return $this->_respond(
            Craft::t('link-audit', 'Restored and checked: {verdict}.', ['verdict' => $status->label()]),
            ['verdict' => $this->_verdictPayload($fresh, $status)],
        );
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
        if ($value === null || $value === '') {
            return '';
        }

        $date = DateTimeHelper::toDateTime((string)$value);

        if (!$date instanceof DateTime) {
            return '';
        }

        return (string)Craft::$app->getFormatter()->asDatetime($date, Locale::LENGTH_SHORT);
    }

    /**
     * The hash the request is asking about.
     *
     * @return string The hash.
     * @throws BadRequestHttpException If the request carries no hash.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _requestedHash(): string
    {
        $hash = trim((string)($this->request->getBodyParam('hash') ?? ''));

        if ($hash === '') {
            throw new BadRequestHttpException('No URL was named.');
        }

        return $hash;
    }

    /**
     * Answers in whichever way the caller asked.
     *
     * @param string $message What happened, in the reader's terms.
     * @param array<string, mixed> $data Anything else the JSON caller wants.
     * @param bool $success Whether it worked.
     * @return Response The response.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _respond(string $message, array $data = [], bool $success = true): Response
    {
        if ($this->request->getAcceptsJson()) {
            return $this->asJson(array_merge([
                'success' => $success,
                'message' => $message,
            ], $success ? $data : ['error' => $message]));
        }

        if ($success) {
            $this->setSuccessFlash($message);
        } else {
            $this->setFailFlash($message);
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * One URL row this user is entitled to act on, or a 404.
     *
     * The hash arrives on a POST body and is fenced by nothing on its own, so
     * every action here resolves it through this rather than handing it straight
     * to a service. A hash for a URL only referenced on somebody else's site
     * gets the same answer as a hash nobody recognises, which is the point: the
     * refusal must not say whether the URL exists.
     *
     * @param string $hash The sha1 of the normalised URL.
     * @return array<string, mixed> The row.
     * @throws ForbiddenHttpException If the user may not edit any site.
     * @throws NotFoundHttpException If no URL has been seen with that hash, or
     *                               nothing on the user's sites points at it.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _url(string $hash): array
    {
        $row = LinkAudit::$plugin->getReportService()->urlByHash($hash);

        if ($row === null) {
            throw new NotFoundHttpException(Craft::t('link-audit', 'No URL has been seen with that address.'));
        }

        $this->requireReadableUrl((int)$row['id']);

        return $row;
    }

    /**
     * The verdict a JSON caller wants back after a check.
     *
     * @param array<string, mixed> $fresh The row as it stands after the check.
     * @param UrlStatus $status The row's status.
     * @return array<string, mixed> The payload.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _verdictPayload(array $fresh, UrlStatus $status): array
    {
        return [
            'status' => $status->value,
            'label' => $status->label(),
            'colour' => $status->colour(),
            'httpStatus' => $fresh['httpStatus'] !== null ? (int)$fresh['httpStatus'] : null,
            'checked' => $this->_formatDate($fresh['dateLastChecked']),
        ];
    }
}
