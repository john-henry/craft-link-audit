<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\web\View;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\records\ReferenceRecord;
use johnhenry\linkaudit\records\UrlRecord;
use markhuot\craftpest\factories\Asset as AssetFactory;
use markhuot\craftpest\factories\Entry as EntryFactory;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// The links panel on an element's edit screen
//
// The panel is where the report meets the work: an author editing a page is
// told what that page is carrying, and never has to go looking. Most of what is
// asserted here is what does not appear, because a panel on a draft, on an
// asset or on an element type the scan never reads would be telling somebody
// something untrue.
//
// Helper names carry a `sidebar` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/**
 * An entry in the dedicated laFixture section, carrying the given rich text.
 *
 * The section and its fields live in project config rather than being made here:
 * creating a section auto-commits the transaction RefreshesDatabase relies on.
 */
function sidebarEntry(string $html = ''): Entry
{
    $section = Craft::$app->getEntries()->getSectionByHandle('laFixture');

    if ($section === null) {
        throw new RuntimeException(
            'The laFixture test section is missing. Run `ddev craft project-config/apply`.',
        );
    }

    $slug = 'la-sidebar-' . StringHelper::toLowerCase(StringHelper::randomString(10));

    return EntryFactory::factory()
        ->section($section)
        ->title('LA sidebar ' . $slug)
        ->slug($slug)
        ->set('laBody', $html)
        ->create();
}

/** Reads an element's links the way the extract job would. */
function sidebarExtract(Entry $entry): void
{
    LinkAudit::getInstance()->getScanService()->extractElement(
        (int)$entry->id,
        Entry::class,
        (int)$entry->siteId,
    );
}

/** The sidebar an element edit screen would render. */
function sidebarHtml(craft\base\Element $element): string
{
    $view = Craft::$app->getView();
    $mode = $view->getTemplateMode();
    $view->setTemplateMode(View::TEMPLATE_MODE_CP);

    try {
        return $element->getSidebarHtml(false);
    } finally {
        $view->setTemplateMode($mode);
    }
}

/**
 * What sits inside the panel's bordered box, from the body's opening tag to the
 * end of the footer.
 *
 * The clipping this guards against is a property of the markup inside the box,
 * so the assertions have to be made against that and not against the whole
 * sidebar, which carries every other panel on the screen along with it.
 */
function sidebarPanelBody(string $html): string
{
    $start = strpos($html, 'link-audit-panel__body');

    if ($start === false) {
        return '';
    }

    $end = strpos($html, 'link-audit-panel__foot', $start);

    return $end === false ? '' : substr($html, $start, $end - $start);
}

/** How many extract jobs are sitting in the queue. */
function sidebarJobCount(): int
{
    return (int)(new Query())
        ->from([Table::QUEUE])
        ->where(['like', 'job', 'ExtractElementLinks'])
        ->count();
}

/** Settles a stored URL on a verdict, the way the check phase would. */
function sidebarSetStatus(string $url, UrlStatus $status, ?int $httpStatus = null): void
{
    Craft::$app->getDb()->createCommand()
        ->update(
            UrlRecord::tableName(),
            [
                'status' => $status->value,
                'httpStatus' => $httpStatus,
                'dateLastChecked' => Db::prepareDateForDb(new DateTime('now')),
            ],
            ['urlHash' => sha1($url)],
        )
        ->execute();
}

/**
 * Forgets which elements have already been queued this request.
 *
 * The memo behind the hooks is request scoped, and in production a request is
 * one save or one restore. The whole suite is a single process, so a test that
 * wants to watch the same element twice has to put that back to how a second
 * request would find it.
 */
function sidebarForgetQueued(): void
{
    $memo = new ReflectionProperty(LinkAudit::class, '_queuedForExtraction');
    $memo->setAccessible(true);
    $memo->setValue(null, []);
}

beforeEach(function() {
    $this->actingAs(UserFactory::factory()->admin(true)->create());
});

// ---------------------------------------------------------------------------
// What the panel says
// ---------------------------------------------------------------------------

