# Release Notes for Link Audit

## 1.0.0-beta.1 - 2026-08-17

### Added
- Initial release.
- Finds links across your stored content: rich text, native Link fields, URL fields, Hyper fields, Matrix and content blocks, and navigation nodes.
- Checks each unique address once, no matter how many entries carry it, so a footer link on ten thousand pages is one request rather than ten thousand.
- Reads entries, categories, tags and global sets out of the box, plus Commerce products and variants where Commerce is installed. Users, addresses, orders and any plugin's own element types are a tick away in the settings, but stay out of the scan until you ask for them: there are an awful lot of them on a busy site and no editor ever typed a link into one.
- Caches every verdict with a shelf life per status, so a rescan only pays for what has gone stale. A link parked because its host asked to be left alone keeps its place and comes back round when its time is up.
- Tells Broken apart from Permanent Redirects, No Answer, and Unverifiable, which is a host that will not deal with robots. Unverifiable links get their own screen and are never counted as broken. A redirect shows both codes side by side, what the address answered with and where it ended up, so a link that has moved does not read as a plain 200.
- Waits for repeated failures before calling a link Broken, so one bad afternoon on somebody else's server does not flood the report. A host that no longer resolves is called broken sooner than one that is timing out, because a domain that is gone is usually gone for good.
- Resolves internal links against your own elements and routes rather than firing requests at your own server. Anything shaped like a file, a transformed image or an uploaded PDF, is checked over HTTP like any other link, since there is no element for it to resolve against.
- Optionally records entries, assets and categories picked in relation fields, and keeps the two kinds of link apart. Picking an element in a relation field is not the same as linking to it, so a relation counts as broken only when its target has been disabled or removed, never for having no page of its own.
- Optionally crawls your own rendered pages, so the links your templates hard-code into footers and navs are audited too. Off by default, capped at a set number of pages per crawl, and the log says when the cap has stopped it short.
- Optionally validates the bit after the hash, so a link to /about#team is only called good when something on that page still carries that id.
- Reports the entries, fields and sites every broken link appears in, with an Edit link straight to the page that needs fixing. Lists come back newest-checked first, and you can sort by how many places a link appears in when that is the question you are asking.
- Fences the whole report by the sites a person is allowed to edit, the lists and the single URL screen alike. Somebody with one site cannot read the verdict on, check, ignore or restore a link that only another site's content points at: they get the same answer they would get for an address nobody has ever met.
- Strips credentials out of an address before storing it, so a password somebody pasted into a field never turns up on a report screen, in a notification email or in a Slack channel. It is the same page either way, so it stays the same row.
- Only hands you a clickable link for `http` and `https` addresses. A scheme the plugin does not check is still recorded exactly as it was typed, so you can see what turned up in your content, but it is printed rather than linked.
- Refuses to request anything resolving to a private or reserved address, on the first hop, on every redirect after it, and on the Slack webhook as well.
- Keeps requests polite: a global limit, a tighter per-host limit on top of it, and a backoff when a host asks for one. A Retry-After is honoured as a window to leave that host alone for, the raised gap eases back down as the host settles, and links still inside the window come round on the next pass rather than holding a batch on the clock.
- Runs one content scan at a time. Ask for another while one is going, from the control panel or the command line, and it tells you which run to wait for rather than setting two workers on the same rows. A run that has gone an hour without touching its own row counts as abandoned, so a worker that died does not lock you out.
- Scans one site or the whole install, and a single-site scan closes out with that site's numbers.
- Rereads an element's links when it is saved, deleted or restored.
- Emails or posts to Slack when a scan finishes with new broken links.
- Adds a links panel to the entry edit screen, with the date those links were last checked and a way through to the full report.
- Adds a Broken Links tile to the dashboard, showing one site or all of them.
- Puts the counts on the control panel nav: broken links beside Link Audit itself, and the count for each list beside Broken, Redirects, Unverifiable, No Answer and Ignored. Scoped to the site you are on, and read from a short-lived cache so the nav costs nothing extra to draw.
- Downloads any of the Broken, Redirects, Unverifiable and No Answer lists as a CSV, honouring whatever the screen is filtered by. A row per place a link appears rather than a row per link, so the same broken address in three entries comes out as three rows, each naming its page, field, site and anchor text alongside the verdict, the codes and the dates. The file is written as it is read, so the size of your site is not the size of your memory, and any cell a spreadsheet would try to run as a formula is quoted so it stays text.
- Ships eight console commands for full, incremental and single element scans, rechecks, pruning, reporting and CSV export.
- A short tour of the Overview the first time you open it, with a link to take it again whenever you like.
- Three permission tiers: view reports, run scans, and ignore or restore links.
