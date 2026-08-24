# Release Notes for Link Audit

## 1.0.0-beta.3 - 2026-08-24

### Added
- Running scans can now be cancelled, from the Stop button on the Overview or with `php craft link-audit/scan/cancel`. Cancelling releases the run's remaining queue jobs, marks the scan `cancelled`, and frees the one-scan-at-a-time lock immediately, so a new scan can start straight away. Verdicts already recorded are kept; content the run never reached is picked up by the next scan.
- New `php craft link-audit/scan/reset` console command for a clean rebuild, for instance after changing which element types or sources get scanned. It cancels any running scan, then clears all stored URLs, references, scan history and per-host throttle state. Ignore decisions survive: a dismissed URL is recreated as ignored the moment a scan rediscovers it. Prompts for confirmation; pass `--force` to skip the prompt in scripts.

## 1.0.0-beta.2 - 2026-08-24

### Fixed
- Internal URLs that match no element and no route are now verified over HTTP instead of being marked broken from the database alone. Request-time redirects, whether from Retour, an `.htaccess` rewrite or a CDN rule, now report as redirects with their status code and final destination, and an internal URL that really is gone reports the response code the server itself returned. Own-host requests go through the same per-host throttling as everything else.

## 1.0.0-beta.1 - 2026-08-17

### Added
- Initial release.
