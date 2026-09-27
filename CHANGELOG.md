# Release Notes for Link Audit

## 1.0.0-beta.8 - 2026-09-25

### Added
- A Getting started pane on the Overview, shown until the first scan has run.
- A Using the Dashboard guide in the docs, with a short video.
- An Excluded Fields setting on the Scanning tab, for fields holding links nobody is going to fix, like a legacy body kept after a migration. You can search the list by field name or handle.
- Links in Commerce variants are now read along with their product.
- Support for Navigation 4, as well as Navigation 3.

### Changed
- The private-address check now comes from the shared `johnhenry/craft-ip-guard` package.
- Permission handles are now kebab-case: `link-audit:view-reports`, `link-audit:run-scans` and `link-audit:manage-ignores`. A migration carries over existing grants; update any code that checks the old handles.
- Excluded URI Patterns now store the site by UID, so a row means the same site in every environment. A migration converts existing rows; in `config/link-audit.php`, use `siteUid` instead of `siteId`.
- Relative links in content now resolve against the page they sit on, the way a browser reads them.
- Links to scheduled or expired entries are now checked over HTTP rather than taken as working.
- A HEAD request answered with 404 or 410 is now confirmed with a GET before the link is called broken.
- A 401 or 407 answer now shows as Unverifiable rather than Broken.
- Check this page again now only checks that page's links, and sends no notifications.
- A host with a long gap between requests now gets as many checks as fit in a run, rather than none.
- The check step stops starting requests after a set time and saves each result as it lands, so one slow host can't stall a scan.
- The Overview's last scan pane no longer shows single-page rechecks.
- Old scan history and unused host data are now pruned during Craft's garbage collection too.
- Check again and Restore no longer retry inside the web request.

### Fixed
- The example config file now shows the five editable tables.
- A Broken Links widget that can't render no longer takes the whole dashboard down.
- `link-audit/scan/report` no longer accepts a `--site` option it ignored.
- IPv4 addresses written inside IPv6 ones (mapped, NAT64, 6to4) are now caught by the address guard, along with multicast, broadcast and documentation ranges.
- The recheck and retention windows are now capped, so a huge value can't break scheduling.
- The page crawl step no longer restarts itself on a slow site.
- The Connect Timeout setting now applies to the rendered crawl.
- The URL detail page now highlights the list it was opened from in the sidebar.
- The settings now fire Craft's `defineRules` event, so modules can add validation.
- New rows in Ignored URL Patterns, Ignored Hosts and Excluded URI Patterns are switched on by default.
- The Excluded URI Patterns help now gives `^$` for the homepage.
- Fixed stray carriage returns in CSV exports on Windows.
- `link-audit/scan/element` now says when the element doesn't exist or is excluded.
- Invalid regular expressions are now rejected when saving pattern settings.
- The Ignored badge now updates when the orphan prune removes an ignored URL.
- Two checks landing on the same link at once no longer lose a failure count.
- A failure to queue a reread can no longer fail a save or restore.
- A link removed from a Matrix block, nested entry or navigation node now leaves the report when the page is read again.
- Links saved while a full scan was running are no longer removed when that scan finishes.
- Links ignored because of a setting, such as Check Internal Links being off, come back once the setting changes.
- Saving a settings tab no longer copies values from `config/link-audit.php` into project config.
- A scan that fails is now marked as failed, and the Overview stops waiting on it.
- Stop now cancels every running scan, including one whose worker died.
- Deleting or disabling entries during a full scan no longer makes it skip others.
- Incremental scans no longer miss edits on a site left out of the last single-site scan.
- Links to a page that's deleted, or whose URI changes, are now rechecked on the next run.
- Anchor IDs made of digits, like `id="2024"`, are no longer reported missing.
- The rendered crawl now skips excluded sections and category groups.
- Disabling an entry now takes its links off the report straight away.
- The links panel now shows while an entry is being edited.
- The broken and permanent redirect counts on the Broken Links widget now announce their label along with the number.
- Clicking a broken link in the sidebar panel now moves focus to it on the page and announces what was found, or that it couldn't be found.

