<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\base;

use Craft;
use craft\base\Element;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Cp;
use craft\helpers\ElementHelper;
use craft\helpers\Queue as QueueHelper;
use craft\helpers\UrlHelper;
use craft\log\MonologTarget;
use craft\services\Dashboard;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use johnhenry\linkaudit\controllers\BaseController;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\helpers\ScannableElementTypes;
use johnhenry\linkaudit\jobs\ExtractElementLinks;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\SettingsModel;
use johnhenry\linkaudit\services\UninstallService;
use johnhenry\linkaudit\widgets\BrokenLinksWidget;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LogLevel;
use Throwable;
use yii\base\Event;
use yii\base\InvalidConfigException;

/**
 * Wires the plugin's event listeners and lifecycle overrides.
 *
 * The main class stays a thin shell: everything Craft has to be told about
 * lives here.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
trait PluginTrait
{
    // =========================================================================
    // Private Properties
    // =========================================================================

    /**
     * @var array<int, bool> The elements already queued for rereading this
     * request, keyed by element id. Kept by {@see self::_queueExtraction()}.
     */
    private static array $_queuedForExtraction = [];

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * The subnav carries the requested site through every link. These URLs are
     * otherwise static, so a click would drop the `site` parameter and fall back
     * to the primary site, quietly undoing the site switcher the reader just
     * used. The suffix is only added off the primary site, so a single site
     * install keeps clean URLs.
     *
     * Nothing is listed that the reader cannot open: the report screens need
     * `viewReports`, and the whole item is dropped when they have none of the
     * plugin's permissions at all. The badges go with the item, so a reader who
     * cannot see the section is not told how many broken links are in it.
     *
     * @return array<string, mixed>|null The nav item, or null when this user has
     *                                   no business in the section.
     * @throws InvalidConfigException If the primary site cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('link-audit', 'Link Audit');

        $user = Craft::$app->getUser();

        if (!$user->checkPermission(BaseController::PERMISSION_VIEW_REPORTS)) {
            return null;
        }

        $site = Cp::requestedSite();
        $suffix = $site !== null && $site->id !== Craft::$app->getSites()->getPrimarySite()->id
            ? '?site=' . $site->handle
            : '';

        $item['subnav'] = [
            'overview' => [
                'label' => Craft::t('link-audit', 'Overview'),
                'url' => 'link-audit' . $suffix,
            ],
            'broken' => [
                'label' => Craft::t('link-audit', 'Broken'),
                'url' => 'link-audit/broken' . $suffix,
            ],
            'redirects' => [
                'label' => Craft::t('link-audit', 'Redirects'),
                'url' => 'link-audit/redirects' . $suffix,
            ],
            'blocked' => [
                'label' => Craft::t('link-audit', 'Unverifiable'),
                'url' => 'link-audit/blocked' . $suffix,
            ],
            'no-answer' => [
                'label' => Craft::t('link-audit', 'No Answer'),
                'url' => 'link-audit/no-answer' . $suffix,
            ],
            'ignored' => [
                'label' => Craft::t('link-audit', 'Ignored'),
                'url' => 'link-audit/ignored' . $suffix,
            ],
        ];

        self::_addNavBadges($item, $site !== null ? (int)$site->id : null);

        // Admins only, and deliberately not gated on `allowAdminChanges`: the
        // settings screen is readable on an environment where it cannot be
        // edited, and hiding the link there would only mean an admin has to
        // remember the URL to find out how the plugin is configured.
        if ($user->getIsAdmin()) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('app', 'Settings'),
                'url' => 'link-audit/settings',
            ];
        }

        return $item;
    }

    /**
     * @inheritdoc
     *
     * The settings live on the plugin's own tabbed screen rather than in the
     * single pane Craft renders for `settingsHtml()`, so this sends the reader
     * there. Read-only environments go to the same place: the screen renders
     * itself static and refuses the saves.
     *
     * @return mixed The redirect.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getReadOnlySettingsResponse(): mixed
    {
        return $this->getSettingsResponse();
    }

    /**
     * @inheritdoc
     *
     * Narrows the base return type for callers and static analysis.
     *
     * @return SettingsModel The plugin settings model.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getSettings(): SettingsModel
    {
        $settings = parent::getSettings();
        assert($settings instanceof SettingsModel);

        return $settings;
    }

    /**
     * @inheritdoc
     *
     * @return mixed The redirect to the plugin's own settings screen.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('link-audit/settings'));
    }

    // =========================================================================
    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Clears what dropping the tables does not reach: queued jobs, the tour
     * preference, the dashboard tiles and the cached counts. What each of those
     * is and why it is left over is {@see UninstallService}.
     *
     * Nothing in there is allowed to stop an uninstall. Craft runs this inside
     * the transaction that removes the plugin, so an exception here is a plugin
     * that cannot be uninstalled at all, which is a far worse thing to be than a
     * plugin that left a row behind.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function beforeUninstall(): void
    {
        parent::beforeUninstall();

        try {
            $this->getUninstallService()->clearAll();
        } catch (Throwable $e) {
            Craft::error('Could not finish the uninstall cleanup: ' . $e->getMessage(), 'link-audit');
        }
    }

    /**
     * @inheritdoc
     *
     * @return SettingsModel The plugin settings model.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function createSettingsModel(): SettingsModel
    {
        return new SettingsModel();
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Hangs the verdict counts off the navigation item and its subnav.
     *
     * The section's own badge is the broken count, since that is the only one of
     * them that is work somebody has to do; the rest sit beside the screen that
     * lists them. Redirects counts the permanent ones only, for the same reason:
     * a temporary redirect is the far end's business, a permanent one is an
     * address the content still holds after it moved.
     *
     * Scoped to the site the control panel is currently on, which is the site
     * every one of these links already carries, so a badge cannot promise a
     * number the screen behind it then disagrees with.
     *
     * The counts come out of the cache rather than the database. This runs on
     * every control panel page load there is, including the ones that have
     * nothing to do with links.
     *
     * @param array<string, mixed> $item The nav item, badged in place.
     * @param int|null $siteId The site being read, or null when there is not one.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private static function _addNavBadges(array &$item, ?int $siteId): void
    {
        if ($siteId === null) {
            return;
        }

        // A badge is not worth an error page. A count that could not be read is
        // a missing badge, which nobody will notice; an uncaught exception in
        // the navigation is a control panel nobody can open at all.
        try {
            $counts = LinkAudit::$plugin->getReportService()->cachedVerdictCounts($siteId);
        } catch (Throwable $e) {
            Craft::error('Could not count the URLs for the nav badges: ' . $e->getMessage(), 'link-audit');

            return;
        }

        $badges = [
            'broken' => (int)($counts[UrlStatus::Broken->value] ?? 0),
            'redirects' => (int)($counts['permanentRedirect'] ?? 0),
            'blocked' => (int)($counts[UrlStatus::Blocked->value] ?? 0),
            'no-answer' => (int)($counts[UrlStatus::Unreachable->value] ?? 0),
            'ignored' => (int)($counts[UrlStatus::Ignored->value] ?? 0),
        ];

        // The key is left off at zero rather than set to it. Craft's own chrome
        // reads a zero as no badge either way, so this changes nothing on
        // screen; it means the item carries only the keys it actually has
        // something to say with.
        foreach ($badges as $key => $count) {
            if ($count > 0) {
                $item['subnav'][$key]['badgeCount'] = $count;
            }
        }

        if ($badges['broken'] > 0) {
            $item['badgeCount'] = $badges['broken'];
        }
    }

    /**
     * The listener behind both content change events.
     *
     * A save and a restore ask the same question of the same guards, so they
     * share the answer. What differs between them is only which event Craft
     * raises, which is the registering method's business.
     *
     * @param Event $e The element event.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private static function _onContentChange(Event $e): void
    {
        $element = $e->sender;

        if (!$element instanceof Element) {
            return;
        }

        $elementId = self::_pageToReread($element);

        if ($elementId === null) {
            return;
        }

        self::_queueExtraction($elementId);
    }

    /**
     * The element a save should send back through the extractor, if any.
     *
     * Every reason not to bother is here, in the order that costs least to ask.
     * A save is one of the most frequent things that happens in a control panel,
     * and most of them have nothing to say about links.
     *
     * A block is answered with the page it sits on. References are recorded
     * against the block itself, but the walk that finds them starts at the page,
     * so the page is what gets read; a page with ten edited blocks is then one
     * job rather than ten.
     *
     * @param Element $element The element that was saved.
     * @return int|null The element id to reread, or null when this save is not
     *                  worth a job.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private static function _pageToReread(Element $element): ?int
    {
        $settings = LinkAudit::$plugin->getSettings();

        if (!$settings->scanOnSave) {
            return null;
        }

        // A bulk resave (the resave commands, a migration, a plugin touching
        // every entry) would otherwise queue a job per element: a sweep of the
        // whole site nobody asked for, saying nothing new, since a resave
        // changes no content. A full scan is the way to ask for a sweep.
        if ($element->resaving) {
            return null;
        }

        // Drafts and revisions both: every manual save writes a revision whose
        // own save event fires too, carrying the same content under an element
        // id nobody can edit.
        if (ElementHelper::isDraftOrRevision($element)) {
            return null;
        }

        $root = $element->getRootOwner();

        if (!$root instanceof Element || $root->id === null || $root->resaving) {
            return null;
        }

        if (!$root->enabled || ElementHelper::isDraftOrRevision($root)) {
            return null;
        }

        // Assets fall out here as well as anything the settings leave out: they
        // are binary files, and their field layout holds alt text rather than
        // links.
        if (!in_array($root::class, $settings->resolvedScannedElementTypes(), true)) {
            return null;
        }

        if (LinkAudit::$plugin->getScanService()->isUriExcluded($root->uri, (int)$root->siteId)) {
            return null;
        }

        if (LinkAudit::$plugin->getScanService()->isContainerExcluded($root)) {
            return null;
        }

        return (int)$root->id;
    }

    /**
     * Queues one reread of an element, at most once a request.
     *
     * One request can reach this more than once: a page and the blocks inside it
     * are separate elements with separate events, and they all resolve back to
     * the same page. The memo is what turns that into one job rather than one
     * per block, and it lasts a request, which is exactly as long as it is true
     * for.
     *
     * @param int $elementId The element to reread.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private static function _queueExtraction(int $elementId): void
    {
        if (isset(self::$_queuedForExtraction[$elementId])) {
            return;
        }

        self::$_queuedForExtraction[$elementId] = true;

        QueueHelper::push(new ExtractElementLinks(['elementId' => $elementId]));
    }

    /**
     * Maps the plugin's control panel URLs onto its controllers.
     *
     * The URLs are the ones people bookmark and share, so they are short and say
     * what they are: the controller and action behind each one is nobody else's
     * business. The URL detail page is addressed by hash rather than by row id,
     * so a link to it means the same URL on every environment.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $e): void {
                $e->rules = array_merge($e->rules, [
                    'link-audit' => 'link-audit/dashboard/index',
                    'link-audit/broken' => 'link-audit/urls/broken',
                    'link-audit/redirects' => 'link-audit/urls/redirects',
                    'link-audit/blocked' => 'link-audit/urls/blocked',
                    'link-audit/no-answer' => 'link-audit/urls/no-answer',
                    'link-audit/ignored' => 'link-audit/urls/ignored',
                    'link-audit/url' => 'link-audit/urls/detail',
                    'link-audit/settings' => 'link-audit/settings/index',
                    'link-audit/settings/scanning' => 'link-audit/settings/scanning',
                    'link-audit/settings/http' => 'link-audit/settings/http',
                    'link-audit/settings/caching' => 'link-audit/settings/caching',
                    'link-audit/settings/ignores' => 'link-audit/settings/ignores',
                    'link-audit/settings/notifications' => 'link-audit/settings/notifications',
                    'link-audit/settings/schedule' => 'link-audit/settings/schedule',
                ]);
            },
        );
    }

    /**
     * Adds a links panel to the sidebar of an element's edit screen.
     *
     * The point of it is that the report is somewhere else. An author editing a
     * page has no reason to go looking at a list of every broken URL on the
     * site, and would not know which of them were theirs if they did. This puts
     * the answer where the work is: this page holds so many links, so many have
     * been checked, and these ones are broken.
     *
     * Registered on `Element` rather than on `Entry`, because the scan reads
     * every type the settings name, and the guards below are the same set the
     * scan itself applies so the panel cannot claim an element is clean when the
     * truth is that it was never read.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _registerElementSidebarPanel(): void
    {
        $handler = static function(DefineHtmlEvent $e): void {
            $element = $e->sender;

            if (!$element instanceof Element || $element->id === null) {
                return;
            }

            // The handler is registered on the concrete types and on the
            // base Element both, so for most types it is offered the same
            // event twice. Its own markup is the memo.
            if (str_contains($e->html, 'link-audit-panel')) {
                return;
            }

            // A draft or a revision has no references of its own, and the
            // canonical element's would be reported here as though they were
            // this version's.
            if (ElementHelper::isDraftOrRevision($element)) {
                return;
            }

            // Assets fall out here along with anything the settings leave
            // out: a panel on an element the scan never reads would say
            // "no links" forever.
            if (!in_array($element::class, LinkAudit::$plugin->getSettings()->resolvedScannedElementTypes(), true)) {
                return;
            }

            // The same goes for an excluded section or category group: the
            // scan never reads these either, so the panel has nothing honest
            // to say on them.
            if (LinkAudit::$plugin->getScanService()->isContainerExcluded($element)) {
                return;
            }

            // The panel is a report, so it wants the permission the reports
            // want. Everything it links to would refuse the reader anyway.
            if (!Craft::$app->getUser()->checkPermission(BaseController::PERMISSION_VIEW_REPORTS)) {
                return;
            }

            $site = Craft::$app->getSites()->getSiteById((int)$element->siteId);

            if ($site === null) {
                return;
            }

            // Nothing here is worth taking an edit screen down for. A panel
            // that cannot render is a missing panel; an uncaught exception
            // is an author who cannot edit their page.
            try {
                $summary = LinkAudit::$plugin->getReportService()->elementSummary(
                        (int)$element->id,
                        (int)$site->id,
                    );

                $e->html .= Craft::$app->getView()->renderTemplate(
                        'link-audit/_sidebar/links-panel',
                        [
                            'element' => $element,
                            'site' => $site,
                            'summary' => $summary,
                            'canRunScans' => Craft::$app->getUser()->checkPermission(
                                BaseController::PERMISSION_RUN_SCANS,
                            ),
                        ],
                    );
            } catch (Throwable $err) {
                Craft::error(
                        "Could not render the links panel for element $element->id: " . $err->getMessage(),
                        'link-audit',
                    );
            }
        };

        // Registered on every concrete element type as well as on the base
        // Element, each prepended to its handler queue. The panel has work in
        // it, broken links to click and a recheck button, so it belongs above
        // the informational panels other plugins add, but never above Craft's
        // own status and meta, which are in the event's html before any
        // listener runs. The concrete registrations are what win that slot:
        // Yii fires a concrete class's handlers before its parents', and the
        // other plugins hook the concrete classes, so a handler only on
        // Element runs after them whatever order it registered in. The base
        // registration stays as the net for a type registered by a plugin
        // this one cannot see, and the markup check above keeps the pair from
        // drawing the panel twice. Deferred to onInit so the element type
        // registry is complete when it is read.
        Craft::$app->onInit(static function() use ($handler): void {
            $classes = array_keys(ScannableElementTypes::all());
            $classes[] = Element::class;

            foreach ($classes as $class) {
                Event::on($class, Element::EVENT_DEFINE_SIDEBAR_HTML, $handler, append: false);
            }
        });
    }

    /**
     * Hangs the fallback schedule off Craft's garbage collection.
     *
     * Garbage collection is the nearest thing Craft has to a heartbeat on an
     * install with no cron, which is the only reason this is here. It is a
     * backstop, not a schedule: it runs when it runs, and the Schedule settings
     * screen says as much and gives the cron line to use instead.
     *
     * Everything that decides whether a scan is due lives in the service, so the
     * console, the button and this all ask the same question the same way. When
     * the setting is off it is two comparisons and no queries at all.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, static function(): void {
            // Garbage collection has plenty else to be getting on with, and a
            // scan that could not be queued is not a reason to stop it.
            try {
                LinkAudit::$plugin->getScanService()->runScheduledScan();
            } catch (Throwable $e) {
                Craft::error('Could not queue the scheduled scan: ' . $e->getMessage(), 'link-audit');
            }
        });
    }

    /**
     * Routes everything the plugin logs (the 'link-audit' category) into its
     * own storage/logs/link-audit-*.log file, so scan and check activity can be
     * read without fishing through web.log and queue.log.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _registerLogTarget(): void
    {
        Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
            'name' => 'link-audit',
            'categories' => ['link-audit'],
            'level' => LogLevel::INFO,
            'logContext' => false,
            'allowLineBreaks' => false,
            'formatter' => new LineFormatter(
                format: "%datetime% [%level_name%] %message%\n",
                dateFormat: 'Y-m-d H:i:s',
            ),
        ]);
    }

    /**
     * Registers the plugin's user permissions.
     *
     * Three tiers rather than two. Reading a report and queueing a scan are the
     * usual split, but dismissing a URL is a third thing again: an ignore
     * changes what every other editor sees from then on, and deciding a broken
     * link does not matter is not the same decision as asking for a scan.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $e): void {
                $e->permissions[] = [
                    'heading' => Craft::t('link-audit', 'Link Audit'),
                    'permissions' => [
                        BaseController::PERMISSION_VIEW_REPORTS => [
                            'label' => Craft::t('link-audit', 'View link reports'),
                        ],
                        BaseController::PERMISSION_RUN_SCANS => [
                            'label' => Craft::t('link-audit', 'Run link scans'),
                        ],
                        BaseController::PERMISSION_MANAGE_IGNORES => [
                            'label' => Craft::t('link-audit', 'Ignore and restore links'),
                        ],
                    ],
                ];
            },
        );
    }

    /**
     * Clears an element's references when it is deleted.
     *
     * The references table's foreign key already covers a hard delete, but a
     * delete in Craft is a soft one: the row stays where it is with a date on
     * it, so nothing cascades. Without this, deleting a page would leave its
     * broken links on the report, pointing at an entry nobody can open.
     *
     * Drafts and revisions are left alone. They never had references of their
     * own, and Craft deletes them constantly.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _registerReferenceCleanup(): void
    {
        Event::on(
            Element::class,
            Element::EVENT_AFTER_DELETE,
            static function(Event $e): void {
                $element = $e->sender;

                if (!$element instanceof Element || $element->id === null) {
                    return;
                }

                if (ElementHelper::isDraftOrRevision($element)) {
                    return;
                }

                // Nothing here is allowed to fail a delete. An author removing a
                // page should not be told their page could not be deleted
                // because a link audit table would not tidy itself up.
                try {
                    LinkAudit::$plugin->getUrlStore()->deleteReferencesForElement((int)$element->id);
                } catch (Throwable $err) {
                    Craft::error(
                        "Could not clear the links held against deleted element $element->id: "
                        . $err->getMessage(),
                        'link-audit',
                    );
                }
            },
        );
    }

    /**
     * Rereads an element's links when somebody brings it back out of the bin.
     *
     * The mirror of {@see self::_registerReferenceCleanup()}. Deleting a page
     * clears its references, so restoring it has to put them back: nothing else
     * would, short of the next full scan, and a page restored on Monday should
     * not be missing off the report until Friday.
     *
     * A restore is not a save, so Craft fires no save event for it and the
     * on save hook never hears about it. The listener is the same one all the
     * same, because the question is the same question.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _registerRestoreExtraction(): void
    {
        Event::on(Element::class, Element::EVENT_AFTER_RESTORE, self::_onContentChange(...));
    }

    /**
     * Rereads an element's links when somebody saves it.
     *
     * The event is the one that fires after an element is fully saved and
     * propagated, which Craft raises once per save rather than once per site, so
     * a save on a five site install queues one job and the job reads all five.
     *
     * Deciding whether a save is worth a job is {@see self::_pageToReread()},
     * and the listener itself is {@see self::_onContentChange()}, shared with
     * the restore hook.
     *
     * A URI is deliberately not required. An entry with no public page still has
     * fields full of links, which is the same call the scan query makes.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _registerScanOnSave(): void
    {
        Event::on(Element::class, Element::EVENT_AFTER_PROPAGATE, self::_onContentChange(...));
    }

    /**
     * Offers the broken links tile to the dashboard.
     *
     * Registered in every context, as a component type has to be: a widget class
     * Craft has never been told about is one it cannot rebuild from a user's
     * saved dashboard, and dashboards get rebuilt by console commands as well as
     * by people.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _registerWidgetTypes(): void
    {
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            static function(RegisterComponentTypesEvent $e): void {
                $e->types[] = BrokenLinksWidget::class;
            },
        );
    }
}
