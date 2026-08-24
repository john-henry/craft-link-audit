<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\exceptions;

use Throwable;
use yii\base\Exception;

/**
 * Thrown when the SSRF guard refuses a URL.
 *
 * Carries a [[$reason]] as well as a message, because the caller has to tell one
 * refusal from another: a host that resolves to a private address is a security
 * finding worth showing the author, while a host that does not resolve at all is
 * an ordinary broken link.
 *
 * @author John Henry Donovan
 * @since 1.0.0
 */
class UnsafeUrlException extends Exception
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * The host does not resolve to any address.
     */
    public const REASON_DNS = 'dns';

    /**
     * The URL could not be parsed.
     */
    public const REASON_MALFORMED = 'malformed';

    /**
     * The host resolves to a private, loopback, link-local or otherwise reserved
     * address.
     */
    public const REASON_PRIVATE_IP = 'private-ip';

    /**
     * The URL uses a scheme that is never fetched.
     */
    public const REASON_SCHEME = 'scheme';

    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @var string Why the URL was refused, one of the REASON_* constants.
     */
    public string $reason;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Constructor.
     *
     * @param string $message What was wrong with the URL.
     * @param string $reason One of the REASON_* constants.
     * @param int $code The exception code.
     * @param Throwable|null $previous The previous exception, if any.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function __construct(
        string $message,
        string $reason = self::REASON_PRIVATE_IP,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        $this->reason = $reason;

        parent::__construct($message, $code, $previous);
    }

    /**
     * @inheritdoc
     *
     * @return string The user-friendly name of this exception.
     * @author John Henry Donovan
     * @since 1.0.0
     */
    public function getName(): string
    {
        return 'Unsafe URL';
    }
}
