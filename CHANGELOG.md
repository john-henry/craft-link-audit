# Release Notes for Link Audit

## 1.0.0-beta.8 - 2026-09-22

### Added
- A Getting started pane on the Overview, shown until the first scan has run. A fresh install used to
  open on a screen of zeros with nothing on it saying what to do about that. It now walks through the
  three steps in order: run a full scan, leave it to the queue, then work from the top of the report. It
  says plainly that the scan runs in the background, so there is no need to sit watching the page, and
  where your account is not allowed to run scans it says so rather than leaving you wondering where the
  button went.
- A Using the Dashboard guide in the documentation, with a short video walkthrough, linked from that
  pane.

### Fixed
- The example config file now shows the editable tables as well: ignore patterns, ignored hosts, the
  bot-hostile host list, excluded URI patterns and the always-valid internal patterns. All five could
  always be pinned in `config/link-audit.php`, and the control panel has always said so when they were,
  but the file you copy to do it did not show them, and the row shape is not something anybody guesses.
- The Broken Links widget can no longer take the dashboard down with it. Craft asks a widget to draw
  itself without catching anything, so a template that would not render, after a part-finished deploy
  say, stopped the whole dashboard loading for everybody who had the tile, including the page they would
  have used to remove it. The tile now goes quiet on its own and the reason goes in the log.
- `link-audit/scan/report` no longer takes a `--site` it was never going to use. The report covers the
  whole install, but the option was listed all the same, so passing one did nothing and passing a
  mistyped one did nothing quietly: the report came out looking right and the command said it worked.
  It is refused now, the way an option nobody offers should be.
- The address guard now reads an IPv4 address written inside an IPv6 one. There are three ways of doing
  that, and the guard was judging the outside of the address rather than the machine it reaches, so on a
  network carrying NAT64 or 6to4 a link could have been followed to somewhere on your own side of the
  firewall. Multicast, broadcast and the documentation ranges are turned away now as well.
- The recheck and retention windows are now capped as well as floored. They are turned into a length of
  time to work out when a URL is next due, and a number big enough stops being one: the setting saved
  fine and the next check threw instead of being scheduled. Ten years of days and a year of hours, which
  is past anything anybody means by leaving something alone.
- The page crawl step no longer restarts itself on a slow site. It fetches twenty pages one after another
  and the queue holds a job for five minutes by default, which twenty pages at the default timeout
  already goes past. When it did, the queue handed the job on and the same twenty pages were crawled
  again from the top, over and over, while the scan sat still. The step now asks for as long as the
  fetching can actually take.
- The Connect Timeout setting now applies when pages are crawled. The crawler was asking for the response
  a way that quietly has no connect timeout at all, so a host that accepts a connection and then says
  nothing cost the full request timeout on every page instead of the few seconds you set. Nothing else
  about the crawl changes: the same cap on how much of a page is read still applies.
- The URL detail page now keeps its place in the sidebar. Opening a URL from any of the lists left every
  entry in the Link Audit menu unlit, so there was nothing to tell you which list you had come out of.
- The plugin's settings now fire Craft's `defineRules` event, so a module can add its own validation to
  them. The settings model was declaring its rules in a way that skipped the event, so a handler you
  attached to it ran against nothing and your rule was never applied.
- Ignored URL Patterns, Ignored Hosts and Excluded URI Patterns now switch a newly added row on. The "On"
  switch on a fresh row was starting off, so a pattern or host you typed in and saved came back looking
  right and was then passed over by every scan, with nothing to tell you why. Rows you already had are
  untouched.
- The Excluded URI Patterns help said a blank pattern matched the homepage. A blank row was never saved,
  so that was never a thing you could do from the settings screen. The homepage is `^$`, and the help now
  says so.
- Fixed the CSV export writing broken line endings when Craft runs on Windows. The file is written with
  the CRLF endings a spreadsheet expects, but the handle it went through was rewriting them again on the
  way out, so every row ended up with a stray carriage return. Nothing changes on Linux or macOS.
- `link-audit/scan/element` says so when there is no element with that id, or when the one there is has
  been excluded from the audit. It used to report "0 links stored" in green either way, which reads as a
  page that was read and had nothing on it.
- A pattern that is not a valid regular expression is refused when you save it, in all three settings
  that take one: Ignored URL Patterns, Excluded URI Patterns and Always Valid Internal URLs. A pattern
  that will not compile never matches, so until now the row saved without complaint and then quietly did
  nothing: an ignore that ignored nothing went on reporting the links it was written to quiet, and an
  exclusion that excluded nothing went on scanning the pages it was written to leave alone. A row you
  have switched off is left alone, so a half-written rule can still be parked.
