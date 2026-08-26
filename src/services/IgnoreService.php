<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\helpers\BotBlockHeuristics;
use johnhenry\linkaudit\helpers\UrlNormaliser;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\SettingsModel;
use johnhenry\linkaudit\models\Verdict;
use johnhenry\linkaudit\records\IgnoreRecord;
use johnhenry\linkaudit\records\ReferenceRecord;
use johnhenry\linkaudit\records\UrlRecord;
use yii\base\Component;

/**
 * The decisions an author makes about links the plugin should stop reporting.
 *
 * Two kinds of ignore, and they are not the same thing at all.
 *
 * A row in the ignores table is editorial: somebody looked at a broken link,
 * decided it does not matter, and said so. That decision has a name and a date
 * on it, it shows up on the ignored screen, and it is undone by restoring the
 * URL and nothing else. It is kept in its own table precisely because reference
 * rows are rebuilt on every extraction, so a decision stored on one would
 * evaporate at the next scan.
 *
 * A rule in the settings is a policy: a host or a pattern nobody wants checked,
 * written once and applied to everything that matches it, before and after. A
 * rule has no row and no author, so it is not listed on the ignored screen, and
 * it is edited in the settings rather than restored.
 *
 * Where they apply differs too, and deliberately. A rule is consulted as links
 * are extracted and again as URLs come due, so adding one today quiets URLs that
 * were stored months ago the next time each one is looked at. A table row is
 * written onto the URL there and then, so it takes effect the moment the button
 * is pressed.
 *
 * Precedence does not arise: either one is enough to stop a URL being checked,
 * and neither overrules the other. Two things follow that are worth knowing.
 * Taking a rule out of the settings does not by itself un-ignore the URLs it
 * already quieted, since the verdict is a row on the URL and nothing rechecks a
 * row it has been told to leave alone: restore those to put them back in the
 * queue. And restoring a URL a rule still matches puts it back for one pass
 * only, after which the rule quiets it again, which is the rule doing exactly
 * what it was written to do.
 *
 * The reason on the URL row says which of the two it was, so nobody has to
 * guess: {@see Verdict::REASON_IGNORED} for a person and
 * {@see Verdict::REASON_IGNORE_RULE} for a rule, the latter naming the rule in
 * its message.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class IgnoreService extends Component
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var string The scope value on a row that names one URL by its hash.
     */
    public const SCOPE_URL = 'url';

    /**
     * @var int The width of the ignores table's `value` column.
     */
    private const _MAX_VALUE = 500;

    // =========================================================================
    // Private Properties
    // =========================================================================

    /**
     * @var array<string, bool>|null The hashes carrying an ignore row, keyed by
     * hash. Read once and held, since an extraction asks about every new URL it
     * meets and the table holds one row per decision an author has made.
     */
    private ?array $_hashes = null;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Forgets the memoised ignore rows.
     *
     * For the tests, and for a long running worker that has just written one.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function flush(): void
    {
        $this->_hashes = null;
    }

    /**
     * Every URL an author has ignored, newest decision first.
     *
     * The decision itself is not scoped to a site: it is made about a URL and it
     * holds wherever that URL turns up. The screen says as much. What can be
     * scoped is who gets shown it, and that is what `$siteIds` is for: the
     * restore button on this screen posts to an action fenced by the reader's
     * own sites, so a row that action would refuse has no business being drawn.
     *
     * A decision whose URL row is gone is listed either way. There is nothing
     * left to fence it by, and the row carries no address to leak: the screen
     * shows the hash and says nothing points at it any more.
     *
     * @param int $limit The most rows to return.
     * @param int[]|null $siteIds The sites the reader may see, or null for every
     *                            decision on the installation.
     * @return array<int, array<string, mixed>> The rows, each carrying the URL,
     *                                          the note, when it was made and who
     *                                          made it.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function ignoredUrls(int $limit = 200, ?array $siteIds = null): array
    {
        $query = (new Query())
            ->select([
                'id' => 'i.id',
                'urlHash' => 'i.urlHash',
                'note' => 'i.note',
                'dateIgnored' => 'i.dateCreated',
                'userId' => 'i.userId',
                'username' => 'users.username',
                'fullName' => 'users.fullName',
                'url' => 'u.url',
                'host' => 'u.host',
                'value' => 'i.value',
            ])
            ->from(['i' => IgnoreRecord::tableName()])
            ->leftJoin(['u' => UrlRecord::tableName()], '[[u.urlHash]] = [[i.urlHash]]')
            ->leftJoin(['users' => Table::USERS], '[[users.id]] = [[i.userId]]')
            ->where(['i.scope' => self::SCOPE_URL]);

        if ($siteIds !== null) {
            $references = (new Query())
                ->select(['r.id'])
                ->from(['r' => ReferenceRecord::tableName()])
                ->where('[[r.urlId]] = [[u.id]]')
                ->andWhere(['r.siteId' => $siteIds]);

            $query->andWhere(['or', ['u.id' => null], ['exists', $references]]);
        }

        return $query
            ->orderBy(['i.dateCreated' => SORT_DESC, 'i.id' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    /**
     * How many dismissals the Ignored screen would list.
     *
     * The same scoping {@see self::ignoredUrls()} applies, so the number on
     * the navigation badge is the number of rows behind the link: decisions
     * about URLs the given sites point at, plus decisions whose URL row has
     * gone, which are listed for everybody since there is nothing left to
     * scope them by.
     *
     * @param int[]|null $siteIds The sites whose content scopes the answer, or
     *                            null for every decision.
     * @return int The count.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function ignoredCount(?array $siteIds = null): int
    {
        $query = (new Query())
            ->from(['i' => IgnoreRecord::tableName()])
            ->leftJoin(['u' => UrlRecord::tableName()], '[[u.urlHash]] = [[i.urlHash]]')
            ->where(['i.scope' => self::SCOPE_URL]);

        if ($siteIds !== null) {
            $references = (new Query())
                ->select(['r.id'])
                ->from(['r' => ReferenceRecord::tableName()])
                ->where('[[r.urlId]] = [[u.id]]')
                ->andWhere(['r.siteId' => $siteIds]);

            $query->andWhere(['or', ['u.id' => null], ['exists', $references]]);
        }

        return (int)$query->count();
    }

    /**
     * Records an author's decision to stop reporting one URL, and takes it out
     * of the lists there and then.
     *
     * The URL row is flipped rather than deleted, and its schedule is cleared:
     * an ignored URL is never checked again, so leaving a due date on it would
     * only be something for a later query to trip over. What the last check
     * learned is left where it is, so the detail page can still say what was
     * wrong with the URL when somebody decided to let it go.
     *
     * @param string $urlHash The sha1 of the normalised URL.
     * @param string|null $note Why, in the author's own words.
     * @param int|null $userId Who decided.
     * @return bool Whether it was ignored. False means no URL has been seen with
     *              that hash, so there is nothing to ignore.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function ignoreUrl(string $urlHash, ?string $note = null, ?int $userId = null): bool
    {
        $urlId = $this->_urlIdByHash($urlHash);

        if ($urlId === null) {
            return false;
        }

        $note = $note !== null ? trim($note) : null;
        $now = Db::prepareDateForDb(DateTimeHelper::now());
        $existing = $this->_rowIdFor($urlHash);

        if ($existing !== null) {
            Db::update(IgnoreRecord::tableName(), [
                'note' => $note !== '' ? $note : null,
                'userId' => $userId,
                'dateUpdated' => $now,
            ], ['id' => $existing]);
        } else {
            Db::insert(IgnoreRecord::tableName(), [
                'scope' => self::SCOPE_URL,
                'urlHash' => $urlHash,
                'value' => $this->_truncate($this->_urlFor($urlId)),
                'note' => $note !== '' ? $note : null,
                'userId' => $userId,
            ]);
        }

        // The status and the schedule, and nothing else. The code, the message
        // and the dates the last check left behind are the history of why
        // somebody ignored it, and they stay on the row: an ignored URL is not a
        // URL nobody ever learned anything about.
        Db::update(UrlRecord::tableName(), [
            'status' => UrlStatus::Ignored->value,
            'reason' => Verdict::REASON_IGNORED,
            'nextCheckAfter' => null,
        ], ['id' => $urlId]);

        $this->flush();
        LinkAudit::$plugin->getReportService()->invalidateCounts();

        return true;
    }

    /**
     * Whether an author has ignored the URL with this hash.
     *
     * The table only. Settings rules are matched on the URL itself, so a caller
     * holding nothing but a hash cannot ask about them.
     *
     * @param string $urlHash The sha1 of the normalised URL.
     * @return bool Whether there is a row for it.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function isHashIgnored(string $urlHash): bool
    {
        return isset($this->_ignoredHashes()[$urlHash]);
    }

    /**
     * Whether a URL is ignored, by either an author's decision or a settings
     * rule.
     *
     * @param string $url The normalised URL.
     * @param string|null $urlHash Its hash, when the caller already has it.
     * @return bool Whether the URL should be left alone.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function isIgnored(string $url, ?string $urlHash = null): bool
    {
        if ($this->isHashIgnored($urlHash ?? UrlNormaliser::hash($url))) {
            return true;
        }

        return $this->ruleFor($url) !== null;
    }

    /**
     * Undoes an author's decision, and puts the URL back in the queue.
     *
     * Pending rather than whatever it held before it was ignored: the verdict it
     * carried then may be months old, and the first thing anybody restoring a URL
     * wants to know is whether it is still broken.
     *
     * Works on a URL quieted by a settings rule as well as on one somebody
     * ignored by hand, since from the reader's side they look the same and both
     * are put back the same way. A rule that still matches will quiet it again on
     * its next check, which is the rule doing its job.
     *
     * @param string $urlHash The sha1 of the normalised URL.
     * @return bool Whether anything was put back. False means the URL was not
     *              ignored in the first place.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function restoreUrl(string $urlHash): bool
    {
        $deleted = Db::delete(IgnoreRecord::tableName(), [
            'scope' => self::SCOPE_URL,
            'urlHash' => $urlHash,
        ]);

        $this->flush();

        $restored = Db::update(UrlRecord::tableName(), [
            'status' => UrlStatus::Pending->value,
            'nextCheckAfter' => null,
        ], ['urlHash' => $urlHash, 'status' => UrlStatus::Ignored->value]);

        LinkAudit::$plugin->getReportService()->invalidateCounts();

        return $deleted > 0 || $restored > 0;
    }

    /**
     * The settings rule that quiets a URL, when one does.
     *
     * @param string $url The normalised URL.
     * @return string|null The rule as the author wrote it, for the message on the
     *                     URL row, or null when nothing matches.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function ruleFor(string $url): ?string
    {
        $settings = $this->_settings();
        $host = UrlNormaliser::hostOf($url);

        foreach ($this->_values($settings->ignoreHosts, 'host') as $rule) {
            if ($host !== '' && BotBlockHeuristics::matchesHostList($host, [strtolower($rule)])) {
                return $rule;
            }
        }

        foreach ($this->_values($settings->ignorePatterns, 'pattern') as $rule) {
            if ($this->_matchesPattern($rule, $url)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * The verdict for a URL that is not to be checked, when it is one.
     *
     * The check phase's gate. Answering with a verdict rather than a boolean
     * keeps the reason with the decision, so the URL row says whether it was a
     * person or a rule that quieted it, and names the rule when it was a rule.
     *
     * @param string $url The normalised URL.
     * @param string|null $urlHash Its hash, when the caller already has it.
     * @return Verdict|null The verdict, or null when the URL is to be checked as
     *                      normal.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function verdictFor(string $url, ?string $urlHash = null): ?Verdict
    {
        if ($this->isHashIgnored($urlHash ?? UrlNormaliser::hash($url))) {
            return new Verdict(
                status: UrlStatus::Ignored,
                reason: Verdict::REASON_IGNORED,
                message: Craft::t('link-audit', 'Somebody decided this one does not need checking.'),
            );
        }

        $rule = $this->ruleFor($url);

        if ($rule === null) {
            return null;
        }

        return new Verdict(
            status: UrlStatus::Ignored,
            reason: Verdict::REASON_IGNORE_RULE,
            message: Craft::t('link-audit', 'Left alone by the ignore rule {rule}.', ['rule' => $rule]),
        );
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Every hash carrying an ignore row, keyed by hash.
     *
     * @return array<string, bool> The hashes.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _ignoredHashes(): array
    {
        if ($this->_hashes !== null) {
            return $this->_hashes;
        }

        $hashes = (new Query())
            ->select(['urlHash'])
            ->from([IgnoreRecord::tableName()])
            ->where(['scope' => self::SCOPE_URL])
            ->andWhere(['not', ['urlHash' => null]])
            ->column();

        $this->_hashes = array_fill_keys(array_map('strval', $hashes), true);

        return $this->_hashes;
    }

    /**
     * Tests a URL against one ignore pattern.
     *
     * A pattern is a regular expression, delimited here so slashes in it need no
     * escaping. A malformed one makes preg_match return false rather than 1, so
     * it simply matches nothing, and the scoped error handler swallows the
     * warning without reaching for the @ operator. The same shape
     * {@see ScanService::isUriExcluded()} uses, for the same reasons.
     *
     * @param string $pattern The pattern from the settings.
     * @param string $url The normalised URL.
     * @return bool Whether it matches.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _matchesPattern(string $pattern, string $url): bool
    {
        $delimited = '~' . str_replace('~', '\~', $pattern) . '~i';
        set_error_handler(static fn(): bool => true);

        try {
            return preg_match($delimited, $url) === 1;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * The id of the ignore row for a hash.
     *
     * @param string $urlHash The sha1 of the normalised URL.
     * @return int|null The row id, or null when there is none.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _rowIdFor(string $urlHash): ?int
    {
        $id = (new Query())
            ->select(['id'])
            ->from([IgnoreRecord::tableName()])
            ->where(['scope' => self::SCOPE_URL, 'urlHash' => $urlHash])
            ->scalar();

        return $id !== false && $id !== null ? (int)$id : null;
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
     * Clips a value to the width of the column it is bound for.
     *
     * @param string|null $value The value to clip.
     * @return string|null The clipped value.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _truncate(?string $value): ?string
    {
        return $value !== null ? mb_substr($value, 0, self::_MAX_VALUE) : null;
    }

    /**
     * The URL a row holds.
     *
     * Copied onto the ignore row so the ignored screen can still name the URL
     * after the row it was about has been pruned as an orphan.
     *
     * @param int $urlId The URL row.
     * @return string|null The URL, or null when the row is gone.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _urlFor(int $urlId): ?string
    {
        $url = (new Query())
            ->select(['url'])
            ->from([UrlRecord::tableName()])
            ->where(['id' => $urlId])
            ->scalar();

        return $url !== false && $url !== null ? (string)$url : null;
    }

    /**
     * The id of the URL row with a given hash.
     *
     * @param string $urlHash The sha1 of the normalised URL.
     * @return int|null The row id, or null when the URL has not been seen.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _urlIdByHash(string $urlHash): ?int
    {
        $id = (new Query())
            ->select(['id'])
            ->from([UrlRecord::tableName()])
            ->where(['urlHash' => $urlHash])
            ->scalar();

        return $id !== false && $id !== null ? (int)$id : null;
    }

    /**
     * Flattens an editable table setting into the values it holds.
     *
     * Reads the named column when the row has one and falls back to the first
     * string in the row when it does not, so a setting written by hand in
     * `config/link-audit.php` as a plain list of strings works as well as one
     * saved from the control panel.
     *
     * @param array<int, mixed> $rows The setting value.
     * @param string $column The column holding the rule.
     * @return string[] The rules, trimmed, with the blanks and the disabled rows
     *                  dropped.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _values(array $rows, string $column): array
    {
        $values = [];

        foreach ($rows as $row) {
            if (is_string($row)) {
                $values[] = trim($row);

                continue;
            }

            if (!is_array($row) || !(bool)($row['enabled'] ?? true)) {
                continue;
            }

            $value = $row[$column] ?? null;

            if (!is_string($value)) {
                continue;
            }

            $values[] = trim($value);
        }

        return array_values(array_filter($values, static fn(string $value): bool => $value !== ''));
    }
}
