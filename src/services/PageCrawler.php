<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\services;

use Craft;
use craft\base\Element;
use craft\db\Query;
use craft\helpers\UrlHelper;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use johnhenry\linkaudit\enums\LinkKind;
use johnhenry\linkaudit\enums\SchemeKind;
use johnhenry\linkaudit\exceptions\UnsafeUrlException;
use johnhenry\linkaudit\helpers\HtmlParser;
use johnhenry\linkaudit\helpers\UrlNormaliser;
use johnhenry\linkaudit\helpers\UrlSafety;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\ExtractedLink;
use johnhenry\linkaudit\models\SettingsModel;
use Throwable;
use yii\base\Component;

/**
 * Fetches this installation's own pages and reads the links out of the HTML a
 * visitor would actually get.
 *
 * The field walker reads what authors typed. This reads what the site serves,
 * which is a different list: the footer, the main navigation, the "back to top"
 * in a layout, the third party widget somebody dropped into a template three
 * years ago. None of that is in anybody's content, so nothing else in a scan
 * will ever see it, and it is on every page of the site.
 *
 * It is off by default because it is the one part of this plugin that costs a
 * request per page of your own site. Three things keep that honest:
 *
 *  - The page cap. A crawl fetches at most `maxPagesToCrawl` pages, in a fixed
 *    order, and says in the log how many it left out rather than quietly
 *    stopping;
 *  - One request at a time, with `minHostDelayMs` between them. The external
 *    checker runs a pool because it is spreading load across hundreds of other
 *    people's servers; here every request lands on the same machine that is
 *    serving the site, so a pool is the last thing wanted. This is deliberately
 *    more conservative than `maxConcurrentPerHost`; and
 *  - The dedupe pillar. A footer link on ten thousand pages is still one URL row
 *    and one check, because that is what the URL table is for.
 *
 * Nothing an author typed reaches this class: every URL it fetches is built from
 * a site's own base URL and a URI out of `elements_sites`. The SSRF guard is
 * applied all the same, and every redirect hop with it, because a page is free
 * to redirect anywhere at all.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class PageCrawler extends Component
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * @var int The most of a page that is ever read into memory. A crawl of a
     * thousand pages should not be brought down by one of them serving a
     * download.
     */
    private const _MAX_BODY_BYTES = 2097152;

    // =========================================================================
    // Private Properties
    // =========================================================================

    /**
     * @var array<string, string[]|null> The anchor names found on each page
     * fetched this process, keyed by page URL. Null means the page could not be
     * read. Memoised so that twenty fragment links pointing at the same page
     * cost one request between them.
     */
    private array $_anchors = [];

    /**
     * @var ClientInterface|null The HTTP client, built on first use.
     */
    private ?ClientInterface $_client = null;

    /**
     * @var array<string, float> When the last request to each host went out, so
     * the next one can wait out whatever is left of the minimum delay.
     */
    private array $_lastRequestAt = [];

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * The anchor names a page carries, so a `#fragment` link can be told whether
     * it lands anywhere.
     *
     * @param string $pageUrl The page URL, with any fragment already off it.
     * @return string[]|null The anchor names, or null when the page could not be
     *                       read at all.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function anchorsFor(string $pageUrl): ?array
    {
        if (array_key_exists($pageUrl, $this->_anchors)) {
            return $this->_anchors[$pageUrl];
        }

        $html = $this->fetch($pageUrl);
        $document = $html !== null ? HtmlParser::document($html) : null;

        return $this->_anchors[$pageUrl] = $document !== null
            ? HtmlParser::anchorNames($document)
            : null;
    }

    /**
     * Crawls one page and rebuilds the references its template put there.
     *
     * A page that could not be fetched leaves its existing rows exactly where
     * they are. A 500 on your own server says nothing about the links in the
     * template, and rubbing out last week's findings because today's deploy is
     * unwell would be the worst possible time to lose them.
     *
     * @param array<string, mixed> $page One row from {@see self::pageQuery()}.
     * @param int|null $scanId The scan to stamp the reference rows with.
     * @return bool Whether the page was fetched and read.
     * @throws Exception If the page URL cannot be built.
     * @throws Throwable If the reference rows cannot be rebuilt.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function crawlPage(array $page, ?int $scanId = null): bool
    {
        $elementId = (int)$page['elementId'];
        $siteId = (int)$page['siteId'];
        $uri = $page['uri'] !== null ? (string)$page['uri'] : null;

        if (LinkAudit::$plugin->getScanService()->isUriExcluded($uri, $siteId)) {
            return false;
        }

        $pageUrl = $this->_pageUrl($uri, $siteId);

        if ($pageUrl === null) {
            return false;
        }

        $html = $this->fetch($pageUrl);

        if ($html === null) {
            return false;
        }

        $document = HtmlParser::document($html);

        if ($document === null) {
            return false;
        }

        // Free for the fragment checker later on: the page is already parsed,
        // and reading its anchors now saves fetching it again.
        $this->_anchors[$pageUrl] = HtmlParser::anchorNames($document);

        $this->_store(
            links: $this->_linksFrom(
                document: $document,
                pageUrl: $pageUrl,
                elementId: $elementId,
                elementType: (string)$page['elementType'],
                siteId: $siteId,
            ),
            elementId: $elementId,
            siteId: $siteId,
            scanId: $scanId,
        );

        return true;
    }

    /**
     * Fetches one of this installation's own pages.
     *
     * @param string $url The absolute page URL.
     * @return string|null The HTML, or null when the page did not answer with
     *                     any.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function fetch(string $url): ?string
    {
        try {
            UrlSafety::assertSafeUrl($url);
        } catch (UnsafeUrlException $e) {
            Craft::warning("Did not fetch $url: {$e->getMessage()}", 'link-audit');

            return null;
        }

        $this->_throttle(UrlNormaliser::hostOf($url));

        try {
            $response = $this->getClient()->request('GET', $url, $this->_requestOptions());
        } catch (Throwable $e) {
            Craft::warning("Could not fetch $url: {$e->getMessage()}", 'link-audit');

            return null;
        }

        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            Craft::warning("Fetching $url came back with HTTP $status.", 'link-audit');

            return null;
        }

        $contentType = strtolower($response->getHeaderLine('Content-Type'));

        // A page that serves something other than markup has no links in it to
        // read, and reading a few megabytes of it to find that out would be a
        // waste of everybody's time.
        if ($contentType !== '' && !str_contains($contentType, 'html')) {
            return null;
        }

        try {
            $body = $response->getBody();
            $html = $body->read(self::_MAX_BODY_BYTES);
            $body->close();
        } catch (Throwable $e) {
            Craft::warning("Could not read the page at $url: {$e->getMessage()}", 'link-audit');

            return null;
        }

        return $html !== '' ? $html : null;
    }

    /**
     * Forgets every page read this process.
     *
     * Wanted by tests, which run many cases in one process. A queue worker never
     * lives long enough to need it, and while it does live the memo is the whole
     * point.
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function flush(): void
    {
        $this->_anchors = [];
        $this->_lastRequestAt = [];
    }

    /**
     * The HTTP client pages are fetched on.
     *
     * @return ClientInterface The client.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getClient(): ClientInterface
    {
        if ($this->_client === null) {
            $this->_client = Craft::createGuzzleClient();
        }

        return $this->_client;
    }

    /**
     * The pages a crawl covers, capped and in a fixed order.
     *
     * Built on the scan's own element query, so a page left out of scanning is
     * left out of crawling too and the two phases cannot disagree about what the
     * site is. What it adds is a URI: an entry with no public page has plenty of
     * links in its fields, but there is nothing to fetch.
     *
     * @param int[] $siteIds The sites to cover.
     * @return Query The query, ordered so paging cannot skip a page and limited
     *               to the page cap.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function pageQuery(array $siteIds): Query
    {
        return $this->uncappedPageQuery($siteIds)->limit($this->_cap());
    }

    /**
     * Notes in the log when the page cap stopped a crawl short.
     *
     * Said out loud rather than left to be noticed: a crawl that quietly covers
     * the first five hundred pages of a five thousand page site is a report that
     * looks complete and is not.
     *
     * @param int[] $siteIds The sites the crawl covers.
     * @return int How many pages were left out.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function reportCappedPages(array $siteIds): int
    {
        $cap = $this->_cap();
        $total = (int)$this->uncappedPageQuery($siteIds)->count();
        $dropped = max(0, $total - $cap);

        if ($dropped > 0) {
            Craft::warning(
                "The rendered crawl covered $cap of $total pages: the page cap stopped it there, so "
                . "$dropped pages were not fetched. Raise the maximum pages per crawl to cover them.",
                'link-audit',
            );
        }

        return $dropped;
    }

    /**
     * Sets the HTTP client, which is how tests point the crawler at a handler
     * of their own rather than at a running site.
     *
     * @param ClientInterface $client The client to use.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function setClient(ClientInterface $client): void
    {
        $this->_client = $client;
    }

    /**
     * Every page a crawl would cover if there were no cap, which is what the cap
     * is measured against.
     *
     * @param int[] $siteIds The sites to cover.
     * @return Query The query.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function uncappedPageQuery(array $siteIds): Query
    {
        return LinkAudit::$plugin->getScanService()
            ->elementQuery($siteIds)
            ->andWhere(['not', ['es.uri' => null]]);
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * The page cap, floored at one.
     *
     * @return int The cap.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _cap(): int
    {
        return max(1, $this->_settings()->maxPagesToCrawl);
    }

    /**
     * Reads the links out of a fetched page.
     *
     * Relative hrefs are resolved against the page's own URL rather than the
     * site's base URL, which is the one place this differs from the field
     * walker and the reason it does its own parsing: `../thing` in a template
     * means something different on `/a/b/c` than it does at the root, and a
     * browser reading the same markup would agree with the page.
     *
     * @param DOMDocument $document The parsed page.
     * @param string $pageUrl The URL it was fetched from.
     * @param int $elementId The element that owns the page.
     * @param string $elementType That element's class.
     * @param int $siteId The site it was fetched on.
     * @return ExtractedLink[] The links found.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _linksFrom(
        DOMDocument $document,
        string $pageUrl,
        int $elementId,
        string $elementType,
        int $siteId,
    ): array {
        $settings = $this->_settings();
        $baseUrls = $this->_siteBaseUrls();
        $xpath = new DOMXPath($document);
        $found = [];

        // Each selector is the tag to read, the attribute holding the URL, and
        // the attribute holding its text, or null to use the tag's own text.
        $selectors = [
            ['//a[@href]', 'href', null],
            ['//area[@href]', 'href', 'alt'],
        ];

        if ($settings->checkImages) {
            $selectors[] = ['//img[@src]', 'src', 'alt'];
        }

        if ($settings->checkIframes) {
            $selectors[] = ['//iframe[@src]', 'src', 'title'];
        }

        foreach ($selectors as [$query, $urlAttribute, $textAttribute]) {
            $nodes = $xpath->query($query);

            if ($nodes === false) {
                continue;
            }

            foreach ($nodes as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }

                $link = $this->_link(
                    rawHref: $node->getAttribute($urlAttribute),
                    linkText: $textAttribute !== null
                        ? $node->getAttribute($textAttribute)
                        : $node->textContent,
                    pageUrl: $pageUrl,
                    baseUrls: $baseUrls,
                    elementId: $elementId,
                    elementType: $elementType,
                    siteId: $siteId,
                );

                if ($link !== null) {
                    $found[] = $link;
                }
            }
        }

        return $found;
    }

    /**
     * Builds one link from a href found in a rendered page.
     *
     * Only http and https survive. A mailto, a tel or a bare `#fragment` is
     * skipped exactly as it is in stored content, and so is a scheme the plugin
     * has never heard of: a template is not somewhere an author typed something
     * odd, so there is nobody to report it to.
     *
     * @param string $rawHref The href as the page served it.
     * @param string|null $linkText The anchor or alt text.
     * @param string $pageUrl The page the href was found on.
     * @param string[] $baseUrls Every site base URL, for the internal test.
     * @param int $elementId The element that owns the page.
     * @param string $elementType That element's class.
     * @param int $siteId The site it was fetched on.
     * @return ExtractedLink|null The link, or null when there is nothing to
     *                            record.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _link(
        string $rawHref,
        ?string $linkText,
        string $pageUrl,
        array $baseUrls,
        int $elementId,
        string $elementType,
        int $siteId,
    ): ?ExtractedLink {
        $rawHref = trim($rawHref);

        if ($rawHref === '' || UrlNormaliser::classifyScheme($rawHref) !== SchemeKind::Checkable) {
            return null;
        }

        $settings = $this->_settings();
        $normalised = UrlNormaliser::normalise($rawHref, $pageUrl, $settings->stripTrackingParams);

        if ($normalised === null) {
            return null;
        }

        $isInternal = UrlNormaliser::isInternal($normalised, $baseUrls);

        if ($isInternal && $settings->checksAnchorFragments()) {
            $normalised = UrlNormaliser::withFragment($normalised, $rawHref);
        }

        return new ExtractedLink(
            kind: $isInternal ? LinkKind::Internal : LinkKind::External,
            elementId: $elementId,
            elementType: $elementType,
            ownerElementId: $elementId,
            siteId: $siteId,
            url: $normalised,
            rawHref: $rawHref,
            linkText: $this->_tidyText($linkText),
            source: ExtractedLink::SOURCE_RENDERED,
        );
    }

    /**
     * The URL a page is served from.
     *
     * Built from the site's base URL and the stored URI rather than by loading
     * the element and asking it, which would mean a full element load, content
     * and all, for the sake of a string the crawl query already has.
     *
     * @param string|null $uri The URI as `elements_sites` stores it.
     * @param int $siteId The site.
     * @return string|null The URL, or null when the site serves none.
     * @throws Exception If the site URL cannot be built.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _pageUrl(?string $uri, int $siteId): ?string
    {
        $site = Craft::$app->getSites()->getSiteById($siteId);

        if ($site === null || $site->getBaseUrl() === null) {
            return null;
        }

        $path = ($uri === null || $uri === Element::HOMEPAGE_URI) ? '' : $uri;

        return UrlHelper::siteUrl($path, siteId: $siteId);
    }

    /**
     * The Guzzle options for one page fetch.
     *
     * No proxy, unlike the external checker. A proxy is configured to reach the
     * outside world, and these requests never leave the building.
     *
     * @return array<string, mixed> The options.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _requestOptions(): array
    {
        $settings = $this->_settings();

        return [
            // Every hop back through the SSRF guard: a page of your own is free
            // to redirect anywhere at all, and one that redirects somewhere it
            // should not is exactly what the guard is for.
            RequestOptions::ALLOW_REDIRECTS => UrlSafety::redirectOptions($settings->maxRedirects),
            RequestOptions::CONNECT_TIMEOUT => $settings->connectTimeout,
            RequestOptions::HEADERS => [
                'Accept' => 'text/html,application/xhtml+xml',
                'User-Agent' => $settings->getCheckerUserAgent(),
            ],
            // A status code is the answer here, never an exception.
            RequestOptions::HTTP_ERRORS => false,
            // Read the headers, then take only as much of the body as the cap
            // allows, so one enormous page cannot take a crawl down.
            RequestOptions::STREAM => true,
            RequestOptions::TIMEOUT => $settings->timeout,
            RequestOptions::VERIFY => $settings->verifySsl,
        ];
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
     * The base URL of every site that serves one.
     *
     * @return string[] The base URLs.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _siteBaseUrls(): array
    {
        $baseUrls = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $baseUrl = $site->getBaseUrl();

            if ($baseUrl !== null) {
                $baseUrls[] = $baseUrl;
            }
        }

        return $baseUrls;
    }

    /**
     * Stores what a page turned out to hold.
     *
     * Links the page already accounts for through its own fields are dropped
     * before anything is written, so the crawl only ever adds what the template
     * put there. Internal links are settled here for the same reason extraction
     * settles them: the answer is in the database, not on the wire.
     *
     * @param ExtractedLink[] $links The links found.
     * @param int $elementId The element that owns the page.
     * @param int $siteId The site it was fetched on.
     * @param int|null $scanId The scan to stamp the reference rows with.
     * @return void
     * @throws Throwable If the reference rows cannot be rebuilt.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _store(array $links, int $elementId, int $siteId, ?int $scanId): void
    {
        $store = LinkAudit::$plugin->getUrlStore();
        $resolver = LinkAudit::$plugin->getInternalResolver();
        $ignores = LinkAudit::$plugin->getIgnoreService();
        $attributed = $store->attributedUrlIds($elementId, $siteId);
        $refs = [];

        foreach ($links as $link) {
            $urlId = $store->upsert($link->url, $link->isInternal(), $link->siteId, $link->initialStatus());

            if (in_array($urlId, $attributed, true)) {
                continue;
            }

            $refs[$urlId] = $link->toReference($urlId, $scanId);
            $verdict = $ignores->verdictFor($link->url) ?? $resolver->resolve($link);

            if ($verdict !== null) {
                $store->recordVerdict($urlId, $verdict);
            }
        }

        // Keyed by URL as they went in, because a template puts the same link on
        // a page more than once as a matter of course: a masthead logo and a
        // footer logo both point home, and one row says that as well as two do.
        $store->replaceReferencesFor(
            $elementId,
            $siteId,
            array_values($refs),
            [ExtractedLink::SOURCE_RENDERED],
        );
    }

    /**
     * Waits out whatever is left of the minimum gap between two requests to the
     * same host.
     *
     * The one place this plugin sleeps, and it is bounded by a setting whose
     * default is a quarter of a second. A crawl is sequential, so the gap is
     * usually already spent on the fetch that went before it and this returns
     * without waiting at all.
     *
     * @param string $host The host about to be asked.
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _throttle(string $host): void
    {
        $delay = max(0, $this->_settings()->minHostDelayMs) / 1000;
        $last = $this->_lastRequestAt[$host] ?? null;
        $now = microtime(true);

        if ($last !== null && $now - $last < $delay) {
            usleep((int)round(($delay - ($now - $last)) * 1000000));
        }

        $this->_lastRequestAt[$host] = microtime(true);
    }

    /**
     * Collapses the whitespace out of anchor text and clips it to something a
     * report column can hold.
     *
     * @param string|null $text The text as it was found.
     * @return string|null The tidied text, or null when there was none.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    private function _tidyText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $tidied = trim((string)preg_replace('/\s+/u', ' ', $text));

        return $tidied !== '' ? mb_substr($tidied, 0, 255) : null;
    }
}
