<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\enums;

/**
 * What a link's scheme means to the checker.
 *
 * Three answers, because "we cannot check this" and "we do not know what this
 * is" call for different rows. A `mailto:` is not a finding and never appears in
 * the report; an `ftp:` is recorded as ignored, so an author can see the plugin
 * met it and chose not to check it.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
enum SchemeKind: string
{
    // =========================================================================
    // Cases
    // =========================================================================

    /**
     * http, https, or a relative reference that resolves to one of them.
     */
    case Checkable = 'checkable';

    /**
     * mailto, tel, sms, javascript, data, or a bare fragment. Not a link to a
     * document, so nothing is recorded.
     */
    case Skippable = 'skippable';

    /**
     * Any other scheme. Recorded with a status of ignored, so it is visible
     * without being reported as broken.
     */
    case Unknown = 'unknown';
}