- The count beside Ignored is brought up to date when the sweep that removes URLs nothing points at
  removes one that was ignored. Running the prune left the badge showing the old number for up to a
  minute, which read as the command having done nothing.
- A check run now tells the queue how long it may actually need, rather than letting it assume five
  minutes. On a slower setting, a run of a hundred links could take far longer than that, and a job that
  outlives its time to run is handed to the next worker from the start: every one of those links asked
  again, and again, with the run never finishing. Raising the timeout or lowering how many run at once
  no longer has that effect.
- Two checks landing on the same link at the same time no longer lose each other's count of consecutive
  failures. A scheduled scan, a rendered crawl and the Check again button all write the same row, and
  each worked its count out from what it read before the other wrote, which could hold a link one check
  short of being called broken. The write now only lands if the row still holds what was read, and reads
  again if it does not.
- Saving or restoring a page can no longer be refused because the plugin could not queue its reread. The
  hook runs inside the save itself, so anything that went wrong there came back to the author as a
  failed save of their own content. A reread that was not queued is picked up by the next scan. Deleting
  a page already worked this way.

### Changed
- The check that stops a scan fetching a private or internal address, and the name lookup behind it,
  now come out of a small shared package, `johnhenry/craft-ip-guard`, which Accessibility Audit reads
  from as well. What counts as private has not changed, nor has what happens when a name will not
  resolve. They sit on their own so a range added to the list is added for both plugins at once, a gap
  in only one of them being the whole risk. Composer pulls it in on update.

## 1.0.0-beta.7 - 2026-08-26

### Security
- Closed a stored cross-site-scripting hole on the report list screens. An address carrying a double quote, which a browser accepts and an author can paste into a link, was placed into a control-panel attribute through an escaper that does not escape quotes, so a crafted href could run script for anyone opening the list. Attribute values are now escaped in full.

### Added
- Edit links on the URL detail page now land on the very anchor carrying the link, the same precision the entry sidebar panel has: the right tab is opened, the exact link is scrolled to the centre of the screen and flashed, inside a Matrix block or a long rich text field alike. The CSV keeps the plainer field and block fragments, which work for a reader who is not signed in.
- Two new console commands for catching the report up without waiting on recheck windows: `php craft link-audit/scan/recheck-url --url=...` checks one address there and then and prints the verdict, and `php craft link-audit/scan/recheck-broken --all` brings everything but the ignored forward, working links included, the sweep for after a migration or a hosting move.

### Removed
- The guided tour is gone, and the vendored Driver.js library with it. The Where to start pane on the Overview does the same orientation job in context and stays put rather than running once, so the tour had become a maintenance cost with nothing left to teach that the screen does not.

### Changed
- A CSV export leaves off the two redirect columns, Redirect Code and Goes To, on any list but Redirects, where they are always empty, so a broken or unverifiable export no longer carries two blank columns to puzzle over. The download is also named after the verdict as the screen labels it: the Unverifiable list exports as `link-audit-unverifiable-<date>.csv` rather than the `blocked` the database calls it.
- The longer settings explanations moved off the screen and into info tips: each field now leads with one plain sentence, and the rest sits behind the familiar circled i, the way Craft's own settings do it, with nothing cut, only tucked away. The pattern and host columns in the rules tables carry a tip of their own with worked examples, so the regular expression help is beside the box you type it into.
- The Settings link leaves the sidebar on environments where admin changes are turned off, the same rule Craft applies to its own Settings section. The screens stay reachable by URL there, rendered read-only.
- The list screens behave themselves on a phone: the filter dropdowns sit two to a row with Apply and Download CSV full width beneath them, the Host and Last Checked columns step aside so the address and the buttons keep their room, and the Where it appears table on a URL's page scrolls sideways rather than crushing its columns. On a full screen, Apply now looks like the button it is and Download CSV sits at the end of the bar on its own.
- The host filter on the list screens now says how many URLs each host is carrying, `example.com (3)`, so a reader can see where the trouble concentrates before choosing.
- The settings tabs no longer open with a boxed note explaining themselves. The guidance lives in each field's own instructions and in the documentation, and a screen that greets you with a warning-styled paragraph reads like something is wrong when nothing is. The Schedule tab keeps its note, since that one carries the cron lines to copy, and it is now styled as the blue tip it is rather than a red warning.

