<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\exceptions;

use Throwable;
use yii\base\Exception;

/**
 * Thrown when a scan of the content is asked for while one is already running.
 *
 * Two full runs at once are two workers rebuilding the same reference rows,
 * pruning each other's findings and taking rows out of the pending set the other
 * one is paging through.
 *
 * The id of the run already going is carried twice: in the message, which the
 * console prints and the scheduler logs, and as [[$scanId]] for anything that
 * would rather read it than pick it out of a sentence. The control panel needs
 * neither, because it refuses the start and sends the reader back to the page
 * the running scan is already reporting its progress on.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class ScanInProgressException extends Exception
{
    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * @var int The scan that is already running.
     */
    public int $scanId;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Constructor.
     *
     * @param int $scanId The scan that is already running.
     * @param int $code The exception code.
     * @param Throwable|null $previous The previous exception, if any.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function __construct(int $scanId, int $code = 0, ?Throwable $previous = null)
    {
        $this->scanId = $scanId;

        parent::__construct(
            "Scan $scanId is still running, so nothing was queued. Wait for it to finish before starting another.",
            $code,
            $previous,
        );
    }

    /**
     * @inheritdoc
     *
     * @return string The user-friendly name of this exception.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getName(): string
    {
        return 'Scan in progress';
    }
}
