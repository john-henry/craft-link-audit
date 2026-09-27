<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\services;

use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\helpers\UrlNormaliser;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\Verdict;
use Throwable;
use yii\base\Component;

/**
 * Runs a batch of URLs through the checker without behaving like an attack.
 *
 * Four rules shape every pass, and all four exist because somebody else's server
 * is on the other end:
 *
 *  - No more than `concurrency` requests are ever in flight;
 *  - No more than `maxConcurrentPerHost` of them belong to any one domain;
 *  - Two requests to the same domain are at least `minHostDelayMs` apart, or
 *    further apart if that domain has asked for it; and
 *  - A domain already inside a backoff window, or one that earns a window
 *    part way through the run, is not touched again, and its URLs come back
 *    deferred for a later pass.
 *
 * Hosts are dispatched round-robin, so a site with four hundred links in the
 * batch cannot hold up the twenty other sites behind it.
 *
 * Retries are re-queued at the back of the schedule with a `notBefore` stamp,
 * never slept through: a queue job that sleeps out its own time to run is a job
 * that gets released and makes every one of its requests again. The one exception
 * is a bounded twenty millisecond yield when every remaining URL is waiting on a
 * clock and there is nothing else to dispatch, which beats spinning a core to no
 * purpose.
 *
 * All that waiting is capped, in two places, by {@see self::$maxYieldSeconds}. A
 * host with a long gap gets only as many requests as fit in the budget, and a
 * run that spends the budget hands whatever is still waiting back deferred. The
 * run as a whole stops starting requests after {@see self::$maxRunSeconds}. None of these is a verdict: the URLs keep what they
 * knew and come round again on a later pass, which is a great deal better than a
 * job that runs past its time to run, gets released, and makes every one of
 * those requests a second time.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class RequestScheduler extends Component
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var float The longest a retry is ever put off for.
     */
    private const _MAX_BACKOFF_SECONDS = 30.0;

    /**
     * @var float The first retry backoff, doubled for each further attempt.
     */
    private const _RETRY_BACKOFF_BASE_SECONDS = 0.5;

    /**
     * @var float The longest one run will spend waiting on clocks, all told.
     */
    private const _MAX_YIELD_SECONDS = 60.0;

    /**
     * @var int How long to yield for when every remaining URL is waiting on a
     * clock.
     */
    private const _YIELD_MICROSECONDS = 20000;

    /**
     * @var float The longest one run keeps starting new requests for.
     */
    private const _MAX_RUN_SECONDS = 90.0;

    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @var float The longest this run will spend waiting on clocks before it
     * hands the rest of the batch back deferred.
     *
     * A gap between two requests to the same host is waited out in this process,
     * so a batch's waiting is real time on a queue worker with a time to run.
     * Left unbounded, one host with a long learned gap turns a chunk of twenty
     * five URLs into hours: the job runs past its time to run, gets released,
     * and the whole thing starts again, making the same requests to the same
     * host that asked to be left alone.
     *
     * Sixty seconds is comfortably more than a polite full chunk costs at the
     * default floor of 250 milliseconds, and comfortably less than the default
     * time to run.
     */
    public float $maxYieldSeconds = self::_MAX_YIELD_SECONDS;

    /**
     * @var float How long a run keeps starting new requests, in seconds. Once
     * it passes, nothing new goes out: requests already in flight finish, and
     * everything still waiting comes back deferred for a later pass.
     *
     * Retries, the per-host limit, a HEAD followed by a GET and a timeout per
     * redirect hop all make a chunk's real cost hard to predict, so the run is
     * bounded by the clock rather than by an estimate.
     */
    public float $maxRunSeconds = self::_MAX_RUN_SECONDS;

    // =========================================================================
    // Private Properties
    // =========================================================================

    /**
     * @var HttpChecker|null The checker requests go through.
     */
    private ?HttpChecker $_checker = null;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * The checker this scheduler drives.
     *
     * @return HttpChecker The checker.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getChecker(): HttpChecker
    {
        if ($this->_checker === null) {
            $this->_checker = LinkAudit::$plugin->getHttpChecker();
        }

        return $this->_checker;
    }

    /**
     * Checks a batch of URLs and returns what each of them had to say.
     *
     * Every URL passed in comes back in the result, including the ones that were
     * never asked: a deferred verdict is the scheduler saying "not now", and the
     * caller is expected to leave the URL row alone and offer it again on the
     * next pass.
     *
     * @param string[] $urls The absolute, normalised URLs to check.
     * @param callable|null $onVerdict Called with each URL and its verdict as
     *                                 soon as a request settles it, so a run cut
     *                                 short still keeps what it learned.
     *                                 Deferrals decided without a request are
     *                                 only in the return value.
     * @param int|null $retryCount How many times to retry a wobbly answer, or
     *                             null for the setting.
     * @return array<string, Verdict> The verdicts, keyed by URL.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function run(array $urls, ?callable $onVerdict = null, ?int $retryCount = null): array
    {
        $settings = LinkAudit::$plugin->getSettings();
        $hostState = LinkAudit::$plugin->getHostState();
        $checker = $this->getChecker();

        $concurrency = max(1, $settings->concurrency);
        $perHost = max(1, $settings->maxConcurrentPerHost);
        $retryCount = max(0, $retryCount ?? $settings->retryCount);

        $verdicts = [];
        $queues = [];

        foreach (array_unique($urls) as $url) {
            $queues[UrlNormaliser::hostOf($url)][] = ['url' => $url, 'attempt' => 0, 'notBefore' => 0.0];
        }

        $budget = max(0.0, $this->maxYieldSeconds);
        $deadline = microtime(true) + max(0.0, $this->maxRunSeconds);

        // A host still serving out a backoff window is not asked anything. One
        // whose gap is long gets as many of its URLs as fit in the waiting
        // budget, and the rest come back deferred before a request goes out.
        foreach ($queues as $host => $waiting) {
            $host = (string)$host;

            if ($hostState->isBlocked($host)) {
                $this->_deferHost($queues, $verdicts, $host, $this->_blockedSeconds($hostState, $host));

                continue;
            }

            $fit = $this->_fitCount($hostState->minDelayMs($host), $budget);

            if ($fit >= count($waiting)) {
                continue;
            }

            $wait = (int)ceil($hostState->minDelayMs($host) * $fit / 1000);

            foreach (array_slice($waiting, $fit) as $task) {
                $verdicts[(string)$task['url']] = $this->_deferred(Verdict::REASON_HOST_BACKOFF, max(1, $wait));
            }

            $queues[$host] = array_slice($waiting, 0, $fit);
        }

        $inFlight = [];
        $hostInFlight = [];
        $hostNextAllowedAt = [];
        $nextId = 0;
        $yielded = 0.0;

        while ($queues !== [] || $inFlight !== []) {
            if ($queues !== [] && microtime(true) >= $deadline) {
                $this->_deferWaiting($queues, $verdicts, $hostNextAllowedAt);
            }

            do {
                $dispatched = false;

                // One dispatch per host per sweep: that is the round-robin.
                foreach (array_keys($queues) as $host) {
                    if (count($inFlight) >= $concurrency) {
                        break 2;
                    }

                    if (($hostInFlight[$host] ?? 0) >= $perHost) {
                        continue;
                    }

                    $now = microtime(true);

                    if (($hostNextAllowedAt[$host] ?? 0.0) > $now) {
                        continue;
                    }

                    $index = $this->_dueTaskIndex($queues[$host], $now);

                    if ($index === null) {
                        continue;
                    }

                    $task = $queues[$host][$index];
                    unset($queues[$host][$index]);
                    $queues[$host] = array_values($queues[$host]);

                    if ($queues[$host] === []) {
                        unset($queues[$host]);
                    }

                    $id = $nextId++;
                    $attempt = (int)$task['attempt'] + 1;
                    $hostInFlight[$host] = ($hostInFlight[$host] ?? 0) + 1;
                    $hostNextAllowedAt[$host] = $now + $hostState->minDelayMs($host) / 1000;

                    $inFlight[$id] = $checker->checkAsync((string)$task['url'])
                        ->otherwise(fn(mixed $reason): Verdict => $this->_rejectionVerdict($reason))
                        ->then(function(Verdict $verdict) use (
                            &$verdicts,
                            &$queues,
                            &$inFlight,
                            &$hostInFlight,
                            $attempt,
                            $deadline,
                            $host,
                            $hostState,
                            $id,
                            $onVerdict,
                            $retryCount,
                            $task,
                        ): Verdict {
                            unset($inFlight[$id]);
                            $hostInFlight[$host] = max(0, ($hostInFlight[$host] ?? 1) - 1);

                            $url = (string)$task['url'];

                            // A 429. The host has spoken for all of its URLs, not
                            // just this one.
                            if ($verdict->isDeferred()) {
                                $hostState->recordRateLimit($host, $verdict->retryAfterSeconds);
                                $verdicts[$url] = $verdict->withAttempts($attempt);

                                if ($onVerdict !== null) {
                                    $onVerdict($url, $verdicts[$url]);
                                }

                                $this->_deferHost($queues, $verdicts, $host, $verdict->retryAfterSeconds);

                                return $verdict;
                            }

                            if (
                                $attempt <= $retryCount
                                && $this->_isRetryable($verdict)
                                && microtime(true) < $deadline
                            ) {
                                $queues[$host][] = [
                                    'url' => $url,
                                    'attempt' => $attempt,
                                    'notBefore' => microtime(true) + $this->_backoffSeconds($attempt),
                                ];

                                return $verdict;
                            }

                            $verdicts[$url] = $verdict->withAttempts($attempt);
                            $this->_recordOutcome($hostState, $host, $verdict);

                            if ($onVerdict !== null) {
                                $onVerdict($url, $verdicts[$url]);
                            }

                            // A host that has just earned a backoff window gets
                            // nothing more from this run.
                            if (isset($queues[$host]) && $hostState->isBlocked($host)) {
                                $this->_deferHost(
                                    $queues,
                                    $verdicts,
                                    $host,
                                    $this->_blockedSeconds($hostState, $host),
                                );
                            }

                            return $verdict;
                        });

                    $dispatched = true;
                }
            } while ($dispatched);

            if ($inFlight === []) {
                // Everything left is waiting on a clock: a retry serving out its
                // backoff, or a host that may not be approached again yet.
                if ($yielded >= $budget) {
                    // The budget is spent, so the rest of the batch is handed
                    // back deferred rather than waited out. Each one carries how
                    // long it still had to wait, so the URL row is put off by
                    // roughly that and the next pass picks it up.
                    $this->_deferWaiting($queues, $verdicts, $hostNextAllowedAt);

                    break;
                }

                usleep(self::_YIELD_MICROSECONDS);
                $yielded += self::_YIELD_MICROSECONDS / 1000000;

                continue;
            }

            // Waiting on one request drives the whole pool: anything else that
            // finishes on the way settles as it goes.
            $id = array_key_first($inFlight);
            $inFlight[$id]->wait(false);
            unset($inFlight[$id]);
        }

        return $verdicts;
    }

    /**
     * Sets the checker this scheduler drives, which is how tests hand it one
     * wired to a mocked handler stack.
     *
     * @param HttpChecker $checker The checker to use.
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function setChecker(HttpChecker $checker): void
    {
        $this->_checker = $checker;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * How long to leave a URL before trying it again, doubling with each attempt
     * and jittered so a batch that all failed together does not all come back
     * together.
     *
     * @param int $attempt How many attempts have been made.
     * @return float The delay in seconds.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _backoffSeconds(int $attempt): float
    {
        $seconds = min(
            self::_MAX_BACKOFF_SECONDS,
            self::_RETRY_BACKOFF_BASE_SECONDS * (2 ** max(0, $attempt - 1)),
        );

        return $seconds + $seconds * (random_int(0, 250) / 1000);
    }

    /**
     * A verdict that is not a verdict: the URL was set aside without being
     * judged.
     *
     * @param string $reason One of the {@see Verdict} REASON_* constants.
     * @param int|null $retryAfterSeconds Roughly how long the URL still had to
     *                                    wait, so the row can be put off by
     *                                    about that rather than offered again
     *                                    straight away.
     * @return Verdict The deferral.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _deferred(string $reason, ?int $retryAfterSeconds = null): Verdict
    {
        return new Verdict(
            status: UrlStatus::Pending,
            reason: $reason,
            message: 'Set aside so the host is not pressed, and offered again on the next pass.',
            retryAfterSeconds: $retryAfterSeconds,
        );
    }

    /**
     * Hands back everything still queued as a deferral, carrying how long it had
     * left to wait.
     *
     * @param array<string, array<int, array<string, mixed>>> $queues The waiting
     *                                                                tasks, per
     *                                                                host. Left
     *                                                                empty.
     * @param array<string, Verdict> $verdicts The verdicts so far, added to.
     * @param array<string, float> $hostNextAllowedAt When each host may be
     *                                                approached again.
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _deferWaiting(array &$queues, array &$verdicts, array $hostNextAllowedAt): void
    {
        $now = microtime(true);

        foreach ($queues as $host => $waiting) {
            $hostWait = max(0.0, ($hostNextAllowedAt[$host] ?? 0.0) - $now);

            foreach ($waiting as $task) {
                $wait = max($hostWait, (float)$task['notBefore'] - $now);

                $verdicts[(string)$task['url']] = $this->_deferred(
                    Verdict::REASON_HOST_BACKOFF,
                    max(1, (int)ceil($wait)),
                );
            }
        }

        $queues = [];
    }

    /**
     * The first task in a host's queue that is allowed to go now.
     *
     * @param array<int, array<string, mixed>> $tasks The host's queue.
     * @param float $now The current time.
     * @return int|null The index, or null when everything left is still waiting.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _dueTaskIndex(array $tasks, float $now): ?int
    {
        foreach ($tasks as $index => $task) {
            if ((float)$task['notBefore'] <= $now) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Whether a verdict is worth another go.
     *
     * A refused connection, a timeout or a 5xx can all be a bad minute rather
     * than a bad link. A DNS failure or an expired certificate will say the same
     * thing however often it is asked.
     *
     * @param Verdict $verdict The verdict.
     * @return bool Whether to try again.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _isRetryable(Verdict $verdict): bool
    {
        if ($verdict->status !== UrlStatus::Unreachable) {
            return false;
        }

        return in_array(
            $verdict->reason,
            [Verdict::REASON_CONNECT, Verdict::REASON_TIMEOUT, Verdict::REASON_HTTP],
            true,
        );
    }

    /**
     * Tells the host state what this verdict says about the domain, which is not
     * always what it says about the link: a 404 is a host answering perfectly
     * well.
     *
     * @param HostState $hostState The host state service.
     * @param string $host The host.
     * @param Verdict $verdict The verdict.
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _recordOutcome(HostState $hostState, string $host, Verdict $verdict): void
    {
        if ($verdict->status === UrlStatus::Blocked) {
            $hostState->markBotHostile($host);
        }

        match ($verdict->status) {
            UrlStatus::Unreachable => $hostState->recordFailure($host, $verdict->httpStatus),
            UrlStatus::Blocked,
            UrlStatus::Broken,
            UrlStatus::Ok,
            UrlStatus::Redirect => $hostState->recordSuccess($host, $verdict->httpStatus),
            default => null,
        };
    }

    /**
     * A verdict for a promise that rejected, which the checker promises never to
     * do. Here so that a broken promise cannot take a whole batch down with it.
     *
     * @param mixed $reason Whatever the promise rejected with.
     * @return Verdict The verdict.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _rejectionVerdict(mixed $reason): Verdict
    {
        $message = $reason instanceof Throwable ? $reason->getMessage() : 'The check did not complete.';

        return new Verdict(
            status: UrlStatus::Unreachable,
            reason: Verdict::REASON_CONNECT,
            message: $message,
        );
    }

    /**
     * How long a host still has to serve of its backoff window.
     *
     * @param HostState $hostState The host state service.
     * @param string $host The host.
     * @return int The seconds, at least one.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0-beta.8
     */
    private function _blockedSeconds(HostState $hostState, string $host): int
    {
        $until = $hostState->blockedUntil($host);

        return max(1, ($until?->getTimestamp() ?? time()) - time());
    }

    /**
     * Hands back everything still queued for one host as a deferral.
     *
     * @param array<string, array<int, array<string, mixed>>> $queues The waiting
     *                                                                tasks, per
     *                                                                host.
     * @param array<string, Verdict> $verdicts The verdicts so far, added to.
     * @param string $host The host.
     * @param int|null $retryAfterSeconds Roughly how long to leave it.
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0-beta.8
     */
    private function _deferHost(array &$queues, array &$verdicts, string $host, ?int $retryAfterSeconds): void
    {
        foreach ($queues[$host] ?? [] as $task) {
            $verdicts[(string)$task['url']] = $this->_deferred(Verdict::REASON_HOST_BACKOFF, $retryAfterSeconds);
        }

        unset($queues[$host]);
    }

    /**
     * How many requests to one host fit in the waiting budget.
     *
     * The first request is free; every one after it waits out the gap.
     *
     * @param int $gapMs The host's gap between requests, in milliseconds.
     * @param float $budget The run's waiting budget, in seconds.
     * @return int How many to send.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0-beta.8
     */
    private function _fitCount(int $gapMs, float $budget): int
    {
        if ($gapMs <= 0) {
            return PHP_INT_MAX;
        }

        return 1 + (int)floor($budget * 1000 / $gapMs);
    }
}
