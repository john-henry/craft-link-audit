<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\LinkAudit;
use yii\base\InvalidConfigException;
use yii\console\Exception as ConsoleException;
use yii\console\ExitCode;

/**
 * Writes a list screen out as a CSV, without a browser involved.
 *
 * Usage:
 *   php craft link-audit/export/csv --status=broken --file=broken.csv
 *   php craft link-audit/export/csv --status=redirect --site=default --file=/tmp/redirects.csv
 *
 * The same file the Download CSV button hands over, from the same generator, so
 * a report mailed out by a cron job and one an editor downloaded are the same
 * thing. One row per reference: a URL appearing on three pages is three rows.
 *
 * There is no site fence here, and that is deliberate. The control panel fences
 * an export by the sites the reader may edit, because a reader is a person with
 * permissions; a console command is somebody already on the server with a shell,
 * and fencing them off from their own database would be theatre. Left to itself
 * this covers every site on the installation. Pass `--site` to keep it to one.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class ExportController extends Controller
{
    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @var string The default action.
     */
    public $defaultAction = 'csv';

    /**
     * @var string|null Where to write the file. Required.
     */
    public ?string $file = null;

    /**
     * @var string|null The handle of the site to cover. Every site when it is
     * not given.
     */
    public ?string $site = null;

    /**
     * @var string The verdict to export, as one of the stored status values.
     */
    public string $status = 'broken';

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Writes one verdict's references to a CSV file.
     *
     * @return int The exit code.
     * @throws ConsoleException If the site handle does not belong to a site.
     * @throws InvalidConfigException If the export service cannot be resolved.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function actionCsv(): int
    {
        $status = UrlStatus::tryFrom($this->status);

        if ($status === null) {
            $this->stderr(sprintf(
                "'%s' is not a verdict. Pass one of: %s.\n",
                $this->status,
                implode(', ', array_map(static fn(UrlStatus $case): string => $case->value, UrlStatus::cases())),
            ), Console::FG_RED);

            return ExitCode::USAGE;
        }

        $path = trim((string)$this->file);

        if ($path === '') {
            $this->stderr("Pass --file with somewhere to write to.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        // Asked before opening rather than found out by failing, so a mistyped
        // path is a sentence saying what is wrong with it rather than a PHP
        // warning in the middle of the output.
        if (!is_writable(file_exists($path) ? $path : dirname($path))) {
            $this->stderr("Cannot write to $path.\n", Console::FG_RED);

            return ExitCode::IOERR;
        }

        $handle = fopen($path, 'w');

        if ($handle === false) {
            $this->stderr("Could not open $path for writing.\n", Console::FG_RED);

            return ExitCode::IOERR;
        }

        $export = LinkAudit::$plugin->getExportService();
        $siteIds = $this->_siteIds();
        $rows = $export->rowCount($status, $siteIds);

        foreach ($export->csv($status, $siteIds) as $chunk) {
            fwrite($handle, $chunk);
        }

        fclose($handle);

        $this->stdout(
            sprintf("Wrote %d %s to %s.\n", $rows, $rows === 1 ? 'row' : 'rows', $path),
            Console::FG_GREEN,
        );

        return ExitCode::OK;
    }

    /**
     * @inheritdoc
     *
     * @param string $actionID The action being run.
     * @return string[] The options it takes.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'csv' => ['file', 'site', 'status'],
            default => [],
        });
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * The sites the command was pointed at.
     *
     * @return int[] The site ids, or every one of them when no handle was given.
     * @throws ConsoleException If the handle does not belong to a site. Better
     *                          than quietly covering every site, which is not
     *                          what anybody who typed a handle wanted.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _siteIds(): array
    {
        $sites = Craft::$app->getSites();

        if ($this->site === null) {
            return array_map(static fn(mixed $id): int => (int)$id, $sites->getAllSiteIds());
        }

        $site = $sites->getSiteByHandle($this->site);

        if ($site === null) {
            throw new ConsoleException("No site with the handle '$this->site'.");
        }

        return [(int)$site->id];
    }
}
