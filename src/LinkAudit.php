<?php

/**
 * Link Audit plugin for Craft CMS 5.
 *
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\console\Application as ConsoleApplication;
use johnhenry\linkaudit\base\PluginTrait;
use johnhenry\linkaudit\models\SettingsModel;
use johnhenry\linkaudit\services\ServicesTrait;

/**
 * Link Audit plugin.
 *
 * Finds every link in your content, checks each unique URL once, and reports
 * which entries carry the broken ones.
 *
 * Verdicts are cached per URL with a per-status time to live, so a rescan only
 * pays for what has gone stale.
 *
 * @property-read SettingsModel $settings
 * @author John Henry Donovan
 * @since 1.0.0
 */
class LinkAudit extends BasePlugin
{
    // =========================================================================
    // Traits
    // =========================================================================

    use ServicesTrait;
    use PluginTrait;

    // =========================================================================
    // Static Properties
    // =========================================================================

    /**
     * @var LinkAudit The plugin instance.
     */
    public static LinkAudit $plugin;

    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public bool $hasCpSettings = true;

    /**
     * @inheritdoc
     *
     * Declared rather than left to Craft, which only works it out for itself
     * when the default settings response is in use. Without it the settings link
     * on the Plugins screen disappears wherever `allowAdminChanges` is off, and
     * the screen is meant to be readable there.
     */
    public bool $hasReadOnlyCpSettings = true;

    /**
     * @inheritdoc
     */
    public bool $hasCpSection = true;

    /**
     * @inheritdoc
     */
    public string $schemaVersion = '1.0.0';

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        Craft::setAlias('@johnhenry/linkaudit', __DIR__);

        $this->_registerLogTarget();
        // Registered in every context: a permission is checked from console
        // commands and queue workers as well as from the control panel, and a
        // handle nobody has registered is a handle nobody but an admin holds.
        $this->_registerPermissions();
        // Also every context, and for the same sort of reason. Content is saved
        // and deleted from console commands and queue workers as much as it is
        // from the control panel: a feed import runs in a worker, and an entry
        // imported with a broken link in it is exactly the one worth catching.
        $this->_registerScanOnSave();
        $this->_registerReferenceCleanup();
        // The mirror of the cleanup above, so a page pulled back out of the bin
        // gets its links back with it. Restores happen in a console command as
        // readily as they do in the control panel.
        $this->_registerRestoreExtraction();
        // Garbage collection is mostly a console job, so this would be dead
        // weight registered for the control panel alone.
        $this->_registerGarbageCollection();
        $this->_registerWidgetTypes();

        if (Craft::$app instanceof ConsoleApplication) {
            return;
        }

        $this->_registerCpUrlRules();
        $this->_registerElementSidebarPanel();
    }
}
