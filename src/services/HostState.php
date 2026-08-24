<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\services;

use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\records\HostRecord;
use yii\base\Component;

/**
 * Remembers how each domain has been treating us, and how it should be treated
 * in return.
 *
 * Durable rather than per-scan, because politeness that is forgotten between
 * runs is not politeness. A host that answered 429 last night is approached
 * gently tonight, a host that has refused every connection for an hour is left
 * out of this pass entirely, and a host that turns robots away is marked so the
 * next scan already knows.
 *
 * Rows are memoised for the life of the process, since a batch of five hundred
 * links usually covers a few dozen domains and none of it is worth a query per
 * URL. {@see self::flush()} exists for the test suite, which runs many cases in
 * one process.
 *
 * Dates go through `DateTimeHelper` throughout: everything here is a row on its
 * way in or out of the database.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class HostState extends Component
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var int How many consecutive failures a host is allowed before it is left
     * out of a pass altogether.
     */
    private const _BACKOFF_AFTER_FAILURES = 3;

    /**
     * @var int The first backoff window in seconds, doubled for each further
     * failure.
     */
    private const _BACKOFF_BASE_SECONDS = 60;

    /**
     * @var int The longest a host is ever set aside for, however rudely it asked.
     * A Retry-After of a day is not worth honouring inside one scan.
     */
    private const _MAX_BLOCK_SECONDS = 900;

    /**
     * @var int The largest gap that can be learned from a 429, so one impatient
     * host cannot bring a whole scan to a halt.
     *
     * The window the host asked for is honoured in full through
     * {@see self::$_states}' `blockedUntil`, which costs nothing: the host is
     * simply left out of the pass. The learned gap is a different thing
     * altogether, because it is paid per request by a process that is sitting
     * there waiting, and a `Retry-After` of fifteen minutes learned as a
     * fifteen minute gap is a chunk of twenty five URLs taking six hours.
     */
    private const _MAX_LEARNED_DELAY_MS = 30000;

    // =========================================================================
    // Private Properties
    // =========================================================================

    /**
     * @var array<string, array<string, mixed>> Host rows read this process, keyed
     * by host.
     */
    private array $_states = [];

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * When a host may be approached again, if it is being left alone at all.
     *
     * @param string $host The host.
     * @return DateTime|null The end of the backoff window, or null when there is
     *                       none.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function blockedUntil(string $host): ?DateTime
    {
        $stored = $this->_state($host)['blockedUntil'];

        if ($stored === null) {
            return null;
        }

        $date = DateTimeHelper::toDateTime($stored);

        return $date !== false ? $date : null;
    }

    /**
     * Forgets every memoised host row.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function flush(): void
    {
        $this->_states = [];
    }

    /**
     * Whether a host is inside a backoff window and should be left out of this
     * pass.
     *
     * @param string $host The host.
     * @return bool Whether to leave it alone.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function isBlocked(string $host): bool
    {
        $until = $this->blockedUntil($host);

        return $until !== null && $until->getTimestamp() > DateTimeHelper::now()->getTimestamp();
    }

    /**
     * Whether a host is known to turn automated requests away.
     *
     * @param string $host The host.
     * @return bool Whether it is bot hostile.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function isBotHostile(string $host): bool
    {
        return (bool)$this->_state($host)['botHostile'];
    }

    /**
     * Records that a host turns automated requests away.
     *
     * @param string $host The host.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function markBotHostile(string $host): void
    {
        if ($this->isBotHostile($host)) {
            return;
        }

        $this->_write($host, ['botHostile' => true]);
    }

    /**
     * The shortest gap allowed between two requests to a host: the configured
     * floor, or whatever the host itself has asked for, whichever is longer.
     *
     * @param string $host The host.
     * @return int The gap in milliseconds.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function minDelayMs(string $host): int
    {
        $configured = max(0, LinkAudit::$plugin->getSettings()->minHostDelayMs);
        $learned = $this->_state($host)['minDelayMs'];

        return $learned !== null ? max($configured, (int)$learned) : $configured;
    }

    /**
     * Records a request the host could not answer.
     *
     * A run of these earns a backoff window that doubles each time, so a domain
     * that has gone down stops costing the scan anything.
     *
     * @param string $host The host.
     * @param int|null $httpStatus The status code, when there was one.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function recordFailure(string $host, ?int $httpStatus = null): void
    {
        $failures = (int)$this->_state($host)['consecutiveFailures'] + 1;

        $columns = [
            'consecutiveFailures' => $failures,
            'lastStatus' => $httpStatus,
            'dateLastRequest' => Db::prepareDateForDb(DateTimeHelper::now()),
        ];

        if ($failures >= self::_BACKOFF_AFTER_FAILURES) {
            $seconds = min(
                self::_MAX_BLOCK_SECONDS,
                self::_BACKOFF_BASE_SECONDS * (2 ** ($failures - self::_BACKOFF_AFTER_FAILURES)),
            );

            $columns['blockedUntil'] = Db::prepareDateForDb(
                DateTimeHelper::now()->modify('+' . (int)$seconds . ' seconds'),
            );
        }

        $this->_write($host, $columns);
    }

    /**
     * Records a host asking to be approached less often, and honours it.
     *
     * The window it asked for is honoured in full: the host is set aside for
     * the rest of this pass and nothing is asked of it. The gap learned for the
     * next pass is capped well below that, because a gap is waited out per
     * request rather than skipped, and it decays again with every good answer
     * afterwards.
     *
     * @param string $host The host.
     * @param int|null $retryAfterSeconds What the host asked for, when it said.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function recordRateLimit(string $host, ?int $retryAfterSeconds = null): void
    {
        $seconds = min(self::_MAX_BLOCK_SECONDS, max(1, $retryAfterSeconds ?? 60));
        $learned = min(self::_MAX_LEARNED_DELAY_MS, $seconds * 1000);
        $current = (int)($this->_state($host)['minDelayMs'] ?? 0);

        $this->_write($host, [
            'minDelayMs' => max($current, $learned),
            'lastStatus' => 429,
            'blockedUntil' => Db::prepareDateForDb(
                DateTimeHelper::now()->modify('+' . $seconds . ' seconds'),
            ),
            'dateLastRequest' => Db::prepareDateForDb(DateTimeHelper::now()),
        ]);
    }

    /**
     * Records a request the host answered, which clears whatever it owed us.
     *
     * A learned gap is halved each time, and dropped altogether once it is back
     * down to the configured floor. Without that the gap would only ever ratchet
     * upwards, so one bad afternoon two years ago would still be costing every
     * scan since, and a host that has answered ten thousand requests happily
     * would still be approached at whatever pace it once asked for.
     *
     * @param string $host The host.
     * @param int|null $httpStatus The status code, when there was one.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function recordSuccess(string $host, ?int $httpStatus = null): void
    {
        $columns = [
            'consecutiveFailures' => 0,
            'lastStatus' => $httpStatus,
            'blockedUntil' => null,
            'dateLastRequest' => Db::prepareDateForDb(DateTimeHelper::now()),
        ];

        $learned = $this->_state($host)['minDelayMs'];

        if ($learned !== null) {
            $columns['minDelayMs'] = $this->_decayed((int)$learned);
        }

        $this->_write($host, $columns);
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * A learned gap, halved for one good answer.
     *
     * @param int $learned The gap the host has been getting.
     * @return int|null The gap it gets next, or null once it is back down to
     *                  the configured floor and there is nothing left to
     *                  remember.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _decayed(int $learned): ?int
    {
        $configured = max(0, LinkAudit::$plugin->getSettings()->minHostDelayMs);
        $halved = (int)floor($learned / 2);

        return $halved > $configured ? $halved : null;
    }

    /**
     * The stored state for a host, with sensible blanks for one never seen
     * before.
     *
     * @param string $host The host.
     * @return array<string, mixed> The state.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _state(string $host): array
    {
        $host = strtolower(trim($host));

        if (isset($this->_states[$host])) {
            return $this->_states[$host];
        }

        $row = $host !== ''
            ? (new Query())
                ->select(['consecutiveFailures', 'lastStatus', 'minDelayMs', 'blockedUntil', 'botHostile'])
                ->from([HostRecord::tableName()])
                ->where(['host' => $host])
                ->one()
            : null;

        return $this->_states[$host] = is_array($row) ? $row : [
            'consecutiveFailures' => 0,
            'lastStatus' => null,
            'minDelayMs' => null,
            'blockedUntil' => null,
            'botHostile' => false,
        ];
    }

    /**
     * Writes columns to a host row, creating it if this is the first time the
     * domain has come up.
     *
     * @param string $host The host.
     * @param array<string, mixed> $columns The columns to write.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _write(string $host, array $columns): void
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return;
        }

        $state = $this->_state($host);

        Db::upsert(HostRecord::tableName(), ['host' => $host] + $columns, $columns);

        $this->_states[$host] = $columns + $state;
    }
}
