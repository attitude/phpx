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

    public static function error(int|string $id, int $code, string $message, mixed $data = null): self
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

    public static function fromArray(array $data): self
    {
        return new self(
            jsonrpc: $data['jsonrpc'] ?? null,
            method: $data['method'] ?? null,
            id: $data['id'] ?? null,
            params: $data['params'] ?? null,
            result: $data['result'] ?? null,
            error: $data['error'] ?? null,
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
            $data['error'] = $this->error;
        } elseif ($this->isResponse()) {
            $data['result'] = $this->result;
        }

        return $data;
    }
}
