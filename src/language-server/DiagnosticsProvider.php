<?php declare(strict_types=1);

namespace Attitude\PHPX\LanguageServer;

use Attitude\PHPX\Compiler\AbstractNodeVisitor;
use Attitude\PHPX\Compiler\NodeTraverser;
use Attitude\PHPX\Parser\NodeType;
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
            $ast = $this->parser->parse(new TokensList($tokens));
        } catch (\Throwable $e) {
            // Catches \Exception, \AssertionError, and any other \Error.
            // When parsing incomplete code (user mid-keystroke) the parser may
            // throw \ParseError (extends \Error, not \Exception).
            return [$this->throwableToDiagnostic($e, $source)];
        }

        // Parse succeeded — check attribute names for typos / HTML-vs-JSX mismatches.
        return $this->checkAttributes($ast, $source);
    }

    /**
     * Walk the parsed AST and report unknown attributes that closely match a
     * known one (typos / HTML-vs-JSX casing). Working from the AST — rather than
     * a raw token scan — means hyphenated names (`data-foo`) and attribute
     * expressions (`{$a > $b}`) are handled correctly by the parser.
     *
     * @param array $ast Parsed top-level nodes.
     * @return array[]
     */
    private function checkAttributes(array $ast, string $source): array
    {
        $collector = new class extends AbstractNodeVisitor {
            /** @var array[] */
            public array $elements = [];

            public function enterNode(array $node): array|int|null
            {
                if ($node['$$type'] === NodeType::PHPX_ELEMENT) {
                    $this->elements[] = $node;
                }
                return null;
            }
        };
        (new NodeTraverser($collector))->traverse($ast);

        $diagnostics = [];

        foreach ($collector->elements as $element) {
            [$tagText] = self::nameParts($element['openingElement'][1]);

            // Skip PHPX components (uppercase-first tags) — we don't know their props.
            if ($tagText === '' || ctype_upper($tagText[0])) {
                continue;
            }
            $tag = strtolower($tagText);

            foreach ($element['attributes'] as $attribute) {
                if (!is_array($attribute) || ($attribute['$$type'] ?? null) !== NodeType::PHPX_ATTRIBUTE) {
                    continue; // whitespace tokens, `{...spread}` expressions, etc.
                }

                [$attrName, $firstToken] = self::nameParts($attribute['name']);

                // Skip namespaced names (xmlns:xlink) and the open-ended data-*/aria-* namespaces.
                if (str_contains($attrName, ':')
                    || str_starts_with($attrName, 'data-')
                    || str_starts_with($attrName, 'aria-')) {
                    continue;
                }

                if (HTMLAttributes::lookup($tag, $attrName) !== null) {
                    continue; // known attribute
                }

                $suggestion = $this->findClosestAttribute($tag, $attrName);
                if ($suggestion === null) {
                    continue;
                }

                $col = $this->byteOffsetToColumn($source, $firstToken->pos);
                $diagnostics[] = [
                    'range' => [
                        'start' => ['line' => $firstToken->line - 1, 'character' => $col],
                        'end' => ['line' => $firstToken->line - 1, 'character' => $col + strlen($attrName)],
                    ],
                    'severity' => 2, // DiagnosticSeverity.Warning
                    'source' => 'phpx',
                    'message' => "Unknown attribute `{$attrName}` on <{$tag}>. Did you mean `{$suggestion}`?",
                ];
            }
        }

        return $diagnostics;
    }

    /**
     * Reassemble an element/attribute name — a single Token, or a run of Tokens
     * for hyphenated/namespaced names — into [text, firstToken].
     *
     * @param Token|Token[] $name
     * @return array{string, Token}
     */
    private static function nameParts(Token|array $name): array
    {
        if ($name instanceof Token) {
            return [$name->text, $name];
        }

        $text = implode('', array_map(fn(Token $t) => $t->text, $name));
        return [$text, $name[0]];
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
