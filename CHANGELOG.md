# Release Notes for Link Audit

## 1.0.0-beta.5 - 2026-08-24

### Fixed
- Element links (Link fields, Hyper links, entry reference tags) pointing at a disabled entry that still has a URL are now verified over HTTP instead of being reported broken from the element lookup alone. The rendered href is the target's URL whether the entry is enabled or not, so a redirect covering a retired page now reports as the redirect it is, with its destination. This closes the element-link half of the beta.4 fix: a disabled target with no URL, and a disabled relation target, still report broken from the lookup, since no request could answer for those.
- The Overview no longer claims nothing has been scanned when a run is stopped before any scan has completed. A stopped run now counts as the last scan: its pane says it was stopped early and its counts cover what it got through.
- The Stop button on the running scan pane is now a plain button on an opaque base, with a bit more room above it. The red caution styling fought the pane's blue tint and oversold the action: stopping a scan loses nothing, everything already checked stays on the report.

## 1.0.0-beta.4 - 2026-08-24

### Fixed
- Internal URLs held by a disabled element are now verified over HTTP like any other address the database cannot vouch for, instead of being reported broken from the disabled match alone. A redirect covering a retired page, the usual housekeeping when an entry is disabled and a Retour rule takes over its address, now reports as the redirect it is, with its destination.

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
