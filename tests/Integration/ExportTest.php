<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\StringHelper;
use johnhenry\linkaudit\console\controllers\ExportController as ConsoleExportController;
use johnhenry\linkaudit\controllers\BaseController;
use johnhenry\linkaudit\enums\UrlStatus;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\models\ExtractedLink;
use johnhenry\linkaudit\models\Verdict;
use johnhenry\linkaudit\records\ReferenceRecord;
use johnhenry\linkaudit\records\ScanRecord;
use johnhenry\linkaudit\records\UrlRecord;
use markhuot\craftpest\factories\Entry as EntryFactory;
use markhuot\craftpest\factories\User as UserFactory;

// ---------------------------------------------------------------------------
// The CSV export
//
// A row here is a reference rather than a URL, which is the thing worth holding
// on to: the file goes to whoever has to open the pages and fix them, so the
// same broken address in three places is three rows and each one names its own
// page.
//
// Two things are asserted harder than the rest. The site fence, because the
// export reads the reference table directly and a fence that only covers the
// screens is no fence at all. And the formula escaping, because every string on
// a row came out of somebody's content, and a CSV handed to an editor is a
// lovely way to get a formula run on their machine.
//
// Helper names carry an `export` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/** Empties the plugin's tables, in foreign key order. */
function exportClearTables(): void
{
    $db = Craft::$app->getDb();

    foreach ([ReferenceRecord::tableName(), UrlRecord::tableName(), ScanRecord::tableName()] as $table) {
        $db->createCommand()->delete($table)->execute();
    }
}

/**
 * An entry in the dedicated laFixture section.
 *
 * The section and its fields live in project config rather than being made here:
 * creating a section auto-commits the transaction RefreshesDatabase relies on.
 */
function exportEntry(): Entry
{
    $section = Craft::$app->getEntries()->getSectionByHandle('laFixture');

    if ($section === null) {
        throw new RuntimeException(
            'The laFixture test section is missing. Run `ddev craft project-config/apply`.',
        );
    }

    $slug = 'la-export-' . StringHelper::toLowerCase(StringHelper::randomString(10));

    return EntryFactory::factory()
        ->section($section)
        ->title('LA export ' . $slug)
        ->slug($slug)
        ->create();
}

/** The whole export as one string, the way a caller would assemble it. */
function exportCsv(UrlStatus $status, array $siteIds, array $filters = []): string
{
    $text = '';

    foreach (LinkAudit::getInstance()->getExportService()->csv($status, $siteIds, $filters) as $chunk) {
        $text .= $chunk;
    }

    return $text;
}

/** The site the fenced reader is not entitled to. */
function exportOtherSite(): craft\models\Site
{
    $primary = Craft::$app->getSites()->getPrimarySite();

    foreach (Craft::$app->getSites()->getAllSites() as $site) {
        if ((int)$site->id !== (int)$primary->id) {
            return $site;
        }
    }

    throw new RuntimeException(
        'The export tests need a second site. Run `ddev craft project-config/apply`.',
    );
}

/** A reader entitled to the primary site and nothing else. */
function exportReader(): User
{
    $user = UserFactory::factory()->create();

    Craft::$app->getUserPermissions()->saveUserPermissions((int)$user->id, [
        'accesscp',
        'accessplugin-link-audit',
        'editsite:' . Craft::$app->getSites()->getPrimarySite()->uid,
        BaseController::PERMISSION_VIEW_REPORTS,
    ]);

    return $user;
}

/**
 * Runs the console command with the given options.
 *
 * Built by hand rather than through the harness's own console helper, which
 * passes its arguments to the action and so cannot set the options: on a Yii
 * console controller an option is a public property. Colour is settled rather
 * than worked out, so nothing goes asking posix_isatty() about the test
 * process's own output.
 */
function exportRunConsole(array $options): int
{
    $controller = new ConsoleExportController('export', LinkAudit::getInstance());
    $controller->color = false;

    foreach ($options as $name => $value) {
        $controller->$name = $value;
    }

    return $controller->actionCsv();
}

/** The parsed rows of a CSV, byte order mark and all taken off the front. */
function exportRows(string $csv): array
{
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, str_replace("\xEF\xBB\xBF", '', $csv));
    rewind($handle);

    $rows = [];

    while (($row = fgetcsv($handle, escape: '')) !== false) {
        $rows[] = $row;
    }

    fclose($handle);

    return $rows;
}

