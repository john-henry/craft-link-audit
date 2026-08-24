<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use johnhenry\linkaudit\jobs\ExtractElementLinks;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\records\ReferenceRecord;
use johnhenry\linkaudit\records\UrlRecord;
use markhuot\craftpest\factories\Entry as EntryFactory;
use markhuot\craftpest\factories\User as UserFactory;
use yii\queue\sync\Queue as SyncQueue;

// ---------------------------------------------------------------------------
// Saving an element
//
// Almost all of this is about what does not happen. The on save hook earns its
// keep by staying out of the way: a resave of the whole site, an autosaved
// draft and a revision are all element saves, and a plugin that queues a job for
// each of them is a plugin somebody turns off.
//
// Helper names carry an `onSave` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/**
 * One fixture entry carrying the given rich text.
 *
 * The slug is passed in where a test needs the URI to be predictable, since the
 * fixture section addresses entries as `la-fixture/{slug}`.
 */
function onSaveEntry(string $html, ?string $slug = null): Entry
{
    $section = Craft::$app->getEntries()->getSectionByHandle('laFixture');

    if ($section === null) {
        throw new RuntimeException(
            'The laFixture test section is missing. Run `ddev craft project-config/apply`.',
        );
    }

    $slug ??= 'la-on-save-' . StringHelper::toLowerCase(StringHelper::randomString(12));

    return EntryFactory::factory()
        ->section($section)
        ->title('LA on save ' . $slug)
        ->slug($slug)
        ->set('laBody', $html)
        ->create();
}

/**
 * How many extract jobs are sitting in the queue.
 *
 * Matched on the class name inside the serialised job rather than on the
 * description, which is translated. Counted rather than asserted absolutely:
 * this runs against a development database that carries whatever the last real
 * save left behind.
 */
function onSaveJobCount(): int
{
    return (int)(new Query())
        ->from([Table::QUEUE])
        ->where(['like', 'job', 'ExtractElementLinks'])
        ->count();
}

/**
 * Forgets which elements have already been queued this request.
 *
 * The memo behind the hook is request scoped, and in production a request is one
 * save. The whole suite is a single process, so a test that wants to watch the
 * same element being saved twice has to put that back to how a second request
 * would find it.
 */
function onSaveForgetQueued(): void
{
    $memo = new ReflectionProperty(LinkAudit::class, '_queuedForExtraction');
    $memo->setAccessible(true);
    $memo->setValue(null, []);
}

/** Runs the on save job by hand, the way a worker would. */
function onSaveRunJob(int $elementId): void
{
    (new ExtractElementLinks(['elementId' => $elementId]))->execute(new SyncQueue());
}

/** How many reference rows point at an element, across every site. */
function onSaveReferenceCount(int $elementId): int
{
    return (int)(new Query())
        ->from([ReferenceRecord::tableName()])
        ->where(['elementId' => $elementId])
        ->count();
}

/** The URL row for a stored URL, or null when there is none. */
function onSaveUrlRow(string $url): ?array
{
    /** @var array<string, mixed>|null $row */
    $row = (new Query())
        ->from([UrlRecord::tableName()])
        ->where(['url' => $url])
        ->one() ?: null;

    return $row;
}

// ---------------------------------------------------------------------------
// What gets queued
// ---------------------------------------------------------------------------

it('queues one job when an entry is saved', function() {
    $before = onSaveJobCount();

    onSaveEntry('<p><a href="https://example.com/on-save-queued">A link</a></p>');

    expect(onSaveJobCount() - $before)->toBe(1);
});

it('queues nothing more when the same element is saved again in the one request', function() {
    $entry = onSaveEntry('<p><a href="https://example.com/on-save-memo">A link</a></p>');

    $before = onSaveJobCount();

    $entry->title = $entry->title . ' again';
    Craft::$app->getElements()->saveElement($entry);

    expect(onSaveJobCount() - $before)->toBe(0);
});

it('queues the page rather than the block when a block is saved', function() {
    $entry = onSaveEntry('<p><a href="https://example.com/on-save-owner">Owner</a></p>');
    $entry->setFieldValue('laBlocks', [
        'new1' => [
            'type' => 'laBlock',
            'fields' => [
                'laBody' => '<p><a href="https://example.com/on-save-block">Nested</a></p>',
            ],
        ],
    ]);
    Craft::$app->getElements()->saveElement($entry);

    $block = $entry->getFieldValue('laBlocks')->site('*')->one();

    expect($block)->not->toBeNull();

    $before = onSaveJobCount();

    Craft::$app->getElements()->saveElement($block);

    // Nothing, because the block resolved back to the page it sits on and the
    // page was queued when it was saved. Queue the block in its own right and
    // this would be one.
    expect(onSaveJobCount() - $before)->toBe(0);
});

