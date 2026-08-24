<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\events;

use johnhenry\linkaudit\models\Verdict;
use yii\base\Event;

/**
 * Fired once the checker has decided what a response means, so a project can
 * disagree.
 *
 * Every site meets a host the built-in rules read wrong: an intranet that
 * answers 401 to everything, an API that returns 404 for a HEAD and 200 for a
 * GET, a protection vendor nobody has heard of yet. Rather than have those
 * projects fork the plugin or carry a patch, the last word on a verdict is a
 * listener's.
 *
 * ```php
 * Event::on(
 *     HttpChecker::class,
 *     HttpChecker::EVENT_DEFINE_VERDICT,
 *     function(DefineVerdictEvent $event) {
 *         if ($event->httpStatus === 401 && str_contains($event->url, 'intranet.example.com')) {
 *             $event->verdict = new Verdict(status: UrlStatus::Ok, httpStatus: 401);
 *         }
 *     },
 * );
 * ```
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class DefineVerdictEvent extends Event
{
    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @var string|null Where a redirect chain ended up, or null when it went
     * nowhere.
     */
    public ?string $finalUrl = null;

    /**
     * @var array<string, string[]> The response headers, or an empty array when
     * there was no response.
     */
    public array $headers = [];

    /**
     * @var int|null The status code that came back, or null when nothing did.
     */
    public ?int $httpStatus = null;

    /**
     * @var string|null The request method that produced the answer, 'head' or
     * 'get', or null when no request was made.
     */
    public ?string $method = null;

    /**
     * @var string The URL that was checked.
     */
    public string $url = '';

    /**
     * @var Verdict The verdict, as the checker sees it. Replace it to overrule
     * the checker; whatever is here when the event returns is what the caller
     * gets.
     */
    public Verdict $verdict;
}
