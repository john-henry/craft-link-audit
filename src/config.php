<?php

/**
 * Link Audit configuration
 *
 * Copy this file to config/link-audit.php in your project to pin any plugin
 * setting from code. Values set here take precedence over the control panel
 * settings screen, where the matching field is shown read-only with a note
 * naming this file. Uncomment only the keys you want to fix; anything left
 * commented stays editable in the control panel.
 *
 * Most keys below show their own default, so uncommenting one changes nothing
 * until you edit the value. The ones whose default is empty show an example
 * instead, because an empty value says nothing about the shape it wants: the
 * environment variable references and every one of the tables are examples to
 * edit, not defaults to put back. Uncomment one of those as it stands and you
 * have set it to what the example says.
 */

return [
    // Scanning
    // -------------------------------------------------------------------------
    // Element type classes to scan. null scans every native type that can carry
    // links, e.g. ['craft\elements\Entry', 'craft\elements\Category'].
    // 'scannedElementTypes' => null,

    // Record relation fields (Entries, Assets, Categories, Users) as references.
    // 'scanRelationFields' => false,

    // Search plain text and table cell values for anything that parses as a URL.
    // 'scanPlainTextUrls' => false,

    // Check image and iframe sources found in rich text.
    // 'checkImages' => true,
    // 'checkIframes' => false,

    // Scan verbb/navigation nav node URLs.
    // 'scanNavigationNodes' => true,

    // Fetch each element's own URL and parse the rendered HTML for links.
    // 'renderedCrawlEnabled' => false,
    // 'maxPagesToCrawl' => 500,

    // Validate #fragment links against the ids in the rendered page. Needs the
    // rendered crawl to be on.
    // 'checkAnchorFragments' => false,

    // Re-extract an element's links when it is saved.
    // 'scanOnSave' => true,

    // Section UIDs left out of the audit altogether: their entries are never
    // read, and links pointing at their entries are recorded as ignored. For
    // sections whose entries are data a template reads rather than pages.
    // 'excludedSectionUids' => [],

    // The same fence for category groups, where having no pages is the norm.
    // 'excludedCategoryGroupUids' => [],

    // Resolve internal links against the site's own elements and routes.
    // 'checkInternalLinks' => true,

    // Strip utm_*, fbclid, gclid and friends before hashing a URL.
    // 'stripTrackingParams' => true,

    // The editable tables, as rows. Pinning one here makes the whole table
    // read-only in the control panel, so give it every row you want, not just
    // the ones you are adding.
    //
    // Pages kept out of the scan by URI. `uriPattern` is a regular expression
    // tested against the URI with no leading slash; the homepage is `^$`. An
    // empty `siteId` means every site.
    // 'excludedUriPatterns' => [
    //     ['enabled' => true, 'siteId' => '', 'uriPattern' => '^checkout'],
    // ],

    // Internal URLs always treated as valid, whatever the resolver makes of
    // them. For a route your own code answers that Craft does not know about.
    // 'internalUrlAllowPatterns' => [
    //     ['pattern' => '^/api/', 'note' => 'Answered by a module.'],
    // ],


    // HTTP
    // -------------------------------------------------------------------------
    // 'concurrency' => 10,
    // 'maxConcurrentPerHost' => 2,
    // 'minHostDelayMs' => 250,
    // 'connectTimeout' => 10,
    // 'timeout' => 20,
    // 'maxRedirects' => 5,
    // 'retryCount' => 2,
    // 'brokenAfterFailures' => 3,

    // A domain that no longer resolves gets a lower bar than a timeout or a
    // 500: it is nearly always a domain that is gone.
    // 'brokenAfterDnsFailures' => 2,

    // Overrides the checker User-Agent. Supports environment variables.
    // 'userAgent' => '$LINK_AUDIT_USER_AGENT',

    // Leave TLS verification on unless you know exactly why you are turning it
    // off: without it every https verdict is meaningless.
    // 'verifySsl' => true,

    // Outbound proxy. Supports environment variables.
    // 'proxy' => '$LINK_AUDIT_PROXY',

    // Ignores
    // -------------------------------------------------------------------------
    // URLs matching one of these are never checked and are reported as ignored
    // rather than broken. `pattern` is a regular expression tested against the
    // whole URL, scheme and all.
    // 'ignorePatterns' => [
    //     ['enabled' => true, 'pattern' => '^https://staging\\.', 'note' => 'Staging.'],
    // ],

    // Whole hosts nobody wants checked. A subdomain of a listed host counts as
    // the host.
    // 'ignoreHosts' => [
    //     ['enabled' => true, 'host' => 'example.com', 'note' => ''],
    // ],

    // Hosts known to refuse robots, so a refusal from one is reported as
    // unverifiable rather than broken. One column: every string is read as a
    // host.
    // 'botHostileHosts' => [
    //     ['host' => 'www.linkedin.com'],
    // ],

    // Caching and retention
    // -------------------------------------------------------------------------
    // 'okTtlDays' => 30,
    // 'redirectTtlDays' => 30,
    // 'brokenRecheckHours' => 24,
    // 'unreachableRecheckHours' => 6,
    // 'blockedTtlDays' => 14,
    // 'retainDays' => 90,
    // 'pruneOrphanUrls' => true,

    // Notifications
    // -------------------------------------------------------------------------
    // 'notifyEmailEnabled' => false,
    // 'notifyEmailRecipients' => '$LINK_AUDIT_NOTIFY_EMAILS',
    // 'notifySlackEnabled' => false,
    // 'notifySlackWebhookUrl' => '$LINK_AUDIT_SLACK_WEBHOOK',
    // 'notifyOnNewBroken' => true,
    // 'notifyBrokenThreshold' => 1,

    // Schedule
    // -------------------------------------------------------------------------
    // Best effort only: it rides on Craft's garbage collection. A cron entry
    // calling `php craft link-audit/scan/incremental` is the reliable way.
    // 'scheduledScanEnabled' => false,
    // 'scheduledScanIntervalHours' => 24,
];