/**
 * A URL with the given verdict, and one reference per element passed in.
 *
 * @param array<int, array<string, mixed>> $refs Each needs `elementId`, and may
 *                                               carry any other reference
 *                                               column.
 */
function exportSeed(string $url, int $siteId, array $refs, UrlStatus $status = UrlStatus::Broken, ?Verdict $verdict = null): int
{
    $store = LinkAudit::getInstance()->getUrlStore();
    $urlId = $store->upsert($url, false);

    $store->recordVerdict($urlId, $verdict ?? new Verdict(
        status: $status,
        httpStatus: 404,
        reason: Verdict::REASON_HTTP,
    ));

    foreach ($refs as $ref) {
        $store->replaceReferencesFor((int)$ref['elementId'], $siteId, [array_merge([
            'urlId' => $urlId,
            'elementType' => User::class,
        ], $ref)]);
    }

    return $urlId;
}

/** Asks the download endpoint for a file. */
function exportRequest(array $params = []): markhuot\craftpest\web\TestableResponse
{
    $query = http_build_query(array_merge([
        'verdict' => UrlStatus::Broken->value,
        'siteId' => exportSiteId(),
    ], $params));

    return test()->http('get', "actions/link-audit/export/csv?$query")->send();
}

/** The primary site id. */
function exportSiteId(): int
{
    return (int)Craft::$app->getSites()->getPrimarySite()->id;
}

/**
 * What the response would put on the wire.
 *
 * Walked here rather than sent, because the test harness deliberately does not
 * send a response: it holds the body where it can be asserted against instead.
 * This is the same walk Yii's own `sendContent()` makes over a callable stream,
 * so what is assembled here is what a browser would be handed.
 */
function exportStreamed(markhuot\craftpest\web\TestableResponse $response): string
{
    $stream = $response->stream;

    expect(is_callable($stream))->toBeTrue();

    $text = '';

    foreach ($stream() as $chunk) {
        $text .= $chunk;
    }

    return $text;
}

beforeEach(function() {
    $this->actingAs(UserFactory::factory()->admin(true)->create());
    exportClearTables();
});

