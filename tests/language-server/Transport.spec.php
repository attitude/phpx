<?php declare(strict_types = 1);

use Attitude\PHPX\LanguageServer\Message;
use Attitude\PHPX\LanguageServer\Transport;

describe('Transport', function () {
    describe('write()', function () {
        it('writes a message with Content-Length header and JSON body', function () {
            $output = fopen('php://memory', 'r+');
            $input = fopen('php://memory', 'r+');
            $transport = new Transport($input, $output);

            $msg = Message::request(1, 'initialize', ['capabilities' => []]);
            $transport->write($msg);

            rewind($output);
            $written = stream_get_contents($output);

            $json = json_encode($msg->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $expectedLength = strlen($json);

            expect($written)->toBe("Content-Length: {$expectedLength}\r\n\r\n{$json}");

            fclose($input);
            fclose($output);
        });

        it('writes a notification message', function () {
            $output = fopen('php://memory', 'r+');
            $input = fopen('php://memory', 'r+');
            $transport = new Transport($input, $output);

            $msg = Message::notification('exit');
            $transport->write($msg);

            rewind($output);
            $written = stream_get_contents($output);

            expect($written)->toContain('Content-Length:');
            expect($written)->toContain('"method":"exit"');

            fclose($input);
            fclose($output);
        });
    });

    describe('read()', function () {
        it('reads a valid LSP message from input', function () {
            $input = fopen('php://memory', 'r+');
            $output = fopen('php://memory', 'r+');

            $json = json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [],
            ]);
            $length = strlen($json);

            fwrite($input, "Content-Length: {$length}\r\n\r\n{$json}");
            rewind($input);

            $transport = new Transport($input, $output);
            $msg = $transport->read();

            expect($msg)->not->toBeNull();
            expect($msg->isRequest())->toBeTrue();
            expect($msg->method)->toBe('initialize');
            expect($msg->id)->toBe(1);

            fclose($input);
            fclose($output);
        });

        it('returns null on empty input', function () {
            $input = fopen('php://memory', 'r+');
            $output = fopen('php://memory', 'r+');

            $transport = new Transport($input, $output);
            $msg = $transport->read();

            expect($msg)->toBeNull();

            fclose($input);
            fclose($output);
        });

        it('handles a roundtrip write then read', function () {
            $stream = fopen('php://memory', 'r+');
            $devnull = fopen('php://memory', 'r+');

            // Write to stream
            $writeTransport = new Transport($devnull, $stream);
            $original = Message::request(42, 'textDocument/hover', ['uri' => 'file:///test.phpx']);
            $writeTransport->write($original);

            // Read from same stream
            rewind($stream);
            $readTransport = new Transport($stream, $devnull);
            $received = $readTransport->read();

            expect($received)->not->toBeNull();
            expect($received->id)->toBe(42);
            expect($received->method)->toBe('textDocument/hover');
            expect($received->params)->toBe(['uri' => 'file:///test.phpx']);

            fclose($stream);
            fclose($devnull);
        });

        it('reads a notification message', function () {
            $input = fopen('php://memory', 'r+');
            $output = fopen('php://memory', 'r+');

            $json = json_encode([
                'jsonrpc' => '2.0',
                'method' => 'initialized',
            ]);
            $length = strlen($json);

            fwrite($input, "Content-Length: {$length}\r\n\r\n{$json}");
            rewind($input);

            $transport = new Transport($input, $output);
            $msg = $transport->read();

            expect($msg)->not->toBeNull();
            expect($msg->isNotification())->toBeTrue();

            fclose($input);
            fclose($output);
        });
    });
});
