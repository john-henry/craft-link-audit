<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\Entry;
use johnhenry\linkaudit\LinkAudit;
use johnhenry\linkaudit\services\UrlStore;
use yii\base\Event;

// ---------------------------------------------------------------------------
// The content hooks are not allowed to fail a save or a delete
//
// The save hook runs inside the save's own transaction, and the delete hook
// right after the delete. Anything either throws would reach the author as
// their page failing to save or delete, so both catch and log instead.
//
// Helper names carry a `resilience` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/** An entry whose root owner lookup throws, standing in for any failure in the save hook's work. */
function resilienceThrowingEntry(): Entry
{
    $entry = new class() extends Entry {
        public function getRootOwner(): ElementInterface
        {
            throw new RuntimeException('Root owner lookup failed.');
        }
    };
    $entry->id = 999999;
    $entry->siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;

    return $entry;
}

it('keeps a failure in the save hook away from the save', function() {
    LinkAudit::getInstance()->getSettings()->scanOnSave = true;

    $hook = new ReflectionMethod(LinkAudit::class, '_onContentChange');

    expect(fn() => $hook->invoke(null, new Event(['sender' => resilienceThrowingEntry()])))
        ->not->toThrow(Throwable::class);
});

it('keeps a failure in the delete hook away from the delete', function() {
    $plugin = LinkAudit::getInstance();
    $original = $plugin->getUrlStore();

    $plugin->set('urlStore', new class() extends UrlStore {
        public function deleteReferencesForElement(int $elementId): int
        {
            throw new RuntimeException('Reference cleanup failed.');
        }
    });

    try {
        $entry = new Entry();
        $entry->id = 999999;

        expect(fn() => $entry->trigger(Element::EVENT_AFTER_DELETE, new Event()))
            ->not->toThrow(Throwable::class);
    } finally {
        $plugin->set('urlStore', $original);
    }
});