describe('The export service', function() {
    it('writes the header row the columns are named for', function() {
        $rows = exportRows(exportCsv(UrlStatus::Broken, [exportSiteId()]));

        expect($rows[0])->toBe([
            'URL',
            'Verdict',
            'Reason',
            'Response Code',
            'Redirect Code',
            'Goes To',
            'Host',
            'Page',
            'Page Type',
            'Site',
            'Field',
            'Link Text',
            'Found Via',
            'Places Total',
            'First Seen',
            'Last Checked',
        ]);
    });

    // Excel reads a CSV as the machine's own legacy encoding unless the file
    // says otherwise, and this is the only thing it takes as saying so.
    it('puts a byte order mark on the front so Excel reads the accents', function() {
        expect(exportCsv(UrlStatus::Broken, [exportSiteId()]))->toStartWith("\xEF\xBB\xBF");
    });

    it('gives one row to each place a URL appears', function() {
        $one = UserFactory::factory()->create();
        $two = UserFactory::factory()->create();
        $three = UserFactory::factory()->create();

        exportSeed('https://example.com/in-three-places', exportSiteId(), [
            ['elementId' => (int)$one->id],
            ['elementId' => (int)$two->id],
            ['elementId' => (int)$three->id],
        ]);

        $rows = exportRows(exportCsv(UrlStatus::Broken, [exportSiteId()]));

        expect($rows)->toHaveCount(4);

        foreach (array_slice($rows, 1) as $row) {
            expect($row[0])->toBe('https://example.com/in-three-places')
                ->and($row[13])->toBe('3');
        }

        // Three rows, three different pages named on them.
        expect(array_unique(array_column(array_slice($rows, 1), 7)))->toHaveCount(3);
    });

    // The Page column names the page an editor opens, which is the owner. The
    // Field column cannot come off the same element: a field layout can override
    // a field's label, and the override is the only name that editor has ever
    // seen. The block layout here calls LA Body "Block Body" and the page's
    // layout does not, so a file built off the owner names the wrong field.
    it('names a nested link by the label the block layout gives it', function() {
        $entry = exportEntry();
        $entry->setFieldValue('laBlocks', [
            'new1' => [
                'type' => 'laBlock',
                'fields' => [
                    'laBody' => '<p><a href="https://example.com/inside-a-block">Block link</a></p>',
                ],
            ],
        ]);
        Craft::$app->getElements()->saveElement($entry);

        $siteId = (int)$entry->siteId;
        LinkAudit::getInstance()->getScanService()->extractElement((int)$entry->id, Entry::class, $siteId);

        $store = LinkAudit::getInstance()->getUrlStore();
        $urlId = $store->upsert('https://example.com/inside-a-block', false);
        $store->recordVerdict($urlId, new Verdict(status: UrlStatus::Broken, httpStatus: 404));

        $row = exportRows(exportCsv(UrlStatus::Broken, [$siteId]))[1];
        $reference = LinkAudit::getInstance()->getReportService()->references($urlId, [$siteId])[0];

        expect($row[10])->toBe('Block Body')
            // The same label the URL detail screen puts on the same reference.
            ->and($reference['fieldName'])->toBe('Block Body')
            ->and($row[7])->toBe($entry->getUiLabel());
    });

    it('says what the screen says, not what the database holds', function() {
        $user = UserFactory::factory()->create();

        exportSeed('https://example.com/no-answer-here', exportSiteId(), [
            ['elementId' => (int)$user->id, 'source' => ExtractedLink::SOURCE_NAV, 'linkText' => 'Our brochure'],
        ], UrlStatus::Unreachable, new Verdict(
            status: UrlStatus::Unreachable,
            httpStatus: 503,
            reason: Verdict::REASON_TIMEOUT,
        ));

        $row = exportRows(exportCsv(UrlStatus::Unreachable, [exportSiteId()]))[1];

        expect($row[1])->toBe('No Answer')
            ->and($row[2])->toBe('Timed out')
            ->and($row[3])->toBe('503')
            ->and($row[6])->toBe('example.com')
            ->and($row[8])->toBe('User')
            ->and($row[9])->toBe(Craft::$app->getSites()->getPrimarySite()->name)
            ->and($row[11])->toBe('Our brochure')
            ->and($row[12])->toBe('A navigation');
    });

    it('carries both redirect codes and where the link ends up', function() {
        $user = UserFactory::factory()->create();

        exportSeed('https://example.com/moved-for-good', exportSiteId(), [
            ['elementId' => (int)$user->id],
        ], UrlStatus::Redirect, new Verdict(
            status: UrlStatus::Redirect,
            httpStatus: 200,
            finalUrl: 'https://example.com/the-new-place',
            redirectCount: 1,
            redirectPermanent: true,
            redirectStatus: 301,
        ));

        $row = exportRows(exportCsv(UrlStatus::Redirect, [exportSiteId()]))[1];

        expect($row[1])->toBe('Redirect')
            ->and($row[3])->toBe('200')
            ->and($row[4])->toBe('301')
            ->and($row[5])->toBe('https://example.com/the-new-place');
    });

    it('writes the dates for a machine rather than for a sentence', function() {
        exportSeed('https://example.com/dated', exportSiteId(), [
            ['elementId' => (int)UserFactory::factory()->create()->id],
        ]);

        $row = exportRows(exportCsv(UrlStatus::Broken, [exportSiteId()]))[1];

        expect($row[14])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/')
            ->and($row[15])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
    });

    it('keeps each verdict to its own file', function() {
        exportSeed('https://example.com/is-broken', exportSiteId(), [
            ['elementId' => (int)UserFactory::factory()->create()->id],
        ]);
        exportSeed('https://example.com/is-blocked', exportSiteId(), [
            ['elementId' => (int)UserFactory::factory()->create()->id],
        ], UrlStatus::Blocked, new Verdict(status: UrlStatus::Blocked, httpStatus: 403));

        expect(exportCsv(UrlStatus::Broken, [exportSiteId()]))->toContain('is-broken')
            ->and(exportCsv(UrlStatus::Broken, [exportSiteId()]))->not->toContain('is-blocked')
            ->and(exportCsv(UrlStatus::Blocked, [exportSiteId()]))->toContain('is-blocked')
            ->and(exportCsv(UrlStatus::Blocked, [exportSiteId()]))->not->toContain('is-broken');
    });

    it('reads past a batch boundary without dropping or repeating a row', function() {
        $urlId = LinkAudit::getInstance()->getUrlStore()->upsert('https://example.com/on-many-pages', false);

        LinkAudit::getInstance()->getUrlStore()->recordVerdict(
            $urlId,
            new Verdict(status: UrlStatus::Broken, httpStatus: 404),
        );

        // One over a batch, so the cursor has to come back for a second pass and
        // pick up exactly where it left off.
        $wanted = johnhenry\linkaudit\services\ExportService::BATCH_SIZE + 1;
        $elementId = (int)UserFactory::factory()->create()->id;
        $refs = [];

        for ($i = 0; $i < $wanted; $i++) {
            $refs[] = [
                'urlId' => $urlId,
                'elementType' => User::class,
                'fieldHandle' => "field$i",
            ];
        }

        LinkAudit::getInstance()->getUrlStore()->replaceReferencesFor($elementId, exportSiteId(), $refs);

        $rows = array_slice(exportRows(exportCsv(UrlStatus::Broken, [exportSiteId()])), 1);

        expect($rows)->toHaveCount($wanted)
            ->and(array_unique(array_column($rows, 10)))->toHaveCount($wanted);
    });

    it('names the file after the list and the day', function() {
        expect(LinkAudit::getInstance()->getExportService()->filename(UrlStatus::Broken))
            ->toBe('link-audit-broken-' . (new DateTime())->format('Y-m-d') . '.csv');
    });
});

