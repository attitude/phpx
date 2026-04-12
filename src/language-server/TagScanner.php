<?php declare(strict_types=1);

namespace Attitude\PHPX\LanguageServer;

use Attitude\PHPX\Parser\Token;

/**
 * Token-based tag scanner for PHPX documents.
 *
 * Uses the PHPX tokenizer (same as the compiler) to find real tag names,
 * avoiding false matches on tag-like text inside attribute strings or
 * expression containers. The tokenizer respects PHP string boundaries —
 * `<span>` inside `"<span>"` is a single T_CONSTANT_ENCAPSED_STRING token,
 * not separate `<`, `span`, `>` tokens.
 */
final class TagScanner
{
    /**
     * Scan a document and return all tag occurrences with their positions.
     *
     * Each entry contains:
     *   - name: the tag name (e.g. "div", "my-component")
     *   - line: 0-based line number
     *   - start: character offset of the first char of the tag name
     *   - end: character offset one past the last char of the tag name
     *   - kind: 'open', 'close', or 'self-close'
     *
     * @return array<int, array{name: string, line: int, start: int, end: int, kind: string}>
     */
    public static function scan(string $source): array
    {
        // Ensure TokensList.php is loaded — its file-scope constants (TX_*)
        // are needed below but aren't autoloaded until the class is referenced.
        class_exists(\Attitude\PHPX\Parser\TokensList::class);

        try {
            $tokens = Token::tokenize($source);
        } catch (\Throwable) {
            return [];
        }

        $tags = [];
        $count = count($tokens);
        $depth = 0; // Track { } nesting — < inside expressions is a comparison, not a tag

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // Track expression-container nesting so we ignore < inside { ... }
            if ($token->text === '{') {
                $depth++;
                continue;
            }
            if ($token->text === '}') {
                $depth = max(0, $depth - 1);
                continue;
            }

            // Skip any < that appears inside an expression container
            if ($depth > 0) {
                continue;
            }

            // Skip fragments (<> / </>)
            if ($token->id === \Attitude\PHPX\Parser\TX_FRAGMENT_ELEMENT_OPEN) {
                continue;
            }

            // Opening tag: < followed by T_STRING (aligned with Parser::parseElementName())
            if ($token->id === \Attitude\PHPX\Parser\TX_ELEMENT_OPENING_OPEN) {
                $next = $tokens[$i + 1] ?? null;

                // Check for closing tag: < / name
                if ($next !== null && $next->text === '/') {
                    $nameToken = $tokens[$i + 2] ?? null;
                    if ($nameToken !== null && $nameToken->id === T_STRING) {
                        $name = self::readTagName($tokens, $i + 2, $count);
                        $nameStart = self::toCharOffset($source, $nameToken->pos);
                        $tags[] = [
                            'name' => $name,
                            'line' => $nameToken->line - 1, // tokens are 1-based
                            'start' => $nameStart,
                            'end' => $nameStart + strlen($name),
                            'kind' => 'close',
                        ];
                    }
                    continue;
                }

                // Opening tag: < name (T_STRING only, matching the parser)
                if ($next !== null && $next->id === T_STRING) {
                    $name = self::readTagName($tokens, $i + 1, $count);
                    $nameStart = self::toCharOffset($source, $next->pos);

                    // Determine if self-closing by scanning forward for /> before >
                    $selfClosing = self::isSelfClosing($tokens, $i + 1, $count, $name);

                    $tags[] = [
                        'name' => $name,
                        'line' => $next->line - 1,
                        'start' => $nameStart,
                        'end' => $nameStart + strlen($name),
                        'kind' => $selfClosing ? 'self-close' : 'open',
                    ];
                }
            }
        }

