<?php declare(strict_types=1);

/**
 * Malformed-frame resilience.
 *
 * A frame that is valid JSON but violates the JSON-RPC shape (non-string
 * method, float/array/bool id, non-object params/error) must never crash the
 * server. Message::fromArray() rejects the shape with an InvalidMessageException
 * instead of letting the strict-typed constructor raise an uncaught TypeError,
 * and Server::run() answers -32600 and keeps reading the next frame.
 */

use Attitude\PHPX\LanguageServer\Message;
use Attitude\PHPX\LanguageServer\InvalidMessageException;
use Attitude\PHPX\LanguageServer\Server;
use Attitude\PHPX\LanguageServer\Transport;

// ── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Wrap a raw JSON body (possibly malformed in shape) in an LSP frame.
 */
function rawFrame(string $json): string
{
    return "Content-Length: " . strlen($json) . "\r\n\r\n" . $json;
}

/**
 * Wrap a Message in an LSP frame.
 */
function messageFrame(Message $message): string
{
    return rawFrame(json_encode($message->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/**
 * Run raw wire bytes through a fresh Server and return the response Messages.
 *
 * @return Message[]
 */
function runRawSession(string $inputData): array
{
    $input = fopen('php://memory', 'r+');
    fwrite($input, $inputData);
    rewind($input);

    $output = fopen('php://memory', 'r+');

    (new Server(new Transport($input, $output)))->run();

    rewind($output);
    $readTransport = new Transport($output, fopen('php://memory', 'r+'));
    $responses = [];
    while (($msg = $readTransport->read()) !== null) {
        $responses[] = $msg;
    }

    return $responses;
}

// ── fromArray shape validation ───────────────────────────────────────────────

describe('Message::fromArray validation', function () {
    it('rejects non-object params, echoing the recoverable id', function () {
        try {
            Message::fromArray(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'x', 'params' => 'notanobject']);
            expect(false)->toBeTrue('expected InvalidMessageException');
        } catch (InvalidMessageException $e) {
            expect($e->recoverableId)->toBe(1);
        }
    });

    it('rejects a fractional float id (unrecoverable)', function () {
        try {
            Message::fromArray(['jsonrpc' => '2.0', 'id' => 1.5, 'method' => 'x']);
            expect(false)->toBeTrue('expected InvalidMessageException');
        } catch (InvalidMessageException $e) {
            expect($e->recoverableId)->toBeNull();
        }
    });

    it('coerces an integral float id to int', function () {
        $msg = Message::fromArray(['jsonrpc' => '2.0', 'id' => 2.0, 'method' => 'x']);
        expect($msg->id)->toBe(2);
    });

    it('rejects an array id', function () {
        expect(fn() => Message::fromArray(['id' => [1], 'method' => 'x']))
            ->toThrow(InvalidMessageException::class);
    });

    it('rejects a boolean id', function () {
        expect(fn() => Message::fromArray(['id' => true, 'method' => 'x']))
            ->toThrow(InvalidMessageException::class);
    });

    it('rejects a non-string method, echoing the recoverable id', function () {
        try {
            Message::fromArray(['jsonrpc' => '2.0', 'id' => 4, 'method' => 5]);
            expect(false)->toBeTrue('expected InvalidMessageException');
        } catch (InvalidMessageException $e) {
            expect($e->recoverableId)->toBe(4);
        }
    });

    it('rejects a non-object error', function () {
        expect(fn() => Message::fromArray(['id' => 3, 'error' => 'oops']))
            ->toThrow(InvalidMessageException::class);
    });

    it('still accepts a well-formed empty frame', function () {
        $msg = Message::fromArray([]);
        expect($msg->id)->toBeNull();
        expect($msg->method)->toBeNull();
    });
});

// ── Server loop survives malformed frames ────────────────────────────────────

describe('Server::run malformed-frame resilience', function () {
    it('answers -32600 for a malformed frame and still serves the next request', function (string $badBody) {
        // initialize (valid) → malformed frame → shutdown (valid)
        $input = messageFrame(Message::request(1, 'initialize', ['capabilities' => []]))
               . rawFrame($badBody)
               . messageFrame(Message::request(99, 'shutdown'));

        $responses = runRawSession($input);

        // The trailing shutdown must have been answered — proof the loop survived.
        $shutdown = array_values(array_filter($responses, fn($r) => $r->id === 99 && $r->isResponse()));
        expect($shutdown)->toHaveCount(1);

        // And an Invalid Request error was emitted for the malformed frame.
        $invalid = array_values(array_filter($responses, fn($r) => $r->error !== null && $r->error['code'] === -32600));
        expect($invalid)->not->toBeEmpty();
    })->with([
        'params as string' => ['{"jsonrpc":"2.0","id":7,"method":"x","params":"notanobject"}'],
        'id as float'      => ['{"jsonrpc":"2.0","id":1.5,"method":"x"}'],
        'id as array'      => ['{"jsonrpc":"2.0","id":[1],"method":"x"}'],
        'id as bool'       => ['{"jsonrpc":"2.0","id":true,"method":"x"}'],
        'error as string'  => ['{"jsonrpc":"2.0","id":8,"error":"oops"}'],
        'method as number' => ['{"jsonrpc":"2.0","id":9,"method":5}'],
    ]);

    it('recovers the id in the -32600 response when the id is well-formed', function () {
        $input = messageFrame(Message::request(1, 'initialize', ['capabilities' => []]))
               . rawFrame('{"jsonrpc":"2.0","id":42,"method":"x","params":"notanobject"}')
               . messageFrame(Message::request(99, 'shutdown'));

        $responses = runRawSession($input);

        $invalid = array_values(array_filter($responses, fn($r) => $r->error !== null && $r->error['code'] === -32600));
        expect($invalid)->toHaveCount(1);
        expect($invalid[0]->id)->toBe(42);
    });
});
