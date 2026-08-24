<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\enums;

/**
 * What kind of thing an extracted link points at, and therefore how it gets
 * checked.
 *
 * The extractor decides this once, from the content, and nothing downstream has
 * to guess again: an external link goes to the HTTP checker; an internal one, an
 * element one and a relation one are all answered from the database without a
 * request; and an ignored one is recorded so the author can see it was met and
 * left alone.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
enum LinkKind: string
{
    // =========================================================================
    // Cases
    // =========================================================================

    /**
     * An http(s) URL on a host that is not one of this installation's sites.
     * The only kind that costs a request.
     */
    case External = 'external';

    /**
     * An http(s) URL on one of this installation's sites. Resolved against
     * elements and routes first, and only fetched when neither of those
     * accounts for the address.
     */
    case Internal = 'internal';

    /**
     * A link stored as an element rather than a URL, where the author asserted a
     * hyperlink exists: a Link field in entry mode, a Hyper element link, a
     * navigation node pointing at an entry, or a reference tag. Answered by
     * whether the element still exists, is enabled, and has a URL, since a
     * URL-less target breaks the link exactly as an absent one would.
     */
    case Element = 'element';

    /**
     * An element picked by a relation field, where nothing was ever typed as a
     * hyperlink. Answered by whether the element still exists and is enabled,
     * nothing more: a target with no URL of its own is not a defect here, since
     * the author never asserted it had a page to send anybody to. See
     * {@see \johnhenry\linkaudit\services\InternalResolver::resolveElement()}.
     */
    case Relation = 'relation';

    /**
     * A scheme the plugin does not check. Recorded so it is visible in the
     * report without ever being called broken.
     */
    case Ignored = 'ignored';
}