        return $tags;
    }

    /**
     * Read a potentially hyphenated tag name starting at token index $start.
     * Returns "my-component" for tokens [my, -, component].
     */
    private static function readTagName(array $tokens, int $start, int $count): string
    {
        $name = $tokens[$start]->text;
        $j = $start + 1;

        // Hyphenated segments: "-" must be followed by T_STRING (aligned with
        // Parser::parseElementName which requires tokenAtCursorIsWord after "-")
        while ($j < $count - 1 && $tokens[$j]->text === '-' && isset($tokens[$j + 1]) &&
               $tokens[$j + 1]->id === T_STRING) {
            $name .= '-' . $tokens[$j + 1]->text;
            $j += 2;
        }

        return $name;
    }

    /**
     * Check if the tag starting at $nameIndex is self-closing.
     * Scans forward past attributes until finding > or />.
     */
    private static function isSelfClosing(array $tokens, int $nameIndex, int $count, string $name): bool
    {
        // Skip past the tag name tokens
        $j = $nameIndex;
        $nameLen = substr_count($name, '-') * 2 + 1; // each hyphenated segment = 2 tokens, plus the first
        $j += $nameLen;

        // Nesting depth for { } and ( ) — skip over attribute expressions
        $depth = 0;

        while ($j < $count) {
            $t = $tokens[$j];

            if ($depth === 0) {
                if ($t->text === '/' && isset($tokens[$j + 1]) && $tokens[$j + 1]->text === '>') {
                    return true;
                }
                if ($t->id === \Attitude\PHPX\Parser\TX_ELEMENT_OPENING_CLOSE) {
                    return false;
                }
            }

            // Track nesting for expressions/arrays in attributes
            if ($t->text === '{' || $t->text === '(' || $t->text === '[') {
                $depth++;
            } elseif ($t->text === '}' || $t->text === ')' || $t->text === ']') {
                $depth = max(0, $depth - 1);
            }

            $j++;
        }

        return false;
    }

    /**
     * Convert a byte offset (from Token::$pos) to the byte offset within
     * the token's line. Since the server negotiates positionEncoding: utf-8,
     * byte offsets equal UTF-8 code unit offsets, which is what LSP expects.
     */
    private static function toCharOffset(string $source, int $bytePos): int
    {
        // Find the start of the line containing this byte position
        $lineStart = strrpos($source, "\n", $bytePos - strlen($source));
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;

        return $bytePos - $lineStart;
    }

    /**
     * Find all tags in the document and return matched open/close pairs.
     * Uses stack-based pairing.
     *
     * @return array<int, array{open: array, close: array|null}>
     */
    public static function findPairs(string $source): array
    {
        $tags = self::scan($source);
        $stack = [];
        $pairs = [];

        foreach ($tags as $tag) {
            if ($tag['kind'] === 'self-close') {
                $pairs[] = ['open' => $tag, 'close' => null];
            } elseif ($tag['kind'] === 'open') {
                $stack[] = $tag;
            } elseif ($tag['kind'] === 'close') {
                // Find matching open tag (same name, last on stack)
                for ($j = count($stack) - 1; $j >= 0; $j--) {
                    if ($stack[$j]['name'] === $tag['name']) {
                        $open = $stack[$j];
                        array_splice($stack, $j, 1);
                        $pairs[] = ['open' => $open, 'close' => $tag];
                        break;
                    }
                }
            }
        }

        return $pairs;
    }

    /**
     * Find the unclosed tag nearest to the cursor (top of the tag stack).
     * Used for close-tag completion.
     */
    public static function findUnclosedTag(string $source): ?string
    {
        $tags = self::scan($source);
        $stack = [];

        foreach ($tags as $tag) {
            if ($tag['kind'] === 'open') {
                $stack[] = $tag['name'];
            } elseif ($tag['kind'] === 'close') {
                for ($j = count($stack) - 1; $j >= 0; $j--) {
                    if ($stack[$j] === $tag['name']) {
                        array_splice($stack, $j, 1);
                        break;
                    }
                }
            }
            // self-close doesn't affect the stack
        }

        return count($stack) > 0 ? end($stack) : null;
    }

    /**
     * Find the tag at a specific cursor position.
     *
     * @return array{name: string, line: int, start: int, end: int, kind: string}|null
     */
    public static function findTagAtPosition(string $source, int $line, int $character): ?array
    {
        $tags = self::scan($source);

        foreach ($tags as $tag) {
            if ($tag['line'] === $line && $character >= $tag['start'] && $character < $tag['end']) {
                return $tag;
            }
        }

        return null;
    }
}
