[![Stable Version](https://img.shields.io/packagist/v/johnhenry/craft-link-audit?label=stable&style=for-the-badge)](https://packagist.org/packages/johnhenry/craft-link-audit)
[![Static Badge](https://img.shields.io/badge/free-plugin?style=for-the-badge&logo=craftcms&logoColor=white&logoSize=auto&label=Craft%20Plugin%20Store&labelColor=%23E5422B)](https://plugins.craftcms.com/link-audit?craft5)

![Link Audit](https://johnhenry.ie/images/plugins/promos/link-audit/1.png)


# Link Audit for Craft CMS



A broken link checker for Craft CMS 5.

It goes through your stored content, gathers up every link, checks each unique address once, and
shows you the entries carrying the ones that are broken.

## The thinking behind it

Most link checkers go page by page: load a page, check its links, move on to the next. That falls
over on a site of any size. If your footer links to a supplier, that supplier is on every page you
have, and a page-by-page checker will ask them about it thousands of times in one run. Sooner or
later they block you for it, everything they host turns up as broken, and the report stops being
believed.

Link Audit keeps one record per unique address instead. The footer link is checked once, however
many pages carry it, and the plugin remembers every entry, field and site it appears in, so when it
does break you can see where to go and fix it.

Verdicts are kept for a while rather than thrown away. A working link is trusted for 30 days before
it is asked again. A broken one is rechecked the next day, so a fix shows up quickly. The first scan
does the heavy lifting; the ones after it only look at what has gone stale.

It also knows the difference between a broken link and a host that will not talk to robots.
LinkedIn, Cloudflare and their kind refuse automated requests no matter how healthy the link is, so
those answers go on their own screen, marked Unverifiable, and are never counted as broken. A link
that gives no answer at all is only called broken after it has failed a few times in a row, because
one bad afternoon on somebody else's server is not news.

## What it reads

Stored field values, not rendered pages:

- Rich text (CKEditor and Redactor), including links inside nested entries
- Native Link fields and URL fields
- Hyper fields
- Matrix and content blocks, as deep as they go
- Navigation nodes, if you have verbb/navigation installed
- Image and iframe sources in rich text, if you turn them on

Because it reads stored values, it sees links on entries that have no public URL, and it can tell
you which field a link came from. Internal links are resolved against your own elements and routes
rather than fetched over HTTP, and whatever those cannot answer for is asked for over HTTP, so a
redirect rule in Retour or in your server config counts as the redirect it is rather than as a broken
link.

There is a rendered crawl mode as well, for links hard-coded into templates. It is off by default,
because fetching every page is the expensive way to do it.

## Documentation

Full documentation lives at [https://johnhenry.ie/plugins/link-audit/](https://johnhenry.ie/plugins/link-audit/)

## Support
Need a hand? Open an issue on our [GitHub Issues page](https://github.com/john-henry/craft-link-audit/issues)

## License

This package is licensed for free under the MIT License.

## Requirements

Craft CMS 5.6.0 or later, and PHP 8.2 or later. 

---

<a href="https://johnhenry.ie/plugins/" target="_blank">
    <img height="46" src="https://johnhenry.ie/images/plugins/logo.svg" alt="John Henry - Craft CMS Plugins">
</a>