it('queues nothing for a draft', function() {
    $entry = onSaveEntry('<p><a href="https://example.com/on-save-draft">A link</a></p>');
    $author = UserFactory::factory()->create();

    $before = onSaveJobCount();

    Craft::$app->getDrafts()->createDraft($entry, (int)$author->id, 'A draft');

    expect(onSaveJobCount() - $before)->toBe(0);
});

it('queues nothing for a bulk resave', function() {
    $entry = onSaveEntry('<p><a href="https://example.com/on-save-resave">A link</a></p>');
    onSaveForgetQueued();

    $before = onSaveJobCount();

    $entry->resaving = true;
    Craft::$app->getElements()->saveElement($entry);

    expect(onSaveJobCount() - $before)->toBe(0);
});

it('queues nothing when scanning on save is off', function() {
    LinkAudit::getInstance()->getSettings()->scanOnSave = false;

    $before = onSaveJobCount();

    onSaveEntry('<p><a href="https://example.com/on-save-off">A link</a></p>');

    expect(onSaveJobCount() - $before)->toBe(0);
});

it('queues nothing for an element whose URI is excluded', function() {
    LinkAudit::getInstance()->getSettings()->excludedUriPatterns = [
        ['uriPattern' => '^la-fixture/la-on-save-excluded', 'siteId' => '', 'enabled' => true],
    ];

    $before = onSaveJobCount();

    onSaveEntry(
        '<p><a href="https://example.com/on-save-excluded">A link</a></p>',
        'la-on-save-excluded-' . StringHelper::toLowerCase(StringHelper::randomString(8)),
    );

    expect(onSaveJobCount() - $before)->toBe(0);
});

// ---------------------------------------------------------------------------
// What the job does
// ---------------------------------------------------------------------------

it('stores the references and leaves the URL waiting, without a scan', function() {
    $url = 'https://example.com/on-save-' . StringHelper::toLowerCase(StringHelper::randomString(10));
    $entry = onSaveEntry(sprintf('<p><a href="%s">A link</a></p>', $url));

    onSaveRunJob((int)$entry->id);

    $row = onSaveUrlRow($url);

    expect(onSaveReferenceCount((int)$entry->id))->toBeGreaterThan(0)
        ->and($row)->not->toBeNull()
        // Pending on purpose: an editor's save is not the moment to fire off
        // outbound requests. The next check phase settles it.
        ->and($row['status'])->toBe('pending')
        ->and($row['dateLastChecked'])->toBeNull();
});

it('rebuilds the references rather than adding to them', function() {
    $url = 'https://example.com/on-save-' . StringHelper::toLowerCase(StringHelper::randomString(10));
    $entry = onSaveEntry(sprintf('<p><a href="%s">A link</a></p>', $url));

    onSaveRunJob((int)$entry->id);
    $first = onSaveReferenceCount((int)$entry->id);

    $entry->setFieldValue('laBody', '<p>The link is gone.</p>');
    Craft::$app->getElements()->saveElement($entry);
    onSaveRunJob((int)$entry->id);

    expect($first)->toBeGreaterThan(0)
        ->and(onSaveReferenceCount((int)$entry->id))->toBe(0);
});

// ---------------------------------------------------------------------------
// Deleting
// ---------------------------------------------------------------------------

it('clears an element references when it is deleted', function() {
    $url = 'https://example.com/on-save-' . StringHelper::toLowerCase(StringHelper::randomString(10));
    $entry = onSaveEntry(sprintf('<p><a href="%s">A link</a></p>', $url));

    onSaveRunJob((int)$entry->id);

    expect(onSaveReferenceCount((int)$entry->id))->toBeGreaterThan(0);

    Craft::$app->getElements()->deleteElement($entry);

    expect(onSaveReferenceCount((int)$entry->id))->toBe(0)
        // A delete in Craft is a soft one, so the URL row only goes because the
        // plugin cleared up after it.
        ->and(onSaveUrlRow($url))->toBeNull();
});

it('leaves a URL alone when another element still points at it', function() {
    $url = 'https://example.com/on-save-' . StringHelper::toLowerCase(StringHelper::randomString(10));
    $html = sprintf('<p><a href="%s">Shared</a></p>', $url);

    $first = onSaveEntry($html);
    $second = onSaveEntry($html);

    onSaveRunJob((int)$first->id);
    onSaveRunJob((int)$second->id);

    Craft::$app->getElements()->deleteElement($first);

    expect(onSaveReferenceCount((int)$first->id))->toBe(0)
        ->and(onSaveReferenceCount((int)$second->id))->toBeGreaterThan(0)
        ->and(onSaveUrlRow($url))->not->toBeNull();
});
