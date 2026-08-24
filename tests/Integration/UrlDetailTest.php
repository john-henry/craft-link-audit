<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\records\ReferenceRecord;
use johnhenry\linkaudit\records\ScanRecord;
use johnhenry\linkaudit\records\UrlRecord;
use markhuot\craftpest\factories\Entry as EntryFactory;
use markhuot\craftpest\factories\User as UserFactory;
use yii\web\NotFoundHttpException;

// ---------------------------------------------------------------------------
// The URL detail page
//
// The reference list is the part that earns its keep, and the Edit link is the
// part that quietly goes wrong: a link inside a Matrix block belongs to the
// block for storage purposes and to the entry for editing purposes, and an
// author offered a link to the block gets a page they cannot open.
//
// Helper names carry a `detail` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/**
 * An entry in the dedicated laFixture section.
 *
 * The section and its fields live in project config rather than being made here:
 * creating a section auto-commits the transaction RefreshesDatabase relies on.
 */
function detailEntry(): Entry
{
    $section = Craft::$app->getEntries()->getSectionByHandle('laFixture');

    if ($section === null) {
        throw new RuntimeException(
            'The laFixture test section is missing. Run `ddev craft project-config/apply`.',
        );
    }

    $slug = 'la-detail-' . StringHelper::toLowerCase(StringHelper::randomString(10));

    return EntryFactory::factory()
        ->section($section)
        ->title('LA detail ' . $slug)
        ->slug($slug)
        ->create();
}

/** Empties the plugin's tables, in foreign key order. */
function detailClearTables(): void
{
    $db = Craft::$app->getDb();

    foreach ([ReferenceRecord::tableName(), UrlRecord::tableName(), ScanRecord::tableName()] as $table) {
        $db->createCommand()->delete($table)->execute();
    }
}

/** The detail page URL for a link. */
function detailPath(string $url): string
{
    return 'admin/link-audit/url?hash=' . sha1($url);
}

beforeEach(function() {
    $this->actingAs(UserFactory::factory()->admin(true)->create());
    detailClearTables();
});

