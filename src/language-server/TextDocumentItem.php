<?php declare(strict_types=1);

namespace Attitude\PHPX\LanguageServer;

final class TextDocumentItem
{
    public function __construct(
        public readonly string $uri,
        public readonly string $languageId,
        public readonly int $version,
        public readonly string $text,
    ) {}

    public function getLines(): array
    {
        return explode("\n", $this->text);
    }

    public function getLine(int $line): ?string
    {
        $lines = $this->getLines();
        return $lines[$line] ?? null;
    }

    public function lineCount(): int
    {
        return count($this->getLines());
    }
}
