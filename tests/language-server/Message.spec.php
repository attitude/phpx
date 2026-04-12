<?php declare(strict_types = 1);

use Attitude\PHPX\LanguageServer\Message;

describe('Message', function () {
    describe('request()', function () {
        it('creates a request with integer id', function () {
            $msg = Message::request(1, 'initialize', ['capabilities' => []]);

            expect($msg->jsonrpc)->toBe('2.0');
            expect($msg->id)->toBe(1);
            expect($msg->method)->toBe('initialize');
            expect($msg->params)->toBe(['capabilities' => []]);
            expect($msg->result)->toBeNull();
            expect($msg->error)->toBeNull();
        });

        it('creates a request with string id', function () {
            $msg = Message::request('abc-123', 'textDocument/hover');

            expect($msg->id)->toBe('abc-123');
            expect($msg->method)->toBe('textDocument/hover');
            expect($msg->params)->toBeNull();
        });

        it('is identified as a request', function () {
            $msg = Message::request(1, 'initialize');

            expect($msg->isRequest())->toBeTrue();
            expect($msg->isNotification())->toBeFalse();
            expect($msg->isResponse())->toBeFalse();
        });
    });

    describe('notification()', function () {
        it('creates a notification without an id', function () {
            $msg = Message::notification('initialized', ['foo' => 'bar']);

            expect($msg->jsonrpc)->toBe('2.0');
            expect($msg->id)->toBeNull();
            expect($msg->method)->toBe('initialized');
            expect($msg->params)->toBe(['foo' => 'bar']);
        });

        it('creates a notification without params', function () {
            $msg = Message::notification('exit');

            expect($msg->params)->toBeNull();
        });

        it('is identified as a notification', function () {
            $msg = Message::notification('initialized');

            expect($msg->isNotification())->toBeTrue();
            expect($msg->isRequest())->toBeFalse();
            expect($msg->isResponse())->toBeFalse();
        });
    });

    describe('response()', function () {
        it('creates a response with a result', function () {
            $msg = Message::response(1, ['capabilities' => []]);

            expect($msg->jsonrpc)->toBe('2.0');
            expect($msg->id)->toBe(1);
            expect($msg->method)->toBeNull();
            expect($msg->result)->toBe(['capabilities' => []]);
            expect($msg->error)->toBeNull();
        });

        it('creates a response with null result', function () {
            $msg = Message::response(1, null);

            expect($msg->id)->toBe(1);
            expect($msg->result)->toBeNull();
        });

        it('is identified as a response', function () {
            $msg = Message::response(1, 'ok');

            expect($msg->isResponse())->toBeTrue();
            expect($msg->isRequest())->toBeFalse();
            expect($msg->isNotification())->toBeFalse();
        });
    });

    describe('error()', function () {
        it('creates an error response', function () {
            $msg = Message::error(1, -32601, 'Method not found');

            expect($msg->jsonrpc)->toBe('2.0');
            expect($msg->id)->toBe(1);
            expect($msg->error)->toBe(['code' => -32601, 'message' => 'Method not found']);
            expect($msg->result)->toBeNull();
        });

        it('includes optional data in the error', function () {
            $msg = Message::error(1, -32602, 'Invalid params', ['detail' => 'missing field']);

            expect($msg->error)->toBe([
                'code' => -32602,
                'message' => 'Invalid params',
                'data' => ['detail' => 'missing field'],
            ]);
        });

        it('excludes null data from the error', function () {
            $msg = Message::error(1, -32601, 'Method not found', null);

            expect($msg->error)->not->toHaveKey('data');
        });
    });

    describe('fromArray()', function () {
        it('reconstructs a request from array', function () {
            $msg = Message::fromArray([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => ['capabilities' => []],
            ]);

            expect($msg->isRequest())->toBeTrue();
            expect($msg->id)->toBe(1);
            expect($msg->method)->toBe('initialize');
        });

        it('reconstructs a notification from array', function () {
            $msg = Message::fromArray([
                'jsonrpc' => '2.0',
                'method' => 'exit',
            ]);

            expect($msg->isNotification())->toBeTrue();
            expect($msg->id)->toBeNull();
        });

        it('handles missing fields with null defaults', function () {
            $msg = Message::fromArray([]);

            expect($msg->jsonrpc)->toBeNull();
            expect($msg->id)->toBeNull();
            expect($msg->method)->toBeNull();
            expect($msg->params)->toBeNull();
        });
    });

    describe('toArray()', function () {
        it('serializes a request', function () {
            $msg = Message::request(1, 'initialize', ['capabilities' => []]);

            expect($msg->toArray())->toBe([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => ['capabilities' => []],
            ]);
        });

        it('serializes a notification without id', function () {
            $msg = Message::notification('exit');
            $array = $msg->toArray();

            expect($array)->toHaveKey('jsonrpc');
            expect($array)->toHaveKey('method');
            expect($array)->not->toHaveKey('id');
            expect($array)->not->toHaveKey('params');
        });

        it('serializes a response with result', function () {
            $msg = Message::response(1, ['data' => true]);

            expect($msg->toArray())->toBe([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => ['data' => true],
            ]);
        });

        it('serializes an error response with error instead of result', function () {
            $msg = Message::error(1, -32601, 'Method not found');
            $array = $msg->toArray();

            expect($array)->toHaveKey('error');
            expect($array)->not->toHaveKey('result');
            expect($array['error']['code'])->toBe(-32601);
        });

        it('serializes a response with null result', function () {
            $msg = Message::response(1, null);
            $array = $msg->toArray();

            expect($array)->toHaveKey('result');
            expect($array['result'])->toBeNull();
        });
    });
});
