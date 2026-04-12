<?php declare(strict_types=1);

namespace Attitude\PHPX\LanguageServer;

final class RenameProvider
{
    /**
     * Check if rename is valid at the given position.
     * Returns the range of the tag name to rename, or null if not on a tag.
     *
     * @return array{range: array, placeholder: string}|null
     */
    public function prepareRename(TextDocumentItem $document, int $line, int $character): ?array
    {
        $tag = TagScanner::findTagAtPosition($document->text, $line, $character);

        if ($tag === null) {
            return null;
        }

        return [
            'range' => [
                'start' => ['line' => $tag['line'], 'character' => $tag['start']],
                'end' => ['line' => $tag['line'], 'character' => $tag['end']],
            ],
            'placeholder' => $tag['name'],
        ];
    }

    /**
     * Perform the rename — returns a WorkspaceEdit that renames all matching
     * opening/closing tags in the document.
     *
     * Uses the PHPX tokenizer to find real tag names, avoiding false matches
     * on tag-like text inside attribute strings or expression containers.
     *
     * @return array{changes: array<string, array>}|null
     */
    public function rename(TextDocumentItem $document, int $line, int $character, string $newName): ?array
    {
        // Validate newName matches the parser's element name grammar:
        // - First segment: T_STRING (letter or underscore start, then word chars)
        // - Optional hyphenated segments: "-" followed by a word token
        // This rejects: empty, leading digits, spaces, consecutive hyphens, trailing hyphen
        if (!preg_match('/^[a-zA-Z_][\w]*(-[a-zA-Z_][\w]*)*$/', $newName)) {
            return null;
        }

        $tag = TagScanner::findTagAtPosition($document->text, $line, $character);

        if ($tag === null) {
            return null;
        }

        $pairs = TagScanner::findPairs($document->text);
        $edits = [];

        // Find the pair containing the cursor
        $matched = false;
        foreach ($pairs as $pair) {
            $o = $pair['open'];
            $c = $pair['close'];

            $cursorOnOpen = ($o['line'] === $line && $character >= $o['start'] && $character < $o['end']);
            $cursorOnClose = ($c !== null && $c['line'] === $line && $character >= $c['start'] && $character < $c['end']);

            if ($cursorOnOpen || $cursorOnClose) {
                // Rename opening tag
                $edits[] = [
                    'range' => [
                        'start' => ['line' => $o['line'], 'character' => $o['start']],
                        'end' => ['line' => $o['line'], 'character' => $o['end']],
                    ],
                    'newText' => $newName,
                ];

                // Rename closing tag (if not self-closing)
                if ($c !== null) {
                    $edits[] = [
                        'range' => [
                            'start' => ['line' => $c['line'], 'character' => $c['start']],
                            'end' => ['line' => $c['line'], 'character' => $c['end']],
                        ],
                        'newText' => $newName,
                    ];
                }

                $matched = true;
                break;
            }
        }

        // Fallback: rename just the tag under the cursor
        if (!$matched) {
            $edits[] = [
                'range' => [
                    'start' => ['line' => $tag['line'], 'character' => $tag['start']],
                    'end' => ['line' => $tag['line'], 'character' => $tag['end']],
                ],
                'newText' => $newName,
            ];
        }

        return [
            'changes' => [
                $document->uri => $edits,
            ],
        ];
    }
}