describe('The filters', function() {
    beforeEach(function() {
        exportSeed('https://kept.example/one', exportSiteId(), [
            ['elementId' => (int)UserFactory::factory()->create()->id, 'source' => ExtractedLink::SOURCE_FIELD],
        ]);
        exportSeed('https://dropped.example/two', exportSiteId(), [
            ['elementId' => (int)UserFactory::factory()->create()->id, 'source' => ExtractedLink::SOURCE_RENDERED],
        ]);
    });

    it('honours the host the screen was filtered by', function() {
        $csv = exportCsv(UrlStatus::Broken, [exportSiteId()], ['host' => 'kept.example']);

        expect($csv)->toContain('kept.example/one')
            ->and($csv)->not->toContain('dropped.example/two');
    });

    it('honours where the link came from', function() {
        $csv = exportCsv(UrlStatus::Broken, [exportSiteId()], ['source' => ExtractedLink::SOURCE_RENDERED]);

        expect($csv)->toContain('dropped.example/two')
            ->and($csv)->not->toContain('kept.example/one');
    });

    it('honours what the link was found in', function() {
        $csv = exportCsv(UrlStatus::Broken, [exportSiteId()], ['elementType' => craft\elements\Entry::class]);

        expect(exportRows($csv))->toHaveCount(1);
    });

    it('counts the places the filters leave, not every place there is', function() {
        $urlId = exportSeed('https://counted.example/twice', exportSiteId(), [
            ['elementId' => (int)UserFactory::factory()->create()->id, 'source' => ExtractedLink::SOURCE_FIELD],
            ['elementId' => (int)UserFactory::factory()->create()->id, 'source' => ExtractedLink::SOURCE_NAV],
        ]);

        expect($urlId)->toBeGreaterThan(0);

        $rows = array_slice(
            exportRows(exportCsv(UrlStatus::Broken, [exportSiteId()], ['source' => ExtractedLink::SOURCE_NAV])),
            1,
        );

        expect($rows)->toHaveCount(1)
            ->and($rows[0][13])->toBe('1');
    });

    it('honours what was typed into the table\'s search box', function() {
        $csv = exportCsv(UrlStatus::Broken, [exportSiteId()], ['search' => 'kept']);

        expect($csv)->toContain('kept.example/one')
            ->and($csv)->not->toContain('dropped.example/two');
    });

    it('honours whether a redirect is permanent', function() {
        exportSeed('https://redirects.example/for-good', exportSiteId(), [
            ['elementId' => (int)UserFactory::factory()->create()->id],
        ], UrlStatus::Redirect, new Verdict(
            status: UrlStatus::Redirect,
            httpStatus: 200,
            redirectPermanent: true,
            redirectStatus: 301,
        ));
        exportSeed('https://redirects.example/for-now', exportSiteId(), [
            ['elementId' => (int)UserFactory::factory()->create()->id],
        ], UrlStatus::Redirect, new Verdict(
            status: UrlStatus::Redirect,
            httpStatus: 200,
            redirectPermanent: false,
            redirectStatus: 302,
        ));

        $csv = exportCsv(UrlStatus::Redirect, [exportSiteId()], ['permanent' => '1']);

        expect($csv)->toContain('for-good')
            ->and($csv)->not->toContain('for-now');
    });
});