describe('The links panel', function() {
    it('counts what the page holds and names the broken ones', function() {
        $broken = 'https://example.com/sidebar-broken';
        $working = 'https://example.com/sidebar-working';

        $entry = sidebarEntry(sprintf(
            '<p><a href="%s">Broken</a> and <a href="%s">working</a></p>',
            $broken,
            $working,
        ));
        sidebarExtract($entry);

        sidebarSetStatus($broken, UrlStatus::Broken, 404);
        sidebarSetStatus($working, UrlStatus::Ok, 200);

        $html = sidebarHtml($entry);

        expect($html)->toContain('link-audit-panel')
            ->and($html)->toContain('2 of 2')
            ->and($html)->toContain($broken)
            ->and($html)->toContain(sha1($broken))
            ->and($html)->toContain('404')
            // The working one is counted, never named: the panel is a list of
            // things to do, not an inventory.
            ->and($html)->not->toContain($working);
    });

    it('counts only what has actually been asked about', function() {
        $entry = sidebarEntry('<p><a href="https://example.com/sidebar-pending">Pending</a></p>');
        sidebarExtract($entry);

        expect(sidebarHtml($entry))->toContain('0 of 1');
    });

    it('counts a link inside a block against the page it sits on', function() {
        $url = 'https://example.com/sidebar-in-a-block';
        $entry = sidebarEntry();
        $entry->setFieldValue('laBlocks', [
            'new1' => [
                'type' => 'laBlock',
                'fields' => [
                    'laBody' => sprintf('<p><a href="%s">Nested</a></p>', $url),
                ],
            ],
        ]);
        Craft::$app->getElements()->saveElement($entry);
        sidebarExtract($entry);

        sidebarSetStatus($url, UrlStatus::Broken, 404);

        expect(sidebarHtml($entry))->toContain('link-audit-panel')
            ->and(sidebarHtml($entry))->toContain($url);
    });

    it('sits in a container with a header, a body and a footer, like the panels beside it', function() {
        $url = 'https://example.com/sidebar-container';
        $entry = sidebarEntry(sprintf('<p><a href="%s">A link</a></p>', $url));
        sidebarExtract($entry);

        sidebarSetStatus($url, UrlStatus::Broken, 404);

        $html = sidebarHtml($entry);

        expect($html)->toContain('<fieldset>')
            ->and($html)->toContain('link-audit-panel__legend')
            // Craft's own boxed container, the one the accessibility and SEO
            // panels sit in. Without it the panel reads as loose fields.
            ->and($html)->toContain('<div class="meta">')
            ->and($html)->toContain('link-audit-panel__body')
            ->and($html)->toContain('link-audit-panel__foot')
            ->and($html)->toContain('Every broken link on this site');
    });

    it('lays its label rows out itself rather than on classes that hang outside the box', function() {
        // Craft's `.meta > .data` rows carry `margin-inline: var(--neg-padding)`
        // with an `!important` on it, because they are built to stretch flush
        // across the whole details pane. Inside a bordered, rounded container
        // with `overflow: hidden` on it, that pulls the first character of every
        // label past the edge and clips it: "Checked" renders as "hecked".
        //
        // The accessibility panel next to this one does not use those classes in
        // its body either. Neither does this: the rows are laid out here, inside
        // the padding, so there is nothing to pull outwards in the first place.
        $url = 'https://example.com/sidebar-no-clipping';
        $entry = sidebarEntry(sprintf('<p><a href="%s">A link</a></p>', $url));
        sidebarExtract($entry);

        sidebarSetStatus($url, UrlStatus::Broken, 404);

        $html = sidebarHtml($entry);
        $body = sidebarPanelBody($html);

        expect($body)->not->toBe('')
            ->and($body)->not->toContain('class="meta')
            ->and($body)->not->toContain('read-only')
            ->and($body)->not->toContain('class="data"')
            ->and($body)->toContain('link-audit-panel__row')
            // Both labels whole, first character and all.
            ->and($body)->toContain('>Links Checked<')
            ->and($body)->toContain('>Broken<');
    });

    it('says when the page was last checked in the panel header', function() {
        $url = 'https://example.com/sidebar-when';
        $entry = sidebarEntry(sprintf('<p><a href="%s">A link</a></p>', $url));
        sidebarExtract($entry);

        sidebarSetStatus($url, UrlStatus::Ok, 200);

        expect(sidebarHtml($entry))
            ->toContain('link-audit-panel__when')
            ->toContain('checked ' . (new DateTime('now'))->format('d M Y'));
    });

    it('leaves the header meta off a page nothing has been asked about yet', function() {
        $entry = sidebarEntry('<p><a href="https://example.com/sidebar-never-checked">A link</a></p>');
        sidebarExtract($entry);

        expect(sidebarHtml($entry))->not->toContain('link-audit-panel__when');
    });

    it('offers the way through to the full report even on a page with no links', function() {
        $entry = sidebarEntry('<p>Nothing but words.</p>');
        sidebarExtract($entry);

        expect(sidebarHtml($entry))->toContain('Every broken link on this site');
    });

    it('says so plainly when a page holds no links at all', function() {
        $entry = sidebarEntry('<p>Nothing but words.</p>');
        sidebarExtract($entry);

        $html = sidebarHtml($entry);

        expect($html)->toContain('link-audit-panel')
            ->and($html)->toContain('None yet');
    });
});

