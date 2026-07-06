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
            // Ensure TokensList.php is loaded — its file-scope TX_* constants
            // are needed by the tokenizer but won't autoload on their own.
            class_exists(TokensList::class);
            $tokens = Token::tokenize($source);
            $tokensList = new TokensList($tokens);
            $this->parser->parse($tokensList);
        } catch (\Throwable $e) {
            // Catches \Exception, \AssertionError, and any other \Error.
            // The parser uses assert() extensively — when parsing incomplete
            // code (user is mid-keystroke), these fire as AssertionError which
            // extends \Error, not \Exception.
            return [$this->throwableToDiagnostic($e, $source)];
        }

        // Parse succeeded — check attribute names for typos / HTML-vs-JSX mismatches
        return $this->checkAttributes($tokens, $source);
    }

    /**
     * Scan tokens for attribute names inside opening tags and report
     * diagnostics for unknown attributes that look like typos or
     * HTML-vs-JSX naming mistakes (e.g. tabindex → tabIndex).
     *
     * @param Token[] $tokens
     * @return array[]
     */
    private function checkAttributes(array $tokens, string $source): array
    {
        $diagnostics = [];
        $count = count($tokens);
        $insideTag = false;
        $currentTag = '';
        $currentTagIsComponent = false;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->id === \Attitude\PHPX\Parser\TX_ELEMENT_OPENING_OPEN) {
                // Next T_STRING is the tag name
                $next = $tokens[$i + 1] ?? null;
                if ($next !== null && $next->id === T_STRING) {
                    // Uppercase-first tags are PHPX components — check case before
                    // lowercasing, otherwise the component skip below never fires.
                    $currentTagIsComponent = ctype_upper($next->text[0] ?? '');
                    $currentTag = strtolower($next->text);
                    $insideTag = true;
                    $i++; // skip the tag name token
                } else {
                    $insideTag = false;
                }
                continue;
            }

            if ($token->id === \Attitude\PHPX\Parser\TX_ELEMENT_OPENING_CLOSE) {
                $insideTag = false;
                continue;
            }

            // Self-closing: `/` followed by `>`
            if ($insideTag && $token->text === '/' && ($tokens[$i + 1] ?? null)?->text === '>') {
                $insideTag = false;
                continue;
            }

            // Only check T_STRING tokens inside an opening tag that look like attribute names
            // (preceded by whitespace, not part of `attr-name` hyphenated chains)
            if (!$insideTag || $token->id !== T_STRING) {
                continue;
            }

            // Skip if this is the first word after `<` (that's the tag name, already consumed)
            // Check that the previous non-whitespace token is not `<`
            $prev = $this->findPreviousNonWhitespace($tokens, $i);
            if ($prev !== null && $prev->id === \Attitude\PHPX\Parser\TX_ELEMENT_OPENING_OPEN) {
                continue;
            }

            // Skip namespaced attributes like xmlns:cc (colon follows)
            if (($tokens[$i + 1] ?? null)?->text === ':') {
                continue;
            }

            // Build the full attribute name (handles hyphenated like data-foo)
            $attrName = $token->text;

            // Skip PHPX components (uppercase-first tags) — we don't know their props.
            if ($currentTagIsComponent) {
                continue;
            }

            // Skip the open-ended data-* / aria-* namespaces (name is exactly
            // `data`/`aria` followed by a `-`; `data-foo` tokenizes as data,-,foo).
            if (($attrName === 'data' || $attrName === 'aria')
                && ($tokens[$i + 1] ?? null)?->text === '-') {
                continue;
            }

            $known = HTMLAttributes::lookup($currentTag, $attrName);
            if ($known !== null) {
                continue; // Valid attribute
            }

            // Unknown attribute — check for close matches
            $suggestion = $this->findClosestAttribute($currentTag, $attrName);

            if ($suggestion !== null) {
                // Convert byte offset → (line, col) for LSP
                $line = $token->line - 1; // tokens are 1-based, LSP is 0-based
                $col = $this->byteOffsetToColumn($source, $token->pos);

                $diagnostics[] = [
                    'range' => [
                        'start' => ['line' => $line, 'character' => $col],
                        'end' => ['line' => $line, 'character' => $col + strlen($attrName)],
                    ],
                    'severity' => 2, // DiagnosticSeverity.Warning
                    'source' => 'phpx',
                    'message' => "Unknown attribute `{$attrName}` on <{$currentTag}>. Did you mean `{$suggestion}`?",
                ];
            }
        }

        return $diagnostics;
    }

    /**
     * Find the closest matching attribute name (case-insensitive match
     * or small edit distance).
     */
    private function findClosestAttribute(string $tagName, string $attrName): ?string
    {
        $all = HTMLAttributes::forElement($tagName);
        $lower = strtolower($attrName);

        // First: exact case-insensitive match (e.g. tabindex → tabIndex)
        foreach ($all as $name => $_) {
            if (strtolower($name) === $lower) {
                return $name;
            }
        }

        // Second: small Levenshtein distance (≤ 2)
        $bestDist = PHP_INT_MAX;
        $bestName = null;

        foreach ($all as $name => $_) {
            $dist = levenshtein($lower, strtolower($name));
            if ($dist < $bestDist && $dist <= 2) {
                $bestDist = $dist;
                $bestName = $name;
            }
        }

        return $bestName;
    }

    /**
     * Convert a byte offset in the source to a column number on its line.
     */
    private function byteOffsetToColumn(string $source, int $byteOffset): int
    {
        $lineStart = strrpos($source, "\n", $byteOffset - strlen($source));
        return $byteOffset - ($lineStart === false ? 0 : $lineStart + 1);
    }

    private function findPreviousNonWhitespace(array $tokens, int $index): ?Token
    {
        for ($j = $index - 1; $j >= 0; $j--) {
            if ($tokens[$j]->id !== T_WHITESPACE) {
                return $tokens[$j];
            }
        }
        return null;
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