describe('UrlsController::actionDetail', function() {
    it('offers the parent entry for a link inside a Matrix block', function() {
        $entry = detailEntry();
        $entry->setFieldValue('laBlocks', [
            'new1' => [
                'type' => 'laBlock',
                'fields' => [
                    'laBody' => '<p><a href="https://example.com/in-a-block">Block link</a></p>',
                ],
            ],
        ]);
        Craft::$app->getElements()->saveElement($entry);

        $siteId = (int)$entry->siteId;
        LinkAudit::getInstance()->getScanService()->extractElement((int)$entry->id, Entry::class, $siteId);

        $block = $entry->getFieldValue('laBlocks')->one();

        expect($block)->not->toBeNull()
            ->and($block->id)->not->toBe($entry->id);

        $response = $this->get(detailPath('https://example.com/in-a-block'));

        $response->assertOk()
            ->assertSee('Where it appears')
            ->assertSee('Block link')
            // The Edit link opens the entry the author can actually edit, not
            // the block the link was stored against.
            ->assertSee($entry->getCpEditUrl())
            ->assertSee('(in a block)');
    });

    it('shows the verdict it holds', function() {
        $entry = detailEntry();
        $entry->setFieldValue('laBody', '<p><a href="https://example.com/plain">Plain</a></p>');
        Craft::$app->getElements()->saveElement($entry);

        LinkAudit::getInstance()->getScanService()
            ->extractElement((int)$entry->id, Entry::class, (int)$entry->siteId);

        $this->get(detailPath('https://example.com/plain'))
            ->assertOk()
            ->assertSee('Not checked yet')
            ->assertSee('https://example.com/plain');
    });

    // What the checker stores is libcurl's own vocabulary, error number and
    // documentation link and all. The row keeps every character of it; the page
    // says the part an editor can act on and puts the rest behind the disclosure.
    it('says a transport failure plainly and keeps the raw string behind the detail', function() {
        $store = LinkAudit::getInstance()->getUrlStore();
        $url = 'https://gone-host.example/curl';
        $urlId = $store->upsert($url, false);

        $store->replaceReferencesFor(
            (int)UserFactory::factory()->create()->id,
            (int)Craft::$app->getSites()->getPrimarySite()->id,
            [['urlId' => $urlId, 'elementType' => craft\elements\User::class]],
        );

        $raw = 'cURL error 6: Could not resolve host: gone-host.example '
            . '(see https://curl.haxx.se/libcurl/c/libcurl-errors.html)';

        Db::update(
            UrlRecord::tableName(),
            ['message' => $raw, 'method' => 'head'],
            ['id' => $urlId],
        );

        $this->get(detailPath($url))
            ->assertOk()
            ->assertSee('Could not resolve host: gone-host.example')
            ->assertSee('Asked with')
            ->assertSee('curl.haxx.se/libcurl/c/libcurl-errors.html')
            // The error number is only ever met inside the disclosure: What Came
            // Back carries the cleaned sentence and nothing else.
            ->assertSeeInOrder([
                'What Came Back',
                'Technical Detail',
                'The message as it arrived',
                'cURL error 6:',
            ]);
    });

    it('leaves a message that is not a transport failure exactly as it is', function() {
        $store = LinkAudit::getInstance()->getUrlStore();
        $url = 'https://plainspoken.example/message';
        $urlId = $store->upsert($url, false);

        $store->replaceReferencesFor(
            (int)UserFactory::factory()->create()->id,
            (int)Craft::$app->getSites()->getPrimarySite()->id,
            [['urlId' => $urlId, 'elementType' => craft\elements\User::class]],
        );

        Db::update(
            UrlRecord::tableName(),
            ['message' => 'The server answered with a 500.'],
            ['id' => $urlId],
        );

        $this->get(detailPath($url))
            ->assertOk()
            ->assertSee('The server answered with a 500.')
            ->assertDontSee('The message as it arrived');
    });

    it('shows the redirect code and the final one side by side', function() {
        $store = LinkAudit::getInstance()->getUrlStore();
        $url = 'https://example.com/detail-redirect';
        $urlId = $store->upsert($url, false);

        $store->replaceReferencesFor(
            (int)UserFactory::factory()->create()->id,
            (int)Craft::$app->getSites()->getPrimarySite()->id,
            [['urlId' => $urlId, 'elementType' => craft\elements\User::class]],
        );

        $store->recordVerdict($urlId, new johnhenry\linkaudit\models\Verdict(
            status: johnhenry\linkaudit\enums\UrlStatus::Redirect,
            httpStatus: 200,
            method: 'head',
            finalUrl: 'https://example.com/detail-new-address',
            redirectCount: 1,
            redirectPermanent: true,
            redirectStatus: 301,
        ));

        $this->get(detailPath($url))
            ->assertOk()
            ->assertSee('link-audit-code--3xx" title="Moved permanently: update the link to the new address">301', false)
            ->assertSee('link-audit-code--2xx" title="Answered normally">200', false)
            ->assertSee('what the address answered with, then where it ended up')
            ->assertDontSee('the code the server answered with');
    });

    // A row checked before the redirect code was kept holds none, and reads
    // exactly as it always did until its next check.
    it('shows the one code when the row holds no redirect code', function() {
        $store = LinkAudit::getInstance()->getUrlStore();
        $url = 'https://example.com/detail-plain-code';
        $urlId = $store->upsert($url, false);

        $store->replaceReferencesFor(
            (int)UserFactory::factory()->create()->id,
            (int)Craft::$app->getSites()->getPrimarySite()->id,
            [['urlId' => $urlId, 'elementType' => craft\elements\User::class]],
        );

        $store->recordVerdict($urlId, new johnhenry\linkaudit\models\Verdict(
            status: johnhenry\linkaudit\enums\UrlStatus::Broken,
            httpStatus: 404,
            method: 'head',
        ));

        $this->get(detailPath($url))
            ->assertOk()
            ->assertSee('link-audit-code--4xx" title="Not found: nothing lives at that address">404', false)
            ->assertSee('the code the server answered with');
    });

    it('answers a hash nobody recognises with a 404', function() {
        expect(fn() => $this->get('admin/link-audit/url?hash=' . str_repeat('a', 40)))
            ->toThrow(NotFoundHttpException::class);
    });

    it('answers a missing hash with a 404 rather than an error', function() {
        expect(fn() => $this->get('admin/link-audit/url'))
            ->toThrow(NotFoundHttpException::class);
    });

    it('prints a URL with a scheme it never follows rather than linking to it', function() {
        // A scheme the plugin does not check is still stored, exactly as the
        // author typed it, so they can see it was met. Drawn as an anchor it
        // would put a `javascript:` value one click away inside the control
        // panel, under whatever privileges the reader holds.
        $store = LinkAudit::getInstance()->getUrlStore();
        $url = 'javascript:alert(document.cookie)';
        $urlId = $store->upsert($url, false, null, johnhenry\linkaudit\enums\UrlStatus::Ignored);

        $store->replaceReferencesFor(
            (int)UserFactory::factory()->create()->id,
            (int)Craft::$app->getSites()->getPrimarySite()->id,
            [['urlId' => $urlId, 'elementType' => craft\elements\User::class]],
        );

        $this->get(detailPath($url))
            ->assertOk()
            ->assertSee('alert(document.cookie)')
            ->assertDontSee('href="javascript:', false);
    });

    it('links to a URL with a scheme it does follow', function() {
        $entry = detailEntry();
        $entry->setFieldValue('laBody', '<p><a href="https://example.com/openable">Openable</a></p>');
        Craft::$app->getElements()->saveElement($entry);

        LinkAudit::getInstance()->getScanService()
            ->extractElement((int)$entry->id, Entry::class, (int)$entry->siteId);

        $this->get(detailPath('https://example.com/openable'))
            ->assertOk()
            ->assertSee('href="https://example.com/openable"', false);
    });

    it('shows a busy state on the buttons that post and wait', function() {
        $entry = detailEntry();
        $entry->setFieldValue('laBody', '<p><a href="https://example.com/busy-buttons">Busy</a></p>');
        Craft::$app->getElements()->saveElement($entry);

        LinkAudit::getInstance()->getScanService()
            ->extractElement((int)$entry->id, Entry::class, (int)$entry->siteId);

        $this->get(detailPath('https://example.com/busy-buttons'))
            ->assertOk()
            ->assertSee('data-link-audit-submit', false)
            ->assertSee('spinner spinner-absolute', false)
            ->assertSee("button.classList.add('loading', 'disabled');", false);
    });

    it('flashes the outcome back onto the page after an ignore posted from a form', function() {
        $entry = detailEntry();
        $entry->setFieldValue('laBody', '<p><a href="https://example.com/flashed">Flashed</a></p>');
        Craft::$app->getElements()->saveElement($entry);

        LinkAudit::getInstance()->getScanService()
            ->extractElement((int)$entry->id, Entry::class, (int)$entry->siteId);

        $hash = sha1('https://example.com/flashed');

        // A plain form post, the way the page works with JavaScript off: the
        // answer is a redirect carrying a flash rather than a JSON body.
        $this->post('actions/link-audit/url-actions/ignore', [
            'hash' => $hash,
            'note' => 'Not worth chasing.',
            'redirect' => Craft::$app->getSecurity()->hashData("link-audit/url?hash=$hash"),
        ])->assertRedirect();

        // Craft stores a control panel success under its own notification key
        // and an ordinary one under `success`, depending on how the request was
        // recognised. Either way there has to be something waiting for the page
        // the redirect lands on, or pressing the button looks like nothing
        // happened.
        $session = Craft::$app->getSession();
        $flash = $session->getFlash('cp-notification-success') ?? $session->getFlash('success');
        $message = is_array($flash) ? (string)$flash[0] : (string)$flash;

        expect($message)->toContain('will not be reported again');
    });

    it('hides the Edit link from somebody who may not view the element', function() {
        $entry = detailEntry();
        $entry->setFieldValue('laBody', '<p><a href="https://example.com/fenced">Fenced</a></p>');
        Craft::$app->getElements()->saveElement($entry);

        LinkAudit::getInstance()->getScanService()
            ->extractElement((int)$entry->id, Entry::class, (int)$entry->siteId);

        $reader = UserFactory::factory()->create();
        Craft::$app->getUserPermissions()->saveUserPermissions((int)$reader->id, [
            'accesscp',
            'accessplugin-link-audit',
            'editsite:' . Craft::$app->getSites()->getPrimarySite()->uid,
            'link-audit:viewreports',
        ]);
        $this->actingAs($reader);

        $this->get(detailPath('https://example.com/fenced'))
            ->assertOk()
            // The row is still listed: hiding it entirely would make the count
            // lie. Only the link to a page they cannot open is withheld.
            ->assertSee('Fenced')
            ->assertDontSee($entry->getCpEditUrl());
    });
});
