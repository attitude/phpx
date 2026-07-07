<?php declare(strict_types=1);

namespace Attitude\PHPX\LanguageServer;

final class Message
{
    private function __construct(
        public readonly ?string $jsonrpc,
        public readonly ?string $method,
        public readonly int|string|null $id,
        public readonly array|null $params,
        public readonly mixed $result,
        public readonly array|null $error,
    ) {}

    public static function request(int|string $id, string $method, ?array $params = null): self
    {
        return new self(
            jsonrpc: '2.0',
            method: $method,
            id: $id,
            params: $params,
            result: null,
            error: null,
        );
    }

    public static function notification(string $method, ?array $params = null): self
    {
        return new self(
            jsonrpc: '2.0',
            method: $method,
            id: null,
            params: $params,
            result: null,
            error: null,
        );
    }

    public static function response(int|string $id, mixed $result): self
    {
        return new self(
            jsonrpc: '2.0',
            method: null,
            id: $id,
            params: null,
            result: $result,
            error: null,
        );
    }

    public static function error(int|string|null $id, int $code, string $message, mixed $data = null): self
    {
        return new self(
            jsonrpc: '2.0',
            method: null,
            id: $id,
            params: null,
            result: null,
            error: array_filter(['code' => $code, 'message' => $message, 'data' => $data], fn($v) => $v !== null),
        );
    }

    /**
     * Build a Message from a decoded JSON frame, validating the JSON-RPC shape.
     *
     * The strict-typed constructor would raise an uncaught TypeError (killing
     * the server) on a malformed field, so each field is checked here first.
     * A violation throws InvalidMessageException carrying the recoverable id,
     * letting the server answer -32600 Invalid Request and keep reading.
     *
     * @throws InvalidMessageException
     */
    public static function fromArray(array $data): self
    {
        $jsonrpc = $data['jsonrpc'] ?? null;
        if ($jsonrpc !== null && !is_string($jsonrpc)) {
            throw new InvalidMessageException(null, 'jsonrpc must be a string');
        }

        // Resolve the id first so later violations can echo it back. An integral
        // float (e.g. 2.0 from JSON) coerces to int; a fractional float, bool,
        // array, or other scalar is not a valid JSON-RPC id.
        $rawId = $data['id'] ?? null;
        $id = null;
        if ($rawId !== null) {
            if (is_int($rawId) || is_string($rawId)) {
                $id = $rawId;
            } elseif (is_float($rawId) && is_finite($rawId) && floor($rawId) === $rawId) {
                $id = (int) $rawId;
            } else {
                throw new InvalidMessageException(null, 'id must be an integer or string');
            }
        }

        $method = $data['method'] ?? null;
        if ($method !== null && !is_string($method)) {
            throw new InvalidMessageException($id, 'method must be a string');
        }

        $params = $data['params'] ?? null;
        if ($params !== null && !is_array($params)) {
            throw new InvalidMessageException($id, 'params must be an object');
        }

        $error = $data['error'] ?? null;
        if ($error !== null && !is_array($error)) {
            throw new InvalidMessageException($id, 'error must be an object');
        }

        return new self(
            jsonrpc: $jsonrpc,
            method: $method,
            id: $id,
            params: $params,
            result: $data['result'] ?? null,
            error: $error,
        );
    }

    public function isRequest(): bool
    {
        return $this->id !== null && $this->method !== null;
    }

    public function isNotification(): bool
    {
        return $this->id === null && $this->method !== null;
    }

    public function isResponse(): bool
    {
        return $this->id !== null && $this->method === null;
    }

    public function toArray(): array
    {
        $data = ['jsonrpc' => $this->jsonrpc];

        if ($this->id !== null) {
            $data['id'] = $this->id;
        }

        if ($this->method !== null) {
            $data['method'] = $this->method;
        }

        if ($this->params !== null) {
            $data['params'] = $this->params;
        }

        if ($this->error !== null) {
            // JSON-RPC error responses must carry an id — null when the request
            // id could not be recovered from the malformed frame.
            $data['id'] = $this->id;
            $data['error'] = $this->error;
        } elseif ($this->isResponse()) {
            $data['result'] = $this->result;
        }

        return $data;
    }
}
