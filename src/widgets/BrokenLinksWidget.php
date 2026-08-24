<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\widgets;

use Craft;
use craft\base\Widget;
use craft\models\Site;
use johnhenry\linkaudit\controllers\BaseController;
use johnhenry\linkaudit\LinkAudit;
use Throwable;

/**
 * A dashboard tile saying how many links are broken and when they were last
 * looked at.
 *
 * The reports are a section of their own that nobody visits unprompted, which is
 * the whole problem with a link checker: it only helps if somebody remembers it
 * exists. The dashboard is the first screen anybody sees in the morning, so a
 * count sitting there is what turns a scan into work getting done.
 *
 * Deliberately three numbers and a link, not a list. A broken URL means nothing
 * without knowing what points at it, and that is the report's job.
 *
 * @property-read string|null $bodyHtml
 * @author John Henry Donovan
 * @since 1.0.0
 */
class BrokenLinksWidget extends Widget
{
    // =========================================================================
    // Properties
    // =========================================================================

    /**
     * The site the counts are scoped to, or null to cover every site the
     * reader may edit.
     *
     * The dashboard has no site switcher over it, so without this the widget's
     * numbers could never agree with the Overview on a multi-site install: the
     * Overview reads one site, the widget would read them all. Scoping the
     * widget to a site makes the two screens tell the same story.
     *
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public ?int $siteId = null;

    // =========================================================================
    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return string The name shown in the widget picker.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function displayName(): string
    {
        return Craft::t('link-audit', 'Broken Links');
    }

    /**
     * @inheritdoc
     *
     * The plugin's own mark rather than a system icon, so the widget matches
     * the nav item. The mask variant, not the coloured one: widget icons are
     * tinted by the dashboard the same way the nav tints its icons, and the
     * full-colour artwork does not survive that.
     *
     * @return string|null The path to the icon.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function icon(): ?string
    {
        return Craft::getAlias('@johnhenry/linkaudit') . '/icon-mask.svg';
    }

    /**
     * @inheritdoc
     *
     * Kept out of the picker for anybody who may not read the reports. Craft
     * asks this before offering the widget, and the base class's answer (one
     * instance at a time) is worth keeping on top of the permission.
     *
     * @return bool Whether this user may add the widget.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function isSelectable(): bool
    {
        if (!Craft::$app->getUser()->checkPermission(BaseController::PERMISSION_VIEW_REPORTS)) {
            return false;
        }

        return parent::isSelectable();
    }

    /**
     * @inheritdoc
     *
     * @return int|null The widest the widget may be.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public static function maxColspan(): ?int
    {
        return 1;
    }

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * The counts cover every site this reader may edit, because the dashboard
     * has no site switcher over it. A permission taken away after the widget was
     * added leaves the tile in place, so the check is made again here rather
     * than trusted from {@see self::isSelectable()}.
     *
     * @return string|null The widget body, or null when it has nothing it is
     *                     allowed to say.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getBodyHtml(): ?string
    {
        if (!Craft::$app->getUser()->checkPermission(BaseController::PERMISSION_VIEW_REPORTS)) {
            return null;
        }

        $editableSites = array_values(Craft::$app->getSites()->getEditableSites());
        $scopedSite = $this->_scopedSite($editableSites);

        $siteIds = $scopedSite !== null
            ? [(int)$scopedSite->id]
            : array_map(static fn(Site $site): int => (int)$site->id, $editableSites);

        $reports = LinkAudit::$plugin->getReportService();

        // A dashboard is a page full of other people's widgets. One of them
        // throwing should not be the reason nobody can see any of them.
        try {
            $counts = $reports->verdictCountsForSites($siteIds);
            $latestScan = $reports->latestScan();
        } catch (Throwable $e) {
            Craft::error('Could not build the broken links widget: ' . $e->getMessage(), 'link-audit');

            return null;
        }

        return Craft::$app->getView()->renderTemplate('link-audit/_widgets/broken-links', [
            'broken' => $counts['broken'] ?? 0,
            'latestScan' => $latestScan,
            'multiSite' => count($editableSites) > 1,
            'permanentRedirects' => $counts['permanentRedirect'] ?? 0,
            'scopedSite' => $scopedSite,
        ]);
    }

    /**
     * @inheritdoc
     *
     * Only offered on a multi-site install: with one site there is nothing to
     * choose and the settings pane would hold a select with one option in it.
     *
     * @return string|null The settings form, or null when there is nothing to
     *                     set.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getSettingsHtml(): ?string
    {
        $editableSites = array_values(Craft::$app->getSites()->getEditableSites());

        if (count($editableSites) < 2) {
            return null;
        }

        return Craft::$app->getView()->renderTemplate('link-audit/_widgets/broken-links-settings', [
            'siteId' => $this->siteId,
            'sites' => $editableSites,
        ]);
    }

    // =========================================================================
    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return array The validation rules.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['siteId'], 'integer'];

        return $rules;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * The site this widget is scoped to, when the setting still names one the
     * reader may edit.
     *
     * A site that has been deleted, or taken off this reader's editable list,
     * quietly widens the widget back out to everything rather than showing an
     * empty tile or somebody else's numbers.
     *
     * @param Site[] $editableSites The sites this reader may edit.
     * @return Site|null The scoped site, or null to cover them all.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _scopedSite(array $editableSites): ?Site
    {
        if ($this->siteId === null) {
            return null;
        }

        foreach ($editableSites as $site) {
            if ((int)$site->id === $this->siteId) {
                return $site;
            }
        }

        return null;
    }
}
