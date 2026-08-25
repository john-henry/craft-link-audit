<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\services;

use Craft;
use craft\base\Component;
use craft\elements\User;

/**
 * The short tour of the Overview a reader gets the first time they open it.
 *
 * A link checker is one of those plugins where every number on the front screen
 * is a word somebody made up. Broken, Unverifiable, No Answer: an editor meeting
 * them cold has to guess which ones are their problem, and the usual guess is
 * that all of them are. Six sentences on first sight sorts that out for good.
 *
 * What the tour says lives here rather than in the JavaScript, for two reasons.
 * The sentences are copy, so they belong somewhere `Craft::t()` can reach them
 * and a translator can find them. And keeping the steps as data leaves the
 * JavaScript generic: it takes a list of selectors and sentences and drives
 * them, which is the shape this wants to be in when it is lifted out into
 * something shared.
 *
 * Whether somebody has seen it is a user preference rather than a row of our
 * own. It is a fact about a person, not about their links, and Craft already
 * has the place for those.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class TourService extends Component
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var string What every preference the plugin writes is named under.
     *
     * User preferences are one flat map shared by Craft and every plugin on the
     * install, so ours carry a prefix. Uninstalling reads this to know which
     * keys to take back out, which is why it is a constant rather than a habit:
     * a key written outside the prefix would be left behind.
     */
    public const PREFERENCE_PREFIX = 'linkAudit.';

    /**
     * @var string The user preference holding whether somebody has met the
     * Overview tour.
     */
    public const PREFERENCE_OVERVIEW_SEEN = self::PREFERENCE_PREFIX . 'overviewTourSeen';

    /**
     * @var string What the scan step points at, named once so the step and the
     * permission that drops it cannot drift apart.
     */
    private const _SCAN_STEP_SELECTOR = '#link-audit-scan-actions';

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Whether this reader has already met the Overview tour.
     *
     * A user with no id at all (which should not happen behind a control panel
     * gate, but costs nothing to answer for) is treated as having seen it: an
     * autostarting tour is the wrong thing to do when there is nowhere to record
     * that it has been done, because it would then start again on every page
     * load.
     *
     * @param User $user The reader.
     * @return bool Whether they have seen it.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function hasSeen(User $user): bool
    {
        if ($user->id === null) {
            return true;
        }

        return (bool)Craft::$app->getUsers()->getUserPreference(
            (int)$user->id,
            self::PREFERENCE_OVERVIEW_SEEN,
            false,
        );
    }

    /**
     * Records that this reader has met the Overview tour.
     *
     * Written when the tour is finished and when it is dismissed alike. Somebody
     * who closed it after one step has said what they think of it, and a tour
     * that comes back until it is sat through to the end is worse than no tour.
     *
     * @param User $user The reader.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function markSeen(User $user): void
    {
        if ($user->id === null) {
            return;
        }

        Craft::$app->getUsers()->saveUserPreferences($user, [
            self::PREFERENCE_OVERVIEW_SEEN => true,
        ]);
    }

    /**
     * The steps of the Overview tour, in the order they are walked.
     *
     * Each one names the thing on screen it is about and says one useful thing
     * about it. A step whose selector matches nothing on the page is dropped by
     * the page itself rather than here, because what is on the Overview depends
     * on the state of the install: a site that has never been scanned has no
     * Last scan pane to point at.
     *
     * The scan step is the one exception, and it is a permission rather than a
     * page state. A reader who may not run scans is never sent the sentence
     * about the buttons at all, so the page they are looking at does not carry
     * copy naming a control they were deliberately not given.
     *
     * @param bool $canRunScans Whether this reader may run a scan.
     * @return array<int, array{selector: string, title: string, text: string}>
     *         The steps.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function stepsForOverview(bool $canRunScans = true): array
    {
        $steps = [
            [
                'selector' => '.link-audit-tiles',
                'title' => Craft::t('link-audit', 'Where things stand'),
                'text' => Craft::t('link-audit', 'Five counts: Broken needs fixing, Permanent Redirects are worth updating, Unverifiable and No Answer are being watched, and Working is everything else. Click a count to see its list.'),
            ],
            [
                'selector' => self::_SCAN_STEP_SELECTOR,
                'title' => Craft::t('link-audit', 'Running a scan'),
                'text' => Craft::t('link-audit', 'Scan what changed reads recently edited content. Run full scan reads everything. Either way, every unique address is only checked once.'),
            ],
            [
                'selector' => '#nav-link-audit-subnav',
                'title' => Craft::t('link-audit', 'The lists'),
                'text' => Craft::t('link-audit', 'The lists live here. The numbers beside them are live counts for the site you are on.'),
            ],
            [
                'selector' => '#link-audit-last-scan',
                'title' => Craft::t('link-audit', 'Last scan'),
                'text' => Craft::t('link-audit', 'When the last scan ran and how long it took. If this is old, the counts are old too.'),
            ],
            [
                'selector' => '#link-audit-start',
                'title' => Craft::t('link-audit', 'Where to start'),
                'text' => Craft::t('link-audit', 'A suggested working order for a long broken list: your own pages first, then the few addresses whose one fix clears the most places.'),
            ],
            [
                'selector' => '#link-audit-worst',
                'title' => Craft::t('link-audit', 'The worst offenders'),
                'text' => Craft::t('link-audit', 'The hosts and pages carrying the most broken links. Fixing one page often clears several.'),
            ],
            [
                'selector' => '#link-audit-tour-start',
                'title' => Craft::t('link-audit', 'Taking it again'),
                'text' => Craft::t('link-audit', 'That is the lot. This link brings the tour back any time.'),
            ],
        ];

        if (!$canRunScans) {
            return array_values(array_filter(
                $steps,
                static fn(array $step): bool => $step['selector'] !== self::_SCAN_STEP_SELECTOR,
            ));
        }

        return $steps;
    }
}
