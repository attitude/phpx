<?php declare(strict_types=1);

namespace Attitude\PHPX\LanguageServer;

/**
 * Thrown by Message::fromArray() when a decoded JSON frame is well-formed JSON
 * but violates the JSON-RPC message shape (e.g. a non-string method, a float
 * id, a non-object params). The frame body has already been consumed, so the
 * stream stays in sync — the server answers -32600 Invalid Request and
 * continues its read loop.
 */
final class InvalidMessageException extends \RuntimeException
{
    /**
     * @param int|string|null $recoverableId The request id to echo back in the
     *   error response, or null when the id itself is missing/invalid.
     */
    public function __construct(
        public readonly int|string|null $recoverableId,
        string $message,
    ) {
        parent::__construct($message);
    }
}
