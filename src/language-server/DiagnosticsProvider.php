<?php declare(strict_types=1);

namespace Attitude\PHPX\LanguageServer;

use Attitude\PHPX\Parser\Parser;
use Attitude\PHPX\Parser\Token;
use Attitude\PHPX\Parser\TokensList;

final class DiagnosticsProvider
{
    public function __construct(
        private readonly Parser $parser = new Parser(),
    ) {}

    /**
     * @return array[] Array of LSP Diagnostic objects
     */
    public function diagnose(TextDocumentItem $document): array
    {
        $source = $document->text;

        try {
            $tokens = Token::tokenize($source);
            $tokensList = new TokensList($tokens);
            $this->parser->parse($tokensList);

            return [];
        } catch (\ParseError $e) {
            return [$this->parseErrorToDiagnostic($e, $source)];
        } catch (\Throwable $e) {
            // Catches \Exception, \AssertionError, and any other \Error.
            // The parser uses assert() extensively — when parsing incomplete
            // code (user is mid-keystroke), these fire as AssertionError which
            // extends \Error, not \Exception.
            return [$this->throwableToDiagnostic($e, $source)];
        }
    }

    private function parseErrorToDiagnostic(\ParseError $e, string $source): array
    {
        $line = max(0, $this->extractLine($e->getMessage()) - 1);
        $lines = explode("\n", $source);
        $lineText = $lines[$line] ?? '';

        return [
            'range' => [
                'start' => ['line' => $line, 'character' => 0],
                'end' => ['line' => $line, 'character' => strlen($lineText)],
            ],
            'severity' => 1, // DiagnosticSeverity.Error
            'source' => 'phpx',
            'message' => $e->getMessage(),
        ];
    }

    private function throwableToDiagnostic(\Throwable $e, string $source): array
    {
        $line = max(0, $this->extractLine($e->getMessage()) - 1);
        $lines = explode("\n", $source);
        $lineText = $lines[$line] ?? '';

        return [
            'range' => [
                'start' => ['line' => $line, 'character' => 0],
                'end' => ['line' => $line, 'character' => strlen($lineText)],
            ],
            'severity' => 1, // DiagnosticSeverity.Error
            'source' => 'phpx',
            'message' => $e->getMessage(),
        ];
    }

    private function extractLine(string $message): int
    {
        if (preg_match('/(?:line|from line)\s+(\d+)/i', $message, $matches)) {
            return (int) $matches[1];
        }

        return 1;
    }
}
