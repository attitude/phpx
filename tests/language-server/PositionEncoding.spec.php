<?php declare(strict_types=1);

/**
 * Position encoding conversion.
 *
 * Internal position math is byte-based; the LSP boundary speaks UTF-16 code
 * units (the default encoding negotiated with the shipped VS Code client).
 * PositionEncoding converts between the two, and the Server applies it to every
 * incoming Position and every outgoing Position/Range.
 */

use Attitude\PHPX\LanguageServer\Message;
use Attitude\PHPX\LanguageServer\PositionEncoding;
use Attitude\PHPX\LanguageServer\Server;
use Attitude\PHPX\LanguageServer\Transport;

// ── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Fresh server (utf-16 client — no positionEncodings offered) plus its captured
 * output stream.
 *
 * @return array{Server, resource}
 */
function utf16Server(): array
{
    $output = fopen('php://memory', 'r+');
    $server = new Server(new Transport(fopen('php://memory', 'r+'), $output));
    $server->handleMessage(Message::request(0, 'initialize', ['capabilities' => []]));
    rewind($output);
    ftruncate($output, 0);

    return [$server, $output];
}

/**
 * @param resource $output
 * @return Message[]
 */
function drainOutput($output): array
{
    rewind($output);
    $transport = new Transport($output, fopen('php://memory', 'r+'));
    $messages = [];
    while (($msg = $transport->read()) !== null) {
        $messages[] = $msg;
    }
    return $messages;
}

// ── Unit: conversion ─────────────────────────────────────────────────────────

describe('PositionEncoding', function () {
    it('is identity for ASCII', function () {
        $line = 'className';
        expect(PositionEncoding::byteToUtf16($line, 5))->toBe(5);
        expect(PositionEncoding::utf16ToByte($line, 5))->toBe(5);
    });

    it('counts a 2-byte char (café) as one UTF-16 unit', function () {
        $line = 'café'; // é = U+00E9, 2 bytes, 1 UTF-16 unit
        expect(strlen($line))->toBe(5);
        expect(PositionEncoding::byteToUtf16($line, 5))->toBe(4);
        expect(PositionEncoding::utf16ToByte($line, 4))->toBe(5);
    });

    it('counts an astral char (😀) as two UTF-16 units', function () {
        $line = '😀'; // U+1F600, 4 bytes, surrogate pair = 2 UTF-16 units
        expect(strlen($line))->toBe(4);
        expect(PositionEncoding::byteToUtf16($line, 4))->toBe(2);
        expect(PositionEncoding::utf16ToByte($line, 2))->toBe(4);
    });

    it('handles a mixed line', function () {
        $line = 'a😀b café'; // a | 😀(2) | b | space | c a f é(1)
        // byte offset of the trailing 'é' region end == full length
        expect(PositionEncoding::byteToUtf16($line, strlen($line)))->toBe(9);
        // 'b' sits right after the astral char: byte 5 → utf16 3
        expect(PositionEncoding::byteToUtf16($line, 5))->toBe(3);
        expect(PositionEncoding::utf16ToByte($line, 3))->toBe(5);
    });

    it('round-trips at every character boundary', function () {
        $line = 'x😀ycafé😀z';
        for ($b = 0; $b <= strlen($line); $b++) {
            // only test real character boundaries (skip continuation bytes)
            if ($b < strlen($line) && (ord($line[$b]) & 0xC0) === 0x80) {
                continue;
            }
            $u = PositionEncoding::byteToUtf16($line, $b);
            expect(PositionEncoding::utf16ToByte($line, $u))->toBe($b);
        }
    });

    it('clamps offsets past the end of the line', function () {
        $line = 'café';
        expect(PositionEncoding::utf16ToByte($line, 100))->toBe(5);
        expect(PositionEncoding::byteToUtf16($line, 100))->toBe(4);
    });
});

// ── Integration: utf-16 boundary conversion ──────────────────────────────────

describe('Server utf-16 position conversion', function () {
    it('hovers a tag preceded by multibyte text', function () {
        [$server, $output] = utf16Server();

        // '😀😀' before the tag shifts byte offsets ahead of UTF-16 offsets.
        $server->handleMessage(Message::notification('textDocument/didOpen', [
            'textDocument' => [
                'uri' => 'file:///m.phpx',
                'languageId' => 'phpx',
                'version' => 1,
                'text' => '<?php $s="😀😀"; $y=<div className="x" />;',
            ],
        ]));
        rewind($output);
        ftruncate($output, 0);

        // UTF-16 offset of 'div' is 21 (byte offset is 25).
        $server->handleMessage(Message::request(1, 'textDocument/hover', [
            'textDocument' => ['uri' => 'file:///m.phpx'],
            'position' => ['line' => 0, 'character' => 21],
        ]));

        $messages = drainOutput($output);
        expect($messages)->toHaveCount(1);
        expect($messages[0]->result)->not->toBeNull();
        expect($messages[0]->result['contents']['kind'])->toBe('markdown');
        // Range is returned in UTF-16 units: div spans 21..24.
        expect($messages[0]->result['range']['start']['character'])->toBe(21);
        expect($messages[0]->result['range']['end']['character'])->toBe(24);
    });

    it('completes an attribute preceded by multibyte text', function () {
        [$server, $output] = utf16Server();

        $server->handleMessage(Message::notification('textDocument/didOpen', [
            'textDocument' => [
                'uri' => 'file:///m.phpx',
                'languageId' => 'phpx',
                'version' => 1,
                'text' => '<?php $s="😀😀"; $y=<div className="x" />;',
            ],
        ]));
        rewind($output);
        ftruncate($output, 0);

        // UTF-16 offset just after the 'cl' of 'className' is 27 (byte 31).
        $server->handleMessage(Message::request(2, 'textDocument/completion', [
            'textDocument' => ['uri' => 'file:///m.phpx'],
            'position' => ['line' => 0, 'character' => 27],
        ]));

        $messages = drainOutput($output);
        expect($messages)->toHaveCount(1);
        $labels = array_column($messages[0]->result, 'label');
        expect($labels)->toContain('className');
    });

    it('renames a tag with correct UTF-16 ranges on a line with a 2-byte char', function () {
        [$server, $output] = utf16Server();

        $server->handleMessage(Message::notification('textDocument/didOpen', [
            'textDocument' => [
                'uri' => 'file:///r.phpx',
                'languageId' => 'phpx',
                'version' => 1,
                'text' => '<?php $s="café"; $y=<div>hi</div>;',
            ],
        ]));
        rewind($output);
        ftruncate($output, 0);

        // UTF-16 offset 21 is 'div' (byte 22 — the 'é' costs 2 bytes but 1 unit).
        $server->handleMessage(Message::request(3, 'textDocument/rename', [
            'textDocument' => ['uri' => 'file:///r.phpx'],
            'position' => ['line' => 0, 'character' => 21],
            'newName' => 'section',
        ]));

        $messages = drainOutput($output);
        expect($messages)->toHaveCount(1);

        $edits = $messages[0]->result['changes']['file:///r.phpx'];
        expect($edits)->toHaveCount(2);

        $ranges = array_map(
            fn($e) => [$e['range']['start']['character'], $e['range']['end']['character']],
            $edits,
        );
        expect($ranges)->toContain([21, 24]); // opening <div>
        expect($ranges)->toContain([29, 32]); // closing </div>
    });
});
