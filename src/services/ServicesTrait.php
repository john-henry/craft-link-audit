<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\services;

use yii\base\InvalidConfigException;

/**
 * Registers and exposes the plugin's service components.
 *
 * Each service gets an entry in [[config()]] and a typed accessor below, which
 * narrows Yii's signed `?object` return so static analysis can resolve it.
 *
 * @property-read ExportService $exportService
 * @property-read HostState $hostState
 * @property-read HttpChecker $httpChecker
 * @property-read IgnoreService $ignoreService
 * @property-read InternalResolver $internalResolver
 * @property-read LinkExtractor $linkExtractor
 * @property-read NotificationService $notificationService
 * @property-read PageCrawler $pageCrawler
 * @property-read ReportService $reportService
 * @property-read RequestScheduler $requestScheduler
 * @property-read ScanService $scanService
 * @property-read TourService $tourService
 * @property-read UninstallService $uninstallService
 * @property-read UrlStore $urlStore
 * @author John Henry Donovan
 * @since 1.0.0
 */
trait ServicesTrait
{
    // =========================================================================
    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return array The plugin configuration, including registered service components.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function config(): array
    {
        return [
            'components' => [
                'exportService' => ExportService::class,
                'hostState' => HostState::class,
                'httpChecker' => HttpChecker::class,
                'ignoreService' => IgnoreService::class,
                'internalResolver' => InternalResolver::class,
                'linkExtractor' => LinkExtractor::class,
                'notificationService' => NotificationService::class,
                'pageCrawler' => PageCrawler::class,
                'reportService' => ReportService::class,
                'requestScheduler' => RequestScheduler::class,
                'scanService' => ScanService::class,
                'tourService' => TourService::class,
                'uninstallService' => UninstallService::class,
                'urlStore' => UrlStore::class,
            ],
        ];
    }

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Returns the CSV export service.
     *
     * @return ExportService The export service instance.
     * @throws InvalidConfigException If the component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getExportService(): ExportService
    {
        $component = $this->get('exportService');
        assert($component instanceof ExportService);

        return $component;
    }

    /**
     * Returns the host state service.
     *
     * @return HostState The host state service instance.
     * @throws InvalidConfigException If the component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getHostState(): HostState
    {
        $component = $this->get('hostState');
        assert($component instanceof HostState);

        return $component;
    }

    /**
     * Returns the HTTP checker service.
     *
     * @return HttpChecker The HTTP checker service instance.
     * @throws InvalidConfigException If the component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getHttpChecker(): HttpChecker
    {
        $component = $this->get('httpChecker');
        assert($component instanceof HttpChecker);

        return $component;
    }

    /**
     * Returns the ignore service.
     *
     * @return IgnoreService The ignore service instance.
     * @throws InvalidConfigException If the component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getIgnoreService(): IgnoreService
    {
        $component = $this->get('ignoreService');
        assert($component instanceof IgnoreService);

        return $component;
    }

    /**
     * Returns the internal link resolver service.
     *
     * @return InternalResolver The internal resolver service instance.
     * @throws InvalidConfigException If the component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getInternalResolver(): InternalResolver
    {
        $component = $this->get('internalResolver');
        assert($component instanceof InternalResolver);

        return $component;
    }

    /**
     * Returns the link extractor service.
     *
     * @return LinkExtractor The link extractor service instance.
     * @throws InvalidConfigException If the component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getLinkExtractor(): LinkExtractor
    {
        $component = $this->get('linkExtractor');
        assert($component instanceof LinkExtractor);

        return $component;
    }

    /**
     * Returns the notification service.
     *
     * @return NotificationService The notification service instance.
     * @throws InvalidConfigException If the component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getNotificationService(): NotificationService
    {
        $component = $this->get('notificationService');
        assert($component instanceof NotificationService);

        return $component;
    }

    /**
     * Returns the rendered page crawler service.
     *
     * @return PageCrawler The page crawler service instance.
     * @throws InvalidConfigException If the component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getPageCrawler(): PageCrawler
    {
        $component = $this->get('pageCrawler');
        assert($component instanceof PageCrawler);

        return $component;
    }

    /**
     * Returns the control panel reporting service.
     *
     * @return ReportService The report service instance.
     * @throws InvalidConfigException If the component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getReportService(): ReportService
    {
        $component = $this->get('reportService');
        assert($component instanceof ReportService);

        return $component;
    }

    /**
     * Returns the request scheduler service.
     *
     * @return RequestScheduler The request scheduler service instance.
     * @throws InvalidConfigException If the component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getRequestScheduler(): RequestScheduler
    {
        $component = $this->get('requestScheduler');
        assert($component instanceof RequestScheduler);

        return $component;
    }

    /**
     * Returns the scan orchestration service.
     *
     * @return ScanService The scan service instance.
     * @throws InvalidConfigException If the component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getScanService(): ScanService
    {
        $component = $this->get('scanService');
        assert($component instanceof ScanService);

        return $component;
    }

    /**
     * Returns the guided tour service.
     *
     * @return TourService The tour service instance.
     * @throws InvalidConfigException If the component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getTourService(): TourService
    {
        $component = $this->get('tourService');
        assert($component instanceof TourService);

        return $component;
    }

    /**
     * Returns the uninstall cleanup service.
     *
     * @return UninstallService The uninstall service instance.
     * @throws InvalidConfigException If the component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getUninstallService(): UninstallService
    {
        $component = $this->get('uninstallService');
        assert($component instanceof UninstallService);

        return $component;
    }

    /**
     * Returns the URL store service.
     *
     * @return UrlStore The URL store service instance.
     * @throws InvalidConfigException If the component cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getUrlStore(): UrlStore
    {
        $component = $this->get('urlStore');
        assert($component instanceof UrlStore);

        return $component;
    }
}