describe('The download endpoint', function() {
    it('hands the browser a CSV under a name that says what it is', function() {
        exportSeed('https://example.com/downloaded', exportSiteId(), [
            ['elementId' => (int)UserFactory::factory()->create()->id],
        ]);

        $response = exportRequest();

        expect($response->headers->get('content-type'))->toBe('text/csv; charset=UTF-8')
            ->and($response->headers->get('content-disposition'))->toBe(
                'attachment; filename="link-audit-broken-' . (new DateTime())->format('Y-m-d') . '.csv"',
            )
            ->and($response->headers->get('x-content-type-options'))->toBe('nosniff');
    });

    it('streams the rows rather than building the file first', function() {
        exportSeed('https://example.com/streamed', exportSiteId(), [
            ['elementId' => (int)UserFactory::factory()->create()->id],
        ]);

        $response = exportRequest();

        // Nothing has been read at the point the response comes back: the whole
        // file is still a closure waiting to be walked.
        expect($response->content)->toBeEmpty();

        $csv = exportStreamed($response);

        expect($csv)->toStartWith("\xEF\xBB\xBF")
            ->and($csv)->toContain('https://example.com/streamed');
    });

    it('takes the verdict off the request', function() {
        exportSeed('https://example.com/downloaded-redirect', exportSiteId(), [
            ['elementId' => (int)UserFactory::factory()->create()->id],
        ], UrlStatus::Redirect, new Verdict(status: UrlStatus::Redirect, httpStatus: 200, redirectStatus: 301));

        $response = exportRequest(['verdict' => UrlStatus::Redirect->value]);

        expect($response->headers->get('content-disposition'))->toContain('link-audit-redirect-')
            ->and(exportStreamed($response))->toContain('downloaded-redirect');
    });

    it('honours the filters the screen was carrying', function() {
        exportSeed('https://kept.example/downloaded', exportSiteId(), [
            ['elementId' => (int)UserFactory::factory()->create()->id],
        ]);
        exportSeed('https://dropped.example/downloaded', exportSiteId(), [
            ['elementId' => (int)UserFactory::factory()->create()->id],
        ]);

        $csv = exportStreamed(exportRequest(['host' => 'kept.example']));

        expect($csv)->toContain('kept.example/downloaded')
            ->and($csv)->not->toContain('dropped.example/downloaded');
    });

    // The search box lives inside the table component, so it never reaches the
    // page's own URL and the screen's JavaScript puts it on the button's link
    // instead. What matters here is that the endpoint takes it when it arrives.
    it('narrows the file to what the reader searched for', function() {
        exportSeed('https://searched.example/wanted', exportSiteId(), [
            ['elementId' => (int)UserFactory::factory()->create()->id],
        ]);
        exportSeed('https://searched.example/not-this-one', exportSiteId(), [
            ['elementId' => (int)UserFactory::factory()->create()->id],
        ]);

        $csv = exportStreamed(exportRequest(['search' => 'wanted']));

        expect($csv)->toContain('searched.example/wanted')
            ->and($csv)->not->toContain('not-this-one');
    });

    // By the time the rows are read the headers have gone out saying 200 and
    // text/csv, so an exception escaping the stream would put Craft's error page
    // into the file the browser is already writing. A short file is a failure
    // somebody notices; a spreadsheet with a page of HTML at the bottom of it is
    // not.
    it('stops cleanly rather than writing an error page into the file', function() {
        $plugin = LinkAudit::getInstance();
        $original = $plugin->getExportService();

        $plugin->set('exportService', new class extends johnhenry\linkaudit\services\ExportService {
            public function csv(UrlStatus $status, array $siteIds, array $filters = []): Generator
            {
                yield "URL\r\n";
                yield "https://example.com/written-before-it-broke\r\n";

                throw new RuntimeException('The database went away mid-read.');
            }
        });

        try {
            $csv = exportStreamed(exportRequest());
        } finally {
            $plugin->set('exportService', $original);
        }

        expect($csv)->toBe("URL\r\nhttps://example.com/written-before-it-broke\r\n");
    });

    it('refuses a reader without the report permission', function() {
        $user = UserFactory::factory()->create();

        Craft::$app->getUserPermissions()->saveUserPermissions((int)$user->id, [
            'accesscp',
            'accessplugin-link-audit',
            'editsite:' . Craft::$app->getSites()->getPrimarySite()->uid,
        ]);

        $this->actingAs($user);

        expect(fn() => exportRequest())->toThrow(yii\web\ForbiddenHttpException::class);
    });
});

