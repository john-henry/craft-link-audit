<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\migrations;

use Craft;
use craft\db\Migration;
use yii\base\Exception;

/**
 * Switches the site on each excluded URI pattern from a site ID to a site UID,
 * so the setting means the same site in every environment.
 *
 * The settings live in project config, so this runs once, on the environment
 * that first runs it; other environments pick it up from the YAML.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0-beta.8
 */
class m260925_000001_excluded_uri_site_uids extends Migration
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var string The project config path to the excluded URI patterns.
     */
    private const PATH = 'plugins.link-audit.settings.excludedUriPatterns';

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return bool Whether the migration applied successfully.
     * @throws Exception if project config can't be updated.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0-beta.8
     */
    public function safeUp(): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $schemaVersion = $projectConfig->get('plugins.link-audit.schemaVersion', true);

        if ($schemaVersion !== null && version_compare($schemaVersion, '1.0.1', '>=')) {
            return true;
        }

        $rows = $projectConfig->get(self::PATH);

        if (!is_array($rows) || $rows === []) {
            return true;
        }

        $sites = Craft::$app->getSites();
        $changed = false;

        foreach ($rows as $key => $row) {
            if (!is_array($row) || !array_key_exists('siteId', $row)) {
                continue;
            }

            $siteId = (int)$row['siteId'];
            $row['siteUid'] = $row['siteUid'] ?? ($siteId ? ($sites->getSiteById($siteId, true)->uid ?? '') : '');
            unset($row['siteId']);
            $rows[$key] = $row;
            $changed = true;
        }

        if ($changed) {
            $projectConfig->set(self::PATH, $rows, 'Store Link Audit excluded URI sites by UID');
        }

        return true;
    }

    /**
     * @inheritdoc
     *
     * @return bool Always false; the change isn't reverted.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0-beta.8
     */
    public function safeDown(): bool
    {
        echo "m260925_000001_excluded_uri_site_uids cannot be reverted.\n";

        return false;
    }
}
