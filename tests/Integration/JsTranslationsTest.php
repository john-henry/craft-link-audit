<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use johnhenry\linkaudit\controllers\BaseController;
use markhuot\craftpest\factories\User as UserFactory;
use yii\i18n\MessageSource;

// ---------------------------------------------------------------------------
// The strings the control panel JavaScript asks for
//
// Craft.t() translates against whatever the page was told about before it
// rendered, and nothing else. A category nothing has been registered under is
// not a fallback, it is a silent passthrough: the string comes out in English
// whatever language the reader picked, and no amount of filling in the
// translation file changes it.
//
// So two things are worth pinning down. That the screens really do register,
// which is the wiring, and that the list they register has not drifted from the
// templates it is for, which is the thing that will go wrong later.
//
// Helper names carry a `jsT` prefix: Pest loads every test file into one
// process, so a bare helper name would collide with another file's.
// ---------------------------------------------------------------------------

/** Every string the control panel templates hand to Craft.t. */
function jsTTemplateStrings(): array
{
    $keys = [];

    foreach (['index.twig', '_includes/url-table.twig'] as $file) {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/src/templates/' . $file);

        preg_match_all("/Craft\\.t\\('link-audit', '((?:[^'\\\\]|\\\\.)*)'/", $source, $matches);

        foreach ($matches[1] as $match) {
            $keys[] = str_replace("\\'", "'", $match);
        }
    }

    sort($keys);

    return array_values(array_unique($keys));
}

/**
 * Puts a real translation behind one of the plugin's strings, and hands back
 * the way to put things as they were.
 *
 * Every string in the plugin's own English file translates to itself, and
 * Craft leaves a string alone when translating it changes nothing, so on an
 * English install the registration writes an empty blob and there is nothing to
 * assert against. One genuinely translated string is enough to see the wiring.
 */
function jsTTranslate(string $message, string $translation): callable
{
    $i18n = Craft::$app->getI18n();
    $original = $i18n->translations['link-audit'];

    $source = new class extends MessageSource {
        /** @var array<string, string> The one message this stands in for. */
        public array $messages = [];

        protected function loadMessages($category, $language): array
        {
            return $this->messages;
        }
    };

    // Yii skips a source outright when the language asked for is the one the
    // messages are written in, which on this install is every request.
    $source->forceTranslation = true;
    $source->messages = [$message => $translation];

    $i18n->translations['link-audit'] = $source;

    return static function() use ($i18n, $original): void {
        $i18n->translations['link-audit'] = $original;
    };
}

describe('The registered strings', function() {
    it('are exactly the ones the templates ask Craft.t for', function() {
        $registered = BaseController::JS_TRANSLATIONS;
        sort($registered);

        expect($registered)->toBe(jsTTemplateStrings());
    });
});

describe('The screens', function() {
    beforeEach(function() {
        $this->actingAs(UserFactory::factory()->admin(true)->create());
    });

    it('hand a list screen its strings so Craft.t can find them', function() {
        $restore = jsTTranslate('URL copied.', 'Seoladh cóipeáilte.');

        try {
            $this->get('admin/link-audit/broken')
                ->assertOk()
                ->assertSee('Craft.translations["link-audit"]["URL copied."] = "Seoladh c', false);
        } finally {
            $restore();
        }
    });

    it('hand the overview its strings too', function() {
        $restore = jsTTranslate('Host', 'Óstach');

        try {
            $this->get('admin/link-audit')
                ->assertOk()
                ->assertSee('Craft.translations["link-audit"]["Host"] = "', false);
        } finally {
            $restore();
        }
    });
});
