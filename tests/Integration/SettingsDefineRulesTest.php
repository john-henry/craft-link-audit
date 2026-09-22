<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

use craft\base\Model;
use craft\events\DefineRulesEvent;
use johnhenry\linkaudit\models\SettingsModel;
use yii\base\Event;

// ---------------------------------------------------------------------------
// Craft's Model::rules() is what fires EVENT_DEFINE_RULES; the rules themselves
// belong in defineRules(). A model that declares its rules by overriding
// rules() outright still validates, so nothing looks wrong, but the event never
// fires and the documented way to extend a model's validation silently does
// nothing to it.
// ---------------------------------------------------------------------------

it('still validates its own rules', function() {
    $settings = new SettingsModel();
    $settings->maxRedirects = 999;

    expect($settings->validate(['maxRedirects']))->toBeFalse();

    $settings = new SettingsModel();
    $settings->maxRedirects = 5;

    expect($settings->validate(['maxRedirects']))->toBeTrue();
});

it('lets a module add a rule through defineRules', function() {
    $handler = static function(DefineRulesEvent $event): void {
        $event->rules[] = [['maxRedirects'], 'compare', 'compareValue' => 2, 'operator' => '<='];
    };

    Event::on(SettingsModel::class, Model::EVENT_DEFINE_RULES, $handler);

    try {
        $settings = new SettingsModel();
        $settings->maxRedirects = 5;

        // Valid against the plugin's own rules, refused by the added one: the
        // event reached the set the validator actually ran.
        expect($settings->validate(['maxRedirects']))->toBeFalse();
    } finally {
        Event::off(SettingsModel::class, Model::EVENT_DEFINE_RULES, $handler);
    }

    $settings = new SettingsModel();
    $settings->maxRedirects = 5;

    expect($settings->validate(['maxRedirects']))->toBeTrue();
});