// ---------------------------------------------------------------------------
// Where the panel stays out of the way
// ---------------------------------------------------------------------------

describe('The links panel guards', function() {
    it('renders nothing on a draft', function() {
        $entry = sidebarEntry('<p><a href="https://example.com/sidebar-draft">A link</a></p>');
        sidebarExtract($entry);

        $author = UserFactory::factory()->create();
        $draft = Craft::$app->getDrafts()->createDraft($entry, (int)$author->id, 'A draft');

        expect(sidebarHtml($draft))->not->toContain('link-audit-panel');
    });

    it('renders nothing on an asset', function() {
        $asset = AssetFactory::factory()->volume('site')->create();

        expect(sidebarHtml($asset))->not->toContain('link-audit-panel');
    });

    it('renders nothing for an element type the scan leaves out', function() {
        $entry = sidebarEntry('<p><a href="https://example.com/sidebar-excluded">A link</a></p>');
        sidebarExtract($entry);

        LinkAudit::getInstance()->getSettings()->scannedElementTypes = [];

        expect(sidebarHtml($entry))->not->toContain('link-audit-panel');
    });

    it('renders nothing on an entry in an excluded section', function() {
        $entry = sidebarEntry('<p><a href="https://example.com/sidebar-section-excluded">A link</a></p>');

        LinkAudit::getInstance()->getSettings()->excludedSectionUids = [
            (string)Craft::$app->getEntries()->getSectionByHandle('laFixture')?->uid,
        ];

        expect(sidebarHtml($entry))->not->toContain('link-audit-panel');
    });

    it('renders nothing for somebody who may not read the reports', function() {
        $entry = sidebarEntry('<p><a href="https://example.com/sidebar-unpermitted">A link</a></p>');
        sidebarExtract($entry);

        $this->actingAs(UserFactory::factory()->create());

        expect(sidebarHtml($entry))->not->toContain('link-audit-panel');
    });
});

// ---------------------------------------------------------------------------
// Restoring
// ---------------------------------------------------------------------------

describe('Restoring an element', function() {
    it('queues one job to read its links again', function() {
        $entry = sidebarEntry('<p><a href="https://example.com/sidebar-restored">A link</a></p>');
        sidebarExtract($entry);

        Craft::$app->getElements()->deleteElement($entry);

        expect((int)(new Query())
            ->from([ReferenceRecord::tableName()])
            ->where(['elementId' => $entry->id])
            ->count())->toBe(0);

        sidebarForgetQueued();
        $before = sidebarJobCount();

        Craft::$app->getElements()->restoreElement($entry);

        expect(sidebarJobCount() - $before)->toBe(1);
    });

    it('queues nothing when scanning on save is off', function() {
        $entry = sidebarEntry('<p><a href="https://example.com/sidebar-restored-off">A link</a></p>');

        Craft::$app->getElements()->deleteElement($entry);

        sidebarForgetQueued();
        LinkAudit::getInstance()->getSettings()->scanOnSave = false;
        $before = sidebarJobCount();

        Craft::$app->getElements()->restoreElement($entry);

        expect(sidebarJobCount() - $before)->toBe(0);
    });
});

describe('The check-this-page-again button', function() {
    it('rereads the page and brings its links forward for a fresh check', function() {
        $entry = sidebarEntry('<p><a href="https://example.com/sidebar-recheck-me">Fix me</a></p>');
        sidebarExtract($entry);

        // A verdict with a long shelf life, so nothing here is stale by
        // accident: only the button's own bring-forward can make it pending.
        sidebarSetStatus('https://example.com/sidebar-recheck-me', UrlStatus::Ok, 200);
        Craft::$app->getDb()->createCommand()
            ->update(
                UrlRecord::tableName(),
                ['nextCheckAfter' => Db::prepareDateForDb(new DateTime('+20 days'))],
                ['urlHash' => sha1('https://example.com/sidebar-recheck-me')],
            )
            ->execute();

        $count = LinkAudit::getInstance()->getScanService()
            ->recheckElementLinks((int)$entry->id, (int)$entry->siteId);

        expect($count)->toBeGreaterThan(0)
            ->and((int)(new Query())
                ->from([UrlRecord::tableName()])
                ->where(['urlHash' => sha1('https://example.com/sidebar-recheck-me')])
                ->andWhere(['<', 'nextCheckAfter', Db::prepareDateForDb(new DateTime())])
                ->count())->toBe(1);
    });
});
