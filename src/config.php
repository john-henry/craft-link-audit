<?php

/**
 * Link Audit configuration
 *
 * Copy this file to config/link-audit.php in your project to pin any plugin
 * setting from code. Values set here take precedence over the control panel
 * settings screen, where the matching field is shown read-only with an
 * "Overridden by config file" note. Uncomment only the keys you want to fix;
 * anything left commented keeps its default (shown below) and stays editable
 * in the CP.
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

    // Resolve internal links against the site's own elements and routes.
    // 'checkInternalLinks' => true,

    // Strip utm_*, fbclid, gclid and friends before hashing a URL.
    // 'stripTrackingParams' => true,

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