describe('The button on a list screen', function() {
    it('sits in the filter bar carrying the screen and its filters', function() {
        exportSeed('https://buttoned.example/one', exportSiteId(), [
            ['elementId' => (int)UserFactory::factory()->create()->id],
        ]);

        $this->get('admin/link-audit/broken?host=buttoned.example')
            ->assertOk()
            ->assertSee('Download CSV')
            ->assertSee('data-icon="download"', false)
            ->assertSee('link-audit/export/csv', false)
            ->assertSee('verdict=broken', false)
            ->assertSee('host=buttoned.example', false);
    });

    // The whole filter bar goes with the table when there is nothing to list,
    // and a button offering to download an empty file goes with it.
    it('is not offered when the screen has nothing on it', function() {
        $this->get('admin/link-audit/broken')
            ->assertOk()
            ->assertDontSee('Download CSV');
    });

    // A filter left empty is left off the link rather than sent as a blank, so
    // the URL says only what the screen is actually filtered by. `permanent=0`
    // is a real answer and has to survive that, which Twig's own `empty` test
    // would not have let it do.
    it('keeps a temporary-only filter on the link', function() {
        exportSeed('https://buttoned.example/temporary', exportSiteId(), [
            ['elementId' => (int)UserFactory::factory()->create()->id],
        ], UrlStatus::Redirect, new Verdict(
            status: UrlStatus::Redirect,
            httpStatus: 200,
            redirectPermanent: false,
            redirectStatus: 302,
        ));

        $this->get('admin/link-audit/redirects?permanent=0')
            ->assertOk()
            ->assertSee('permanent=0', false)
            ->assertDontSee('host=&', false);
    });
});

describe('The site fence on a download', function() {
    // The export reads the reference table directly rather than going through
    // the list screens, so a fence that only covers the screens would be no
    // fence at all: a reader entitled to one site could ask for another site's
    // id and be handed its content in a file.
    it('never hands a reader a site they may not edit', function() {
        exportSeed('https://example.com/mine-to-download', exportSiteId(), [
            ['elementId' => (int)UserFactory::factory()->create()->id],
        ]);
        exportSeed('https://example.com/not-mine-to-download', (int)exportOtherSite()->id, [
            ['elementId' => (int)UserFactory::factory()->create()->id],
        ]);

        $this->actingAs(exportReader());

        // Asking outright for the site they are not entitled to. The id is
        // clamped back to one they are, rather than honoured.
        $csv = exportStreamed(exportRequest(['siteId' => (int)exportOtherSite()->id]));

        expect($csv)->toContain('mine-to-download')
            ->and($csv)->not->toContain('not-mine-to-download');
    });

    it('gives an admin every site they ask for', function() {
        exportSeed('https://example.com/admins-download-everything', (int)exportOtherSite()->id, [
            ['elementId' => (int)UserFactory::factory()->create()->id],
        ]);

        $csv = exportStreamed(exportRequest(['siteId' => (int)exportOtherSite()->id]));

        expect($csv)->toContain('admins-download-everything');
    });
});

