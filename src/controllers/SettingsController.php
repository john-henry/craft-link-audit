<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\controllers;

use Craft;
use craft\web\View;
use johnhenry\linkaudit\helpers\ScannableElementTypes;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\SettingsModel;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The plugin's settings, spread over one tab per decision an admin has to make.
 *
 * Two things about this controller are load-bearing.
 *
 * Every save reads only the fields its own tab posted, and writes the whole
 * settings model back. The obvious way round, handing the posted body straight
 * to `savePluginSettings()`, quietly drops everything the form did not carry:
 * save the HTTP tab and the notification settings are gone. Reading each field
 * by name off the tab that owns it makes that impossible to do by accident.
 *
 * And the read and the write are gated on different things. Being on the screen
 * needs an admin, whatever `allowAdminChanges` says, because a production admin
 * still needs to see what the plugin is configured to do. Changing anything
 * needs `allowAdminChanges` as well, checked again on the server: the form is
 * rendered read-only without it, but a form is not a gate.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class SettingsController extends BaseController
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Admins only, and deliberately without requiring `allowAdminChanges`: the
     * screen is readable on an environment where it cannot be edited, and the
     * write gate sits on the save actions instead.
     *
     * @param mixed $action The action about to run.
     * @return bool Whether the action may run.
     * @throws ForbiddenHttpException If the user is not an admin, or may not read
     *                                the reports.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin(requireAdminChanges: false);

        return true;
    }

    /**
     * The caching and retention tab.
     *
     * @return Response The rendered page.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionCaching(): Response
    {
        return $this->_render('caching');
    }

    /**
     * The HTTP tab.
     *
     * @return Response The rendered page.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionHttp(): Response
    {
        return $this->_render('http');
    }

    /**
     * The ignore rules tab.
     *
     * @return Response The rendered page.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionIgnores(): Response
    {
        return $this->_render('ignores');
    }

    /**
     * Sends anybody arriving at the bare settings URL to the first tab.
     *
     * @return Response The redirect.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionIndex(): Response
    {
        return $this->redirect('link-audit/settings/scanning');
    }

    /**
     * The notifications tab.
     *
     * @return Response The rendered page.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionNotifications(): Response
    {
        return $this->_render('notifications');
    }

    /**
     * Saves the caching and retention tab.
     *
     * @return Response A redirect back to the tab, or the tab again when the
     *                  values were refused.
     * @throws BadRequestHttpException If the request is not a POST.
     * @throws ForbiddenHttpException If settings are read-only on this
     *                                environment.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionSaveCaching(): Response
    {
        $settings = $this->_beginSave();

        $settings->okTtlDays = $this->_int('okTtlDays', $settings->okTtlDays);
        $settings->redirectTtlDays = $this->_int('redirectTtlDays', $settings->redirectTtlDays);
        $settings->brokenRecheckHours = $this->_int('brokenRecheckHours', $settings->brokenRecheckHours);
        $settings->unreachableRecheckHours = $this->_int(
            'unreachableRecheckHours',
            $settings->unreachableRecheckHours,
        );
        $settings->blockedTtlDays = $this->_int('blockedTtlDays', $settings->blockedTtlDays);
        $settings->retainDays = $this->_int('retainDays', $settings->retainDays);
        $settings->pruneOrphanUrls = $this->_bool('pruneOrphanUrls', $settings->pruneOrphanUrls);

        return $this->_finishSave($settings, 'caching');
    }

    /**
     * Saves the HTTP tab.
     *
     * @return Response A redirect back to the tab, or the tab again when the
     *                  values were refused.
     * @throws BadRequestHttpException If the request is not a POST.
     * @throws ForbiddenHttpException If settings are read-only on this
     *                                environment.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionSaveHttp(): Response
    {
        $settings = $this->_beginSave();

        $settings->concurrency = $this->_int('concurrency', $settings->concurrency);
        $settings->maxConcurrentPerHost = $this->_int('maxConcurrentPerHost', $settings->maxConcurrentPerHost);
        $settings->minHostDelayMs = $this->_int('minHostDelayMs', $settings->minHostDelayMs);
        $settings->connectTimeout = $this->_int('connectTimeout', $settings->connectTimeout);
        $settings->timeout = $this->_int('timeout', $settings->timeout);
        $settings->maxRedirects = $this->_int('maxRedirects', $settings->maxRedirects);
        $settings->retryCount = $this->_int('retryCount', $settings->retryCount);
        $settings->brokenAfterFailures = $this->_int('brokenAfterFailures', $settings->brokenAfterFailures);
        $settings->brokenAfterDnsFailures = $this->_int(
            'brokenAfterDnsFailures',
            $settings->brokenAfterDnsFailures,
        );
        $settings->userAgent = $this->_string('userAgent', $settings->userAgent);
        $settings->verifySsl = $this->_bool('verifySsl', $settings->verifySsl);
        $settings->proxy = $this->_string('proxy', $settings->proxy);

        return $this->_finishSave($settings, 'http');
    }

    /**
     * Saves the ignore rules tab.
     *
     * @return Response A redirect back to the tab, or the tab again when the
     *                  values were refused.
     * @throws BadRequestHttpException If the request is not a POST.
     * @throws ForbiddenHttpException If settings are read-only on this
     *                                environment.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionSaveIgnores(): Response
    {
        $settings = $this->_beginSave();

        $settings->ignorePatterns = $this->_rows(
            'ignorePatterns',
            ['enabled' => 'bool', 'pattern' => 'string', 'note' => 'string'],
            ['pattern'],
            $settings->ignorePatterns,
        );
        $settings->ignoreHosts = $this->_rows(
            'ignoreHosts',
            ['enabled' => 'bool', 'host' => 'string', 'note' => 'string'],
            ['host'],
            $settings->ignoreHosts,
        );
        // One column and no note, on purpose: the bot-block heuristics read
        // every string in the row as a host, so a second column would have the
        // checker treating somebody's comment as a domain name.
        $settings->botHostileHosts = $this->_rows(
            'botHostileHosts',
            ['host' => 'string'],
            ['host'],
            $settings->botHostileHosts,
        );

        return $this->_finishSave($settings, 'ignores');
    }

    /**
     * Saves the notifications tab.
     *
     * @return Response A redirect back to the tab, or the tab again when the
     *                  values were refused.
     * @throws BadRequestHttpException If the request is not a POST.
     * @throws ForbiddenHttpException If settings are read-only on this
     *                                environment.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionSaveNotifications(): Response
    {
        $settings = $this->_beginSave();

        $settings->notifyEmailEnabled = $this->_bool('notifyEmailEnabled', $settings->notifyEmailEnabled);
        $settings->notifyEmailRecipients = $this->_string(
            'notifyEmailRecipients',
            $settings->notifyEmailRecipients,
        );
        $settings->notifySlackEnabled = $this->_bool('notifySlackEnabled', $settings->notifySlackEnabled);
        $settings->notifySlackWebhookUrl = $this->_string(
            'notifySlackWebhookUrl',
            $settings->notifySlackWebhookUrl,
        );
        $settings->notifyOnNewBroken = $this->_bool('notifyOnNewBroken', $settings->notifyOnNewBroken);
        $settings->notifyBrokenThreshold = $this->_int(
            'notifyBrokenThreshold',
            $settings->notifyBrokenThreshold,
        );

        return $this->_finishSave($settings, 'notifications');
    }

    /**
     * Saves the scanning tab.
     *
     * @return Response A redirect back to the tab, or the tab again when the
     *                  values were refused.
     * @throws BadRequestHttpException If the request is not a POST.
     * @throws ForbiddenHttpException If settings are read-only on this
     *                                environment.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionSaveScanning(): Response
    {
        $settings = $this->_beginSave();

        $settings->scannedElementTypes = $this->_elementTypes($settings->scannedElementTypes);
        $settings->excludedSectionUids = $this->_uidList('excludedSectionUids', $settings->excludedSectionUids);
        $settings->excludedCategoryGroupUids = $this->_uidList('excludedCategoryGroupUids', $settings->excludedCategoryGroupUids);
        $settings->scanOnSave = $this->_bool('scanOnSave', $settings->scanOnSave);
        $settings->checkInternalLinks = $this->_bool('checkInternalLinks', $settings->checkInternalLinks);
        $settings->checkImages = $this->_bool('checkImages', $settings->checkImages);
        $settings->checkIframes = $this->_bool('checkIframes', $settings->checkIframes);
        $settings->scanNavigationNodes = $this->_bool('scanNavigationNodes', $settings->scanNavigationNodes);
        $settings->renderedCrawlEnabled = $this->_bool('renderedCrawlEnabled', $settings->renderedCrawlEnabled);
        $settings->maxPagesToCrawl = $this->_int('maxPagesToCrawl', $settings->maxPagesToCrawl);
        $settings->checkAnchorFragments = $this->_bool('checkAnchorFragments', $settings->checkAnchorFragments);
        $settings->scanRelationFields = $this->_bool('scanRelationFields', $settings->scanRelationFields);
        $settings->scanPlainTextUrls = $this->_bool('scanPlainTextUrls', $settings->scanPlainTextUrls);
        $settings->stripTrackingParams = $this->_bool('stripTrackingParams', $settings->stripTrackingParams);
        $settings->excludedUriPatterns = $this->_rows(
            'excludedUriPatterns',
            ['enabled' => 'bool', 'siteId' => 'siteId', 'uriPattern' => 'string'],
            ['uriPattern'],
            $settings->excludedUriPatterns,
        );
        $settings->internalUrlAllowPatterns = $this->_rows(
            'internalUrlAllowPatterns',
            ['pattern' => 'string', 'note' => 'string'],
            ['pattern'],
            $settings->internalUrlAllowPatterns,
        );

        return $this->_finishSave($settings, 'scanning');
    }

    /**
     * Saves the schedule tab.
     *
     * @return Response A redirect back to the tab, or the tab again when the
     *                  values were refused.
     * @throws BadRequestHttpException If the request is not a POST.
     * @throws ForbiddenHttpException If settings are read-only on this
     *                                environment.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionSaveSchedule(): Response
    {
        $settings = $this->_beginSave();

        $settings->scheduledScanEnabled = $this->_bool('scheduledScanEnabled', $settings->scheduledScanEnabled);
        $settings->scheduledScanIntervalHours = $this->_int(
            'scheduledScanIntervalHours',
            $settings->scheduledScanIntervalHours,
        );

        return $this->_finishSave($settings, 'schedule');
    }

    /**
     * The scanning tab.
     *
     * @return Response The rendered page.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionScanning(): Response
    {
        return $this->_render('scanning');
    }

    /**
     * The schedule tab.
     *
     * @return Response The rendered page.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionSchedule(): Response
    {
        return $this->_render('schedule');
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * The checks every save starts with, and the model it works on.
     *
     * @return SettingsModel The settings, loaded in full so that writing them
     *                       back cannot drop another tab's values.
     * @throws BadRequestHttpException If the request is not a POST.
     * @throws ForbiddenHttpException If settings are read-only on this
     *                                environment.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _beginSave(): SettingsModel
    {
        $this->requirePostRequest();

        // The form is rendered read-only without this, but a rendered form is
        // not a gate: the post is refused here as well.
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException('Administrative changes are disallowed on this environment.');
        }

        return $this->_settings();
    }

    /**
     * A posted boolean.
     *
     * @param string $name The setting name.
     * @param bool $current What it holds now, used when the field was not
     *                      posted.
     * @return bool The value.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _bool(string $name, bool $current): bool
    {
        return (bool)$this->request->getBodyParam("settings[$name]", $current);
    }

    /**
     * The posted element type set.
     *
     * A checkbox list posts an empty string when nothing is ticked, which is a
     * deliberate empty list rather than a missing one, so it is kept as an empty
     * array: null means "the native default" and would put every type back.
     *
     * @param string[]|null $current What it holds now.
     * @return string[]|null The value.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _elementTypes(?array $current): ?array
    {
        $posted = $this->request->getBodyParam('settings[scannedElementTypes]');

        if (!is_array($posted)) {
            return $current;
        }

        // Only the scalar members are kept. A crafted post can nest an array in
        // here, and a nested array through strval is a warning that devMode
        // turns into an exception, with the literal string "Array" stored in
        // project config behind it.
        return array_values(array_filter(array_map(
            'strval',
            array_filter($posted, 'is_scalar'),
        )));
    }

    /**
     * A posted list of UIDs off a checkbox select.
     *
     * A checkbox select with nothing ticked posts an empty string rather than
     * an array, and that is a real answer: it means every box was cleared, so
     * it empties the setting rather than keeping the old one. Only a missing
     * parameter altogether keeps what was stored.
     *
     * @param string $key The setting the field posts as.
     * @param string[] $current The stored value, kept when the field was not
     *                          posted at all.
     * @return string[] The value.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _uidList(string $key, array $current): array
    {
        $posted = $this->request->getBodyParam("settings[$key]");

        if ($posted === null) {
            return $current;
        }

        if (!is_array($posted)) {
            return [];
        }

        // Scalars only, so a nested array cannot become the string "Array" in
        // project config; see {@see self::_elementTypes()}.
        return array_values(array_filter(array_map(
            'strval',
            array_filter($posted, 'is_scalar'),
        )));
    }

    /**
     * Writes the settings back and answers the browser.
     *
     * @param SettingsModel $settings The settings to write.
     * @param string $tab The tab that posted, so a refusal comes back to it.
     * @return Response The redirect, or the tab again with its errors showing.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _finishSave(SettingsModel $settings, string $tab): Response
    {
        $saved = Craft::$app->getPlugins()->savePluginSettings(LinkAudit::$plugin, $settings->toArray());

        if (!$saved) {
            $this->setFailFlash(Craft::t(
                'link-audit',
                'Those settings could not be saved. Check the fields marked below and try again.',
            ));

            return $this->_render($tab);
        }

        $this->setSuccessFlash(Craft::t('app', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * A posted whole number.
     *
     * A box cleared out keeps the stored value rather than becoming a zero
     * nobody typed: for the settings where zero is legal, "trust nothing" and
     * "keep everything" among them, an accidental backspace-and-save should
     * not change policy. A deliberate 0 posts as the character and lands.
     *
     * @param string $name The setting name.
     * @param int $current What it holds now, used when the field was not
     *                     posted, or was posted empty.
     * @return int The value.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _int(string $name, int $current): int
    {
        $posted = $this->request->getBodyParam("settings[$name]");

        if ($posted === null || trim((string)$posted) === '') {
            return $current;
        }

        return (int)$posted;
    }

    /**
     * Renders one tab.
     *
     * Everything a tab needs is assembled here rather than in the action, so a
     * save that comes back with errors renders exactly the same page as the
     * action does, rather than a version of it missing a field.
     *
     * @param string $tab The tab template, which is also its key in the tab bar.
     * @return Response The rendered page.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _render(string $tab): Response
    {
        $settings = $this->_settings();

        $variables = [
            'tab' => $tab,
            'settings' => $settings,
            // Anything set in config/link-audit.php wins over the database, so
            // the fields it covers are shown static rather than pretending to be
            // editable.
            'config' => Craft::$app->getConfig()->getConfigFromFile('link-audit'),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ];

        if ($tab === 'scanning') {
            $variables['elementTypeOptions'] = ScannableElementTypes::all();
            $variables['scannedElementTypes'] = $settings->resolvedScannedElementTypes();
        }

        // The template mode is named rather than left to the request: a save
        // that comes back with errors renders this page from an action request,
        // which is a site request whenever the form posted somewhere other than
        // under the control panel trigger, and the plugin's control panel
        // templates are not on the site path.
        return $this->renderTemplate("link-audit/_settings/$tab", $variables, View::TEMPLATE_MODE_CP);
    }

    /**
     * The posted rows of an editable table, cleaned down to the columns the
     * services that read the setting actually understand.
     *
     * Anything else a browser sends is dropped rather than stored: these values
     * go to project config, which is committed, and a stray column would be
     * there for good.
     *
     * @param string $name The setting name.
     * @param array<string, string> $columns The columns to keep, each named with
     *                                       the kind of value it holds: `bool`,
     *                                       `siteId` or `string`.
     * @param string[] $required The columns that make a row worth keeping. A row
     *                           with none of them filled in is an empty "add
     *                           row" nobody typed into.
     * @param array<int, array<string, mixed>> $current What it holds now, used
     *                                                  when the table was not
     *                                                  posted.
     * @return array<int, array<string, mixed>> The rows.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _rows(string $name, array $columns, array $required, array $current): array
    {
        $posted = $this->request->getBodyParam("settings[$name]");

        if (!is_array($posted)) {
            return $current;
        }

        $rows = [];

        foreach ($posted as $postedRow) {
            if (!is_array($postedRow)) {
                continue;
            }

            $row = [];

            foreach ($columns as $column => $kind) {
                $value = $postedRow[$column] ?? null;

                $row[$column] = match ($kind) {
                    'bool' => (bool)$value,
                    // An empty site means every site, and is kept as an empty
                    // string rather than a zero, which is what the readers of
                    // this setting compare against.
                    'siteId' => trim((string)$value) === '' ? '' : (int)$value,
                    default => trim((string)$value),
                };
            }

            $filled = array_filter(
                $required,
                static fn(string $column): bool => ($row[$column] ?? '') !== '',
            );

            if ($filled === []) {
                continue;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The plugin's settings.
     *
     * @return SettingsModel The settings.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _settings(): SettingsModel
    {
        return LinkAudit::$plugin->getSettings();
    }

    /**
     * A posted string.
     *
     * @param string $name The setting name.
     * @param string $current What it holds now, used when the field was not
     *                        posted.
     * @return string The value.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _string(string $name, string $current): string
    {
        return trim((string)$this->request->getBodyParam("settings[$name]", $current));
    }
}
