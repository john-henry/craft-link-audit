<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\services;

use Craft;
use craft\base\Element;
use craft\db\Query;
use craft\db\Table;
use craft\models\Site;
use craft\services\ProjectConfig;
use craft\web\UrlRule;
use johnhenry\linkaudit\enums\LinkKind;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\helpers\UrlNormaliser;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\ExtractedLink;
use johnhenry\linkaudit\models\SettingsModel;
use johnhenry\linkaudit\models\Verdict;
use yii\base\Component;

/**
 * Answers a link that points at this installation, out of the database where
 * the database knows the answer.
 *
 * An address that matches a live element is not an HTTP question. The answer is
 * already there, and asking your own server about a page it just rendered is a
 * request paid for twice. So a root-relative link is turned back into the URI
 * its site would store, and looked up against the elements on that site.
 *
 * The order matters, and it is built around not crying wolf. A live element
 * match settles it. An element sitting at that URI with its switch off settles
 * it too, and is said plainly, because the server would answer that address with
 * a 404 that tells the author less than the database already knows. A
 * template-only route in `config/routes.php` or the project config serves a real
 * page no element knows about, so the routes are asked before anything else, and
 * the `internalUrlAllowPatterns` escape hatch has its say after that.
 *
 * What none of those answers for is left pending rather than called broken, and
 * the HTTP check phase an external link goes through asks the server for it. The
 * database is not the last word on this installation's own addresses: a redirect
 * plugin, an `.htaccess` rule, a CDN and a URL rule a plugin registers at
 * runtime all serve pages that `elements_sites` has never heard of, and so does
 * every file, from an uploaded PDF to a transformed asset. Calling that lot
 * broken on a database miss is the fastest way to get a link checker switched
 * off, and a 301 an editor should be acting on would be reported as the one
 * thing it is not.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class InternalResolver extends Component
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var string The fragment every document answers to, whether or not
     * anything on it carries the name: browsers scroll to the top of the page.
     */
    private const _ANCHOR_TOP = 'top';

    // =========================================================================
    // Private Properties
    // =========================================================================

    /**
     * @var array<int, string[]> Route patterns as regular expressions, keyed by
     * site id.
     */
    private array $_routePatterns = [];

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * The verdict for a link the extractor found, or null when it is somebody
     * else's server to answer for, or nobody's to answer for yet.
     *
     * A link carrying a `#fragment` is the second of those. Whether anything on
     * the target page holds that id is a question only the page's own HTML can
     * answer, and extraction is no time to be fetching pages: it runs per
     * element, so the same page would be pulled down once for every link
     * pointing at it. Left pending, the check phase settles it instead, where
     * one fetch answers every fragment on the page and the verdict earns a time
     * to live like any other.
     *
     * @param ExtractedLink $link The link to resolve.
     * @return Verdict|null The verdict, or null when the link is external or is
     *                      being left for the check phase.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function resolve(ExtractedLink $link): ?Verdict
    {
        if ($link->kind === LinkKind::Internal && UrlNormaliser::fragmentOf($link->url) !== null) {
            return null;
        }

        return match ($link->kind) {
            LinkKind::External => null,
            LinkKind::Ignored => new Verdict(status: UrlStatus::Ignored),
            LinkKind::Internal => $this->resolveUrl($link->url, $link->siteId),
            LinkKind::Element => $this->resolveElement(
                $link->targetElementId ?? 0,
                $link->targetElementType,
                $link->siteId,
            ),
            LinkKind::Relation => $this->resolveElement(
                $link->targetElementId ?? 0,
                $link->targetElementType,
                $link->siteId,
                isRelation: true,
            ),
        };
    }

    /**
     * The verdict for a link that points at an element.
     *
     * Three ways for it to be wrong, and the author needs to be told which:
     * the element is gone, it is switched off, or, for an authored hyperlink
     * only, it is there and enabled but has no URL to send anybody to.
     *
     * That last check is skipped for a relation. A relation field only ever
     * asserts that an element was picked, never that it renders a link, so an
     * entry with no URL of its own, a data-only row a template reads inline
     * rather than links to, is not a defect: it is answered as soon as it is
     * found to exist and be enabled.
     *
     * @param int $elementId The element being linked to.
     * @param string|null $elementType Its class, when the extractor could work
     *                                 it out. Without it Craft has to look the
     *                                 type up first, which costs a query.
     * @param int $siteId The site the link was found on.
     * @param bool $isRelation Whether this came from a relation field rather
     *                         than an authored hyperlink, so a target with no
     *                         URL of its own is left alone rather than called
     *                         broken.
     * @return Verdict The verdict.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function resolveElement(
        int $elementId,
        ?string $elementType,
        int $siteId,
        bool $isRelation = false,
    ): Verdict {
        if (!$this->_settings()->checkInternalLinks) {
            return new Verdict(status: UrlStatus::Ignored);
        }

        if ($elementId <= 0) {
            return $this->_broken('This link does not say what it points at.');
        }

        $element = Craft::$app->getElements()->getElementById($elementId, $elementType, $siteId);

        if ($element === null) {
            return $this->_broken('Whatever this pointed at has been deleted.');
        }

        if (!$element->enabled || $element->getEnabledForSite($siteId) === false) {
            return $this->_broken('What this points at is disabled on this site, so nobody can see it.');
        }

        if (!$isRelation && $element->getUrl() === null) {
            return $this->_broken(
                'What this links to is still there and switched on, but it has no URL on this '
                . 'site, so the link goes nowhere.',
            );
        }

        return new Verdict(status: UrlStatus::Ok);
    }

    /**
     * The verdict for a URL on one of this installation's own sites.
     *
     * @param string $url The normalised URL.
     * @param int $siteId The site the link was found on. The URL itself decides
     *                    which site it is actually resolved against, since two
     *                    sites can share a host and differ only by base path.
     * @return Verdict|null The verdict, or null when nothing in the database
     *                      answers for the address and the HTTP check phase has
     *                      to ask the server instead.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function resolveUrl(string $url, int $siteId): ?Verdict
    {
        if (!$this->_settings()->checkInternalLinks) {
            return new Verdict(status: UrlStatus::Ignored);
        }

        $site = $this->_siteForUrl($url, $siteId);

        if ($site === null) {
            return $this->_broken('This address does not belong to any of the sites here.');
        }

        $uri = $this->_uriFor($url, $site);

        // A template-only route serves a real page that no element knows about,
        // so it has to be asked before anything is called broken.
        if ($this->_elementExists($uri, (int)$site->id) || $this->_matchesRoute($uri, $site)) {
            // The address is right. Whether the bit after the hash is, if there
            // is one, is a separate question and a slower one.
            return $this->_fragmentVerdict($url);
        }

        if ($this->_matchesAllowPattern($uri)) {
            return new Verdict(status: UrlStatus::Ignored);
        }

        // No live element and no route answers to this address, but that is
        // not the same as nobody: a redirect rule, a file, a route registered
        // at runtime, all of them are served by the server and by nothing that
        // can be looked up here. A disabled element holding the URI proves
        // nothing either way, because retiring a page by disabling its entry
        // and putting a redirect over the address is ordinary housekeeping.
        // Only a request can say which it is.
        return null;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * A broken verdict, with the reason every internal failure shares.
     *
     * @param string $message What to show the author.
     * @return Verdict The verdict.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _broken(string $message): Verdict
    {
        return new Verdict(
            status: UrlStatus::Broken,
            reason: Verdict::REASON_NO_ELEMENT,
            message: $message,
        );
    }

    /**
     * Whether a live element on a site answers to a URI.
     *
     * Drafts, revisions and trashed rows are all excluded: none of them is
     * served to anybody.
     *
     * @param string $uri The URI, as `elements_sites` stores it.
     * @param int $siteId The site to look in.
     * @return bool Whether an element answers to it.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _elementExists(string $uri, int $siteId): bool
    {
        return (new Query())
            ->from(['elements_sites' => Table::ELEMENTS_SITES])
            ->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[elements_sites.elementId]]')
            ->where([
                'elements_sites.siteId' => $siteId,
                'elements_sites.uri' => $uri,
                'elements_sites.enabled' => true,
                'elements.enabled' => true,
                'elements.draftId' => null,
                'elements.revisionId' => null,
                'elements.dateDeleted' => null,
            ])
            ->exists();
    }

    /**
     * The verdict for the `#fragment` on a link whose page is already known to
     * be there.
     *
     * The verdict belongs to the link rather than to the page, which is why the
     * fragment is kept on the URL row for this one path: `/about#team` and
     * `/about#staff` are two different questions about the same page, and one
     * row could only ever answer one of them.
     *
     * Two ways out that are deliberately not broken. `#top` is answered by every
     * document there is, whether or not anything on it carries the name, because
     * that is what browsers do with it. And a page that could not be fetched at
     * all is this installation's problem rather than the link's: the database
     * says the page is there, so calling the link broken because the server was
     * unwell would be crying wolf.
     *
     * @param string $url The normalised URL, fragment and all.
     * @return Verdict The verdict.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _fragmentVerdict(string $url): Verdict
    {
        $fragment = UrlNormaliser::fragmentOf($url);

        if ($fragment === null || !$this->_settings()->checksAnchorFragments()) {
            return new Verdict(status: UrlStatus::Ok);
        }

        $anchors = LinkAudit::$plugin->getPageCrawler()->anchorsFor(UrlNormaliser::stripFragment($url));

        if ($anchors === null) {
            Craft::warning(
                "Could not read the page behind $url, so its anchor was taken on trust.",
                'link-audit',
            );

            return new Verdict(status: UrlStatus::Ok);
        }

        $name = rawurldecode($fragment);

        if (
            $name === self::_ANCHOR_TOP
            || in_array($name, $anchors, true)
            || in_array($fragment, $anchors, true)
        ) {
            return new Verdict(status: UrlStatus::Ok);
        }

        return new Verdict(
            status: UrlStatus::Broken,
            reason: Verdict::REASON_FRAGMENT,
            message: "The page is there, but nothing on it is named \"$name\", so the link lands at "
                . 'the top of the page instead.',
        );
    }

    /**
     * Whether a URI matches one of the always valid patterns from the settings.
     *
     * The escape hatch for pages the resolver cannot see: a route registered by
     * a plugin at runtime, a file served by the web server, anything at all.
     *
     * @param string $uri The URI.
     * @return bool Whether it is allowed through.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _matchesAllowPattern(string $uri): bool
    {
        foreach ($this->_settings()->internalUrlAllowPatterns as $row) {
            $pattern = trim((string)($row['pattern'] ?? ''));

            if ($pattern === '') {
                continue;
            }

            // The tilde delimiter means slashes need no escaping in the
            // pattern. A malformed pattern makes preg_match return false rather
            // than 1, so it simply does not match; the scoped error handler
            // swallows the warning without reaching for the @ operator.
            $delimited = '~' . str_replace('~', '\~', $pattern) . '~';
            set_error_handler(static fn(): bool => true);

            try {
                if (preg_match($delimited, $uri) === 1) {
                    return true;
                }
            } finally {
                restore_error_handler();
            }
        }

        return false;
    }

    /**
     * Whether a URI matches a route defined for a site.
     *
     * @param string $uri The URI.
     * @param Site $site The site.
     * @return bool Whether a route answers to it.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _matchesRoute(string $uri, Site $site): bool
    {
        $subject = $uri === Element::HOMEPAGE_URI ? '' : $uri;

        foreach ($this->_routePatterns($site) as $pattern) {
            if (preg_match($pattern, $subject) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Turns a Craft route pattern into a regular expression.
     *
     * Craft's own token expansion is borrowed rather than reimplemented, so
     * `{slug}` means here exactly what it means in `config/routes.php`. The
     * literal parts of the pattern are quoted and the `<name:regex>` parameters
     * become groups, which is the same shape Yii builds internally.
     *
     * @param string $pattern The route pattern.
     * @return string|null The regular expression, or null when the pattern is
     *                     empty.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _patternToRegex(string $pattern): ?string
    {
        $pattern = trim($pattern, '/');

        if ($pattern === '') {
            return null;
        }

        $expanded = strtr($pattern, UrlRule::regexTokens());
        $parts = preg_split(
            '/(<[\w._-]+(?::[^>]+)?>)/',
            $expanded,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        );

        if ($parts === false) {
            return null;
        }

        $regex = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (preg_match('/^<[\w._-]+(?::([^>]+))?>$/', $part, $matches) === 1) {
                $regex .= '(?:' . ($matches[1] ?? '[^\/]+') . ')';
                continue;
            }

            $regex .= preg_quote($part, '/');
        }

        return '/^' . $regex . '$/u';
    }

    /**
     * Every route pattern that applies to a site, as regular expressions.
     *
     * Project config routes are read straight from the config rather than
     * through the routes service, because that service scopes them to whichever
     * site happens to be current, and a queue worker's current site is not the
     * site being resolved.
     *
     * @param Site $site The site.
     * @return string[] The patterns.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _routePatterns(Site $site): array
    {
        $siteId = (int)$site->id;

        if (isset($this->_routePatterns[$siteId])) {
            return $this->_routePatterns[$siteId];
        }

        $patterns = [];
        $configured = Craft::$app->getProjectConfig()->get(ProjectConfig::PATH_ROUTES) ?? [];

        if (is_array($configured)) {
            foreach ($configured as $route) {
                if (!is_array($route) || !isset($route['uriPattern'])) {
                    continue;
                }

                $routeSiteUid = $route['siteUid'] ?? null;

                if (!empty($routeSiteUid) && $routeSiteUid !== $site->uid) {
                    continue;
                }

                $patterns[] = (string)$route['uriPattern'];
            }
        }

        foreach (array_keys(Craft::$app->getRoutes()->getConfigFileRoutes()) as $pattern) {
            $patterns[] = (string)$pattern;
        }

        $this->_routePatterns[$siteId] = array_values(array_filter(array_map(
            fn(string $pattern): ?string => $this->_patternToRegex($pattern),
            $patterns,
        )));

        return $this->_routePatterns[$siteId];
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
     * The site a URL belongs to.
     *
     * Two sites can share a host and differ only by base path, so the longest
     * matching base URL wins: `/store/bikes` belongs to the store, not to the
     * site sitting at the root of the same domain.
     *
     * @param string $url The normalised URL.
     * @param int $fallbackSiteId The site the link was found on, used when the
     *                            URL matches nothing better.
     * @return Site|null The site, or null when no site claims the URL.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _siteForUrl(string $url, int $fallbackSiteId): ?Site
    {
        $host = mb_strtolower((string)parse_url($url, PHP_URL_HOST), 'UTF-8');
        $path = (string)parse_url($url, PHP_URL_PATH);
        $best = null;
        $bestLength = -1;

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $baseUrl = $site->getBaseUrl();

            if ($baseUrl === null) {
                continue;
            }

            $siteHost = mb_strtolower((string)parse_url($baseUrl, PHP_URL_HOST), 'UTF-8');

            if ($siteHost !== $host) {
                continue;
            }

            $basePath = rtrim((string)parse_url($baseUrl, PHP_URL_PATH), '/');
            $matches = $basePath === '' || $path === $basePath || str_starts_with($path, $basePath . '/');

            if ($matches && strlen($basePath) > $bestLength) {
                $best = $site;
                $bestLength = strlen($basePath);
            }
        }

        return $best ?? Craft::$app->getSites()->getSiteById($fallbackSiteId);
    }

    /**
     * The URI a site would store for a URL.
     *
     * The site's base path comes off the front, what is left is trimmed of its
     * slashes, and an empty result becomes the homepage token Craft keeps in
     * `elements_sites`.
     *
     * @param string $url The normalised URL.
     * @param Site $site The site it belongs to.
     * @return string The URI.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _uriFor(string $url, Site $site): string
    {
        $path = rawurldecode((string)parse_url($url, PHP_URL_PATH));
        $basePath = rtrim((string)parse_url((string)$site->getBaseUrl(), PHP_URL_PATH), '/');

        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }

        $uri = trim($path, '/');

        return $uri === '' ? Element::HOMEPAGE_URI : $uri;
    }
}
