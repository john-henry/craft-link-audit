# Release Notes for Link Audit

## 1.0.0-beta.2 - 2026-08-24

### Fixed
- Internal links that no longer match a page of your own are now asked for over HTTP instead of being called broken on the spot. If something is answering for the old address, a Retour rule, an `.htaccess` rewrite or a redirect your CDN is doing, the link is reported as the redirect it is, with the address it ends up at, so an editor can bring the content up to date. One that really has gone is reported with the answer your own server gave for it.

## 1.0.0-beta.1 - 2026-08-17

### Added
- Initial release.
