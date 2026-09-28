<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\linkaudit\helpers;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * A response sink that keeps the first bytes of a body and discards the rest.
 *
 * Writes past the cap report success, so the transfer carries on to the
 * request's own time limit rather than failing, but nothing more is held in
 * memory or written to disk. A server sending an endless or decompressing body
 * costs time, never space.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class CappedStream implements StreamInterface
{
    // =========================================================================
    // Traits
    // =========================================================================

    use StreamDecoratorTrait;

    // =========================================================================
    // Private Properties
    // =========================================================================

    /**
     * @var StreamInterface The stream the kept bytes go into.
     */
    private StreamInterface $stream;

    /**
     * @var int How many bytes are kept.
     */
    private int $_cap;

    /**
     * @var int How many bytes have been kept so far.
     */
    private int $_kept = 0;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @param int $cap How many bytes to keep.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function __construct(int $cap)
    {
        $this->_cap = max(0, $cap);
        $this->stream = Utils::streamFor(fopen('php://memory', 'r+b'));
    }

    /**
     * Keeps as much of the chunk as fits under the cap.
     *
     * @param string $string The chunk.
     * @return int The chunk's full length, so the transfer isn't treated as failed.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function write($string): int
    {
        $room = $this->_cap - $this->_kept;

        if ($room > 0) {
            $this->_kept += $this->stream->write(substr($string, 0, $room));
        }

        return strlen($string);
    }
}