### Fixed
- The broken-links notification email now names an element link by its target's title instead of the internal marker, and gives each link its own line through to its report page, where the pages carrying it are listed with the edit links that open on the right field.
- The "Check this page again" button, and a rescan, no longer send a notification email or Slack post for links that were already broken. A recheck that finds a standing failure still standing is no longer counted as a fresh break, and a single-page recheck no longer mails the content team at all.
- A very long link address no longer breaks a scan. An href over the stored length is trimmed to fit rather than failing the whole batch and failing it again on every rescan.
- The page recheck endpoint now checks that the element exists and that the reader may see it before doing any work, and the report list screens cap how large a page of rows a request may ask for.
- The Ignored screen names an element link by its target's title, the way every other screen does, instead of the internal marker.
- Hovering a URL on the list screens now says plainly that the click opens the link's report rather than the link itself. The external-link icon beside it remains the way to actually visit one.
- The sidebar badges no longer vanish on screens with no site context, the Dashboard among them: they fall back to the primary site, which is the site the links themselves open.
- The badge beside Ignored in the sidebar now counts the dismissals the screen actually lists. It used to count every URL holding the ignored verdict, rule-quieted addresses and skipped schemes included, so it could promise dozens of rows over an empty screen.
- Clearing a number field on the settings and saving no longer quietly stores a zero. For the fields where zero is legal, the caching windows among them, an emptied box used to become "trust nothing, recheck everything on every scan" without anybody choosing it. An empty box now keeps the stored value, and a typed 0 still lands.

## 1.0.0-beta.6 - 2026-08-25

### Added
- A Where to start pane on the Overview, shown while anything is broken. On a site with thousands of broken links a wall of counts answers how bad it is and nothing else, so the pane offers a working order: the broken links pointing at your own sites first, since those are yours to fix without waiting on anybody, then the handful of addresses sitting in the most places, where one fix clears hundreds of rows at once, then the fresh arrivals: what was still working a week ago and what the last scan saw for the first time, since fresh breakage is worth catching before it settles into the pile. It finishes with what not to bother with at all.
- A Points At filter on the list screens, splitting links to your own sites from links to other websites. The CSV download honours it like the other filters.
- An Excluded Sections setting on the Scanning tab, for sections whose entries are data a template reads rather than pages anybody visits. An excluded section is fenced both ways: its entries are never read for links, and a link or relation pointing at one of its entries is recorded as ignored instead of being reported broken for having no page. This is the answer for URL-less sections, which the Excluded URI Patterns setting cannot reach since their entries have no URI to match. An Excluded Category Groups setting does the same for taxonomy, where having no pages is the norm rather than the exception.
- The CSV export now carries an Edit URL column, a link straight into each page's edit screen in the control panel, and a Page URL column with the page's public address. A page title in a spreadsheet was only half an answer: the person handed the file can now click through and fix the link rather than go searching by title.

### Changed
- Element links whose target has no URL of its own now show the target's title on the list screens, the URL detail page, the entry sidebar panel and the Where to start pane, instead of an internal `element:<id>` marker that names nothing an editor recognises. The detail page says plainly that such a link has no address to open or copy.
- Edit links on the URL detail page, and the Edit URL column in the CSV, now land where the work is: a link in a top-level field scrolls the edit screen to that field, and a link inside a Matrix block scrolls to the block itself and gives it a flash of outline so it is easy to spot. A field or block sitting on another tab has its tab opened first, and when a block cannot be found on the page at all, being edited through a draft for instance, the Matrix field holding it is scrolled to instead.
- The links panel on an entry's edit screen earned its keep: the broken addresses in it now scroll to the very anchor carrying the link, right there in the field, rather than leaving the page, with the report page one click away on the code badge, and a new Check this page again button rereads the page and queues a fresh check of everything it links to, so a fix shows up while the editor is still looking at it. The panel now sits at the top of the sidebar additions, above the informational panels, since it is the one with work in it.
- A link found inside a Matrix block now says which kind of block it is in: the Where it appears table reads "(in a Stats and Image block)" rather than "(in a block)", using the name on the block's own header, so the author knows which block on the page to open.
- The CSV columns now lead with the place and follow with the link: Page, Edit URL, Page URL, Page Type, Site, Field, Link Text, then the URL and its verdict, codes and dates. A row in this file is a place a link appears, so the file now reads as the work list it is. Anything parsing the old column order will need updating.
- The Redirects screen's opening line now says plainly what the two address columns mean: URL is the address as written in your content, Goes To is where the server actually sends anybody who follows it.
- On an environment where admin changes are turned off, the settings screens now show Craft's own read-only notice, the same one every native settings screen and well-behaved plugin shows, instead of a warning box of the plugin's own. The plugin now requires Craft 5.6 or later, which is where that notice arrived.

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