### Security
- Navigation node URLs are no longer expanded from environment variables, which let a navigation editor send server secrets to an outside address.
- Fixed a stored XSS through the Host column on the list screens.
- Checks now connect to the exact address that passed the private-network check, on every redirect too, closing a DNS rebinding gap.
- The own-site exemption now needs the site's exact scheme, host and port, and is off when `@web` is taken from the request.
- A huge `Retry-After` header can no longer stop the check step.
- Response bodies are capped and every request has a total time limit, so a slow or oversized answer can't hold a worker or fill the disk.
- Report screens, the CSV export and the links panel no longer show the title, link text or field of a page the reader can't view.
- Usernames and passwords are now stripped from links with schemes the plugin doesn't check.
- Slack notifications no longer follow redirects.

## 1.0.0-beta.7 - 2026-08-26

### Added
- Edit links on the URL detail page now open the right tab and scroll to the exact link.
- `link-audit/scan/recheck-url` checks one address straight away, and `link-audit/scan/recheck-broken --all` brings every link forward for a check.

### Changed
- CSV exports leave out the redirect columns except on the Redirects list, and are named after the list.
- Longer settings help moved into info tips.
- The Settings link is hidden where admin changes are turned off.
- The list screens now work on a phone.
- The host filter shows how many URLs each host has.
- The settings tabs no longer open with a warning-styled note.

### Removed
- The guided tour, and the bundled Driver.js library.

### Fixed
- Broken-link emails now name element links by title and link each one to its report page.
- Check this page again and rescans no longer send notifications for links that were already broken.
- A very long link no longer fails a scan.
- The page recheck endpoint now checks the element exists and the reader may view it, and list screens cap the page size.
- The Ignored screen names element links by title.
- Hovering a URL on the list screens now says the click opens its report.
- The sidebar badges no longer vanish on screens with no site context.
- The Ignored badge now counts only what the Ignored screen lists.
- Clearing a number setting no longer saves a zero.

### Security
- Fixed a stored XSS through double quotes in link addresses on the list screens.

## 1.0.0-beta.6 - 2026-08-25

### Added
- A Where to start pane on the Overview, shown while anything is broken.
- A Points At filter on the list screens, for own-site or external links.
- Excluded Sections and Excluded Category Groups settings.
- Edit URL and Page URL columns in the CSV export.

### Changed
- Craft 5.6 or later is now required.
- Links to elements with no URL now show the target's title.
- Edit links now scroll to the field or Matrix block holding the link.
- The entry links panel scrolls to the link in the field, and has a Check this page again button.
- Links in Matrix blocks now name the block type.
- CSV columns now lead with the page and follow with the link. Update anything that parses the old column order.
- The Redirects screen explains its two address columns.
- Read-only settings screens now show Craft's own notice.

## 1.0.0-beta.5 - 2026-08-24

### Changed
- The Stop button is now a plain button.

### Fixed
- Element links to a disabled entry that still has a URL are now checked over HTTP.
- The Overview no longer says nothing was scanned when the first scan was stopped.

## 1.0.0-beta.4 - 2026-08-24

### Fixed
- Internal URLs held by a disabled element are now checked over HTTP, so a redirect over a retired page shows as a redirect.

## 1.0.0-beta.3 - 2026-08-24

### Added
- Running scans can be stopped from the Overview or with `link-audit/scan/cancel`.
- `link-audit/scan/reset` clears all stored results for a clean rebuild, keeping ignore decisions.

## 1.0.0-beta.2 - 2026-08-24

### Fixed
- Internal URLs that match no element or route are now checked over HTTP, so redirects from Retour, rewrites or a CDN show up properly.

## 1.0.0-beta.1 - 2026-08-17

### Added
- Initial release.