describe('The console command', function() {
    it('writes the same file the button hands over', function() {
        exportSeed('https://console.example/exported', exportSiteId(), [
            ['elementId' => (int)UserFactory::factory()->create()->id, 'linkText' => 'The brochure'],
        ]);

        $path = Craft::$app->getPath()->getTempPath() . '/link-audit-console-export.csv';
        $exitCode = exportRunConsole(['file' => $path]);

        expect($exitCode)->toBe(yii\console\ExitCode::OK);

        $written = (string)file_get_contents($path);
        unlink($path);

        expect($written)->toBe(exportCsv(UrlStatus::Broken, Craft::$app->getSites()->getAllSiteIds()));
    });

    // No site fence on the console, so left to itself it covers the lot. That
    // is the difference from the control panel, and it is worth pinning down.
    it('covers every site until it is told otherwise', function() {
        exportSeed('https://console.example/on-the-primary', exportSiteId(), [
            ['elementId' => (int)UserFactory::factory()->create()->id],
        ]);
        exportSeed('https://console.example/on-the-other', (int)exportOtherSite()->id, [
            ['elementId' => (int)UserFactory::factory()->create()->id],
        ]);

        $path = Craft::$app->getPath()->getTempPath() . '/link-audit-console-every-site.csv';

        exportRunConsole(['file' => $path]);
        $everySite = (string)file_get_contents($path);

        exportRunConsole(['file' => $path, 'site' => Craft::$app->getSites()->getPrimarySite()->handle]);
        $oneSite = (string)file_get_contents($path);

        unlink($path);

        expect($everySite)->toContain('on-the-primary')
            ->and($everySite)->toContain('on-the-other')
            ->and($oneSite)->toContain('on-the-primary')
            ->and($oneSite)->not->toContain('on-the-other');
    });

    it('says what it wants when it is given no file', function() {
        expect(exportRunConsole([]))->toBe(yii\console\ExitCode::USAGE);
    });

    it('refuses a verdict that is not one', function() {
        expect(exportRunConsole([
            'file' => Craft::$app->getPath()->getTempPath() . '/link-audit-never-written.csv',
            'status' => 'catastrophic',
        ]))->toBe(yii\console\ExitCode::USAGE);
    });

    it('says so rather than half-writing when the path is no good', function() {
        expect(exportRunConsole(['file' => '/no/such/directory/broken.csv']))
            ->toBe(yii\console\ExitCode::IOERR);
    });

    it('will not take a site handle nobody has', function() {
        expect(fn() => exportRunConsole([
            'file' => Craft::$app->getPath()->getTempPath() . '/link-audit-never-written.csv',
            'site' => 'no-such-site',
        ]))->toThrow(yii\console\Exception::class);
    });
});

describe('A cell a spreadsheet would run', function() {
    // Everything on a row came out of somebody's content, so a URL, a link text
    // or a page title is whatever an author, or whoever got at their content,
    // decided to put in it. The apostrophe is the spreadsheets' own way of
    // saying "this is text": it does not show in the cell and it survives the
    // trip back out again.
    it('is quoted so it stays text', function() {
        exportSeed('https://example.com/formula', exportSiteId(), [
            [
                'elementId' => (int)UserFactory::factory()->create()->id,
                'linkText' => '=cmd|\' /c calc\'!A1',
            ],
        ]);

        $row = exportRows(exportCsv(UrlStatus::Broken, [exportSiteId()]))[1];

        expect($row[11])->toBe("'=cmd|' /c calc'!A1");
    });

    it('covers every leader a spreadsheet acts on', function() {
        $leaders = ['=', '+', '-', '@', "\t", "\r"];

        foreach ($leaders as $i => $leader) {
            exportSeed("https://leaders.example/take-$i", exportSiteId(), [
                [
                    'elementId' => (int)UserFactory::factory()->create()->id,
                    'linkText' => $leader . 'SUM(A1:A9)',
                ],
            ]);
        }

        $rows = array_slice(exportRows(exportCsv(UrlStatus::Broken, [exportSiteId()])), 1);

        expect($rows)->toHaveCount(count($leaders));

        foreach ($rows as $row) {
            expect($row[11])->toStartWith("'");
        }
    });

    it('leaves an ordinary cell alone', function() {
        exportSeed('https://example.com/ordinary', exportSiteId(), [
            ['elementId' => (int)UserFactory::factory()->create()->id, 'linkText' => 'Read the brochure'],
        ]);

        $row = exportRows(exportCsv(UrlStatus::Broken, [exportSiteId()]))[1];

        expect($row[11])->toBe('Read the brochure')
            ->and($row[0])->toBe('https://example.com/ordinary');
    });
});
