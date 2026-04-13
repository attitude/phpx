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
        // Force-load TokensList so its file-scope TX_* constants are defined.
        // Autoloaders map class names to files, not bare constants — without this
        // the TX_FRAGMENT_ELEMENT_OPEN / TX_ELEMENT_OPENING_OPEN references below
        // would throw "Undefined constant".
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

            // Skip any token inside an expression container
            if ($depth > 0) {
                continue;
            }

            // Fragments (<>) carry no name — skip. TX_FRAGMENT_ELEMENT_OPEN is a
            // synthetic id assigned by Token::tokenize() when PHP emits T_IS_NOT_EQUAL
            // for the <> digraph. The closing </> never forms a named tag entry either.
            if ($token->id === \Attitude\PHPX\Parser\TX_FRAGMENT_ELEMENT_OPEN) {
                continue;
            }

            if ($token->id === \Attitude\PHPX\Parser\TX_ELEMENT_OPENING_OPEN) {
                $next = $tokens[$i + 1] ?? null;

                // Closing tag: < / name
                if ($next !== null && $next->text === '/') {
                    $nameToken = $tokens[$i + 2] ?? null;
                    if ($nameToken !== null && $nameToken->id === T_STRING) {
                        $name = self::readTagName($tokens, $i + 2, $count);
                        $nameStart = self::toCharOffset($source, $nameToken->pos);
                        $tags[] = [
                            'name' => $name,
                            'line' => $nameToken->line - 1, // PHP tokenizer is 1-based; LSP is 0-based
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
     * Read a potentially hyphenated tag name starting at $tokens[$start].
     * Returns "my-component" for the token sequence [my, -, component].
     *
     * PHP tokenizes "my-component" as three separate tokens, so the name must be
     * reassembled. A hyphen is consumed only when followed by a word token —
     * T_STRING or any token whose text matches /^\w+$/ (covers numeric segments
     * like "2" in "x-2"). Aligned with Parser::parseElementName().
     */
    private static function readTagName(array $tokens, int $start, int $count): string
    {
        $name = $tokens[$start]->text;
        $j = $start + 1;

        // The bound $j < $count - 1 ensures $tokens[$j + 1] exists before peeking.
        while ($j < $count - 1 && $tokens[$j]->text === '-' && isset($tokens[$j + 1]) &&
               ($tokens[$j + 1]->id === T_STRING || preg_match('/^\w+$/', $tokens[$j + 1]->text))) {
            $name .= '-' . $tokens[$j + 1]->text;
            $j += 2;
        }

        return $name;
    }

    /**
     * Check if the tag starting at $nameIndex is self-closing.
     * Scans forward past attributes until finding /> or >.
     */
    private static function isSelfClosing(array $tokens, int $nameIndex, int $count, string $name): bool
    {
        // Skip past the tag name tokens.
        // A simple name ("div") is 1 token; each hyphenated segment adds 2 ('-' + word).
        $j = $nameIndex;
        $nameLen = substr_count($name, '-') * 2 + 1; // e.g. "my-foo" → 1*2+1 = 3 tokens
        $j += $nameLen;

        // Nesting depth for { } ( ) [ ] — skip over attribute expressions
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
     * Convert a byte offset (from Token::$pos) to the character offset within
     * the token's line, suitable for use as an LSP character value.
     *
     * Finds the start of the line containing $bytePos by searching backwards
     * for the last newline. The negative third argument to strrpos() is an
     * offset from the end of the string: ($bytePos - strlen($source)) is
     * negative, so strrpos() starts its backward search at byte $bytePos.
     * When no newline is found the byte is on the first line (line start = 0).
     */
    private static function toCharOffset(string $source, int $bytePos): int
    {
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
        return self::findPairsFromTags(self::scan($source));
    }

    /**
     * Find matched open/close pairs from a pre-scanned tag list.
     *
     * @param  array<int, array{name: string, line: int, start: int, end: int, kind: string}> $tags
     * @return array<int, array{open: array, close: array|null}>
     */
    public static function findPairsFromTags(array $tags): array
    {
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
        return self::findTagAtPositionFromTags(self::scan($source), $line, $character);
    }

    /**
     * Find the tag at a specific cursor position from a pre-scanned tag list.
     *
     * @param  array<int, array{name: string, line: int, start: int, end: int, kind: string}> $tags
     * @return array{name: string, line: int, start: int, end: int, kind: string}|null
     */
    public static function findTagAtPositionFromTags(array $tags, int $line, int $character): ?array
    {
        foreach ($tags as $tag) {
            if ($tag['line'] === $line && $character >= $tag['start'] && $character < $tag['end']) {
                return $tag;
            }
        }

        return null;
    }

    /**
     * Check if the given line prefix ends inside a quoted string ("…", '…', `…`)
     * or a {…} expression container. Used to suppress completions and hover
     * results when the cursor is inside attribute string content rather than markup.
     *
     * Note: operates on the current-line prefix only. Multi-line attribute values
     * are not detected. In practice PHPX files do not use multi-line quoted
     * attribute values, so this is an acceptable limitation.
     */
    public static function isInsideStringOrExpression(string $prefix): bool
    {
        $depth = 0;
        $inDouble = false;
        $inSingle = false;
        $inBacktick = false;
        $len = strlen($prefix);

        for ($i = 0; $i < $len; $i++) {
            $c = $prefix[$i];

            if ($inDouble) {
                if ($c === '\\') { $i++; continue; }
                if ($c === '"') { $inDouble = false; }
            } elseif ($inSingle) {
                if ($c === '\\') { $i++; continue; }
                if ($c === "'") { $inSingle = false; }
            } elseif ($inBacktick) {
                if ($c === '\\') { $i++; continue; }
                if ($c === '`') { $inBacktick = false; }
            } elseif ($depth === 0) {
                if ($c === '"') { $inDouble = true; }
                elseif ($c === "'") { $inSingle = true; }
                elseif ($c === '`') { $inBacktick = true; }
                elseif ($c === '{') { $depth++; }
            } else {
                if ($c === '{') { $depth++; }
                elseif ($c === '}') { $depth = max(0, $depth - 1); }
            }
        }

        return $depth > 0 || $inDouble || $inSingle || $inBacktick;
    }
}
