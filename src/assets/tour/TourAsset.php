<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\assets\tour;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * The guided tour library.
 *
 * Driver.js, vendored into `dist/` rather than pulled off a CDN or built by a
 * bundler. A control panel is somebody's private admin screen, and it has no
 * business making a request to a third party's server to draw a tooltip; the
 * plugin has no build step either, so a bundler would be a whole toolchain
 * added for two files.
 *
 * The bundle carries the library and nothing else. What the tour actually says
 * is built in PHP and handed to the page, so the steps stay translatable and
 * this stays a plain dependency.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class TourAsset extends AssetBundle
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return void
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->depends = [
            CpAsset::class,
        ];

        $this->css = [
            'driver.css',
        ];

        $this->js = [
            'driver.js.iife.js',
        ];

        parent::init();
    }
}
