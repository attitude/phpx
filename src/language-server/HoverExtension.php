<?php declare(strict_types=1);

namespace Attitude\PHPX\LanguageServer;

/**
 * LSP hover extension.
 *
 * Implementations are tried in registration order before the built-in
 * HoverProvider. Return a non-null LSP Hover object to provide the hover
 * content; return null to fall through to the next extension or the
 * built-in provider.
 *
 * Example — add hover docs for a custom attribute:
 *
 *   class XIfHoverExtension implements HoverExtension {
 *       public function hover(TextDocumentItem $document, int $line, int $character): ?array {
 *           $word = // ... detect 'x-if' at position ...
 *           if ($word !== 'x-if') return null;
 *           return [
 *               'contents' => ['kind' => 'markdown', 'value' => '**`x-if`** — conditional rendering'],
 *               'range' => [...],
 *           ];
 *       }
 *   }
 */
interface HoverExtension
{
    /**
     * Return an LSP Hover object if this extension handles the cursor position,
     * or null to fall through to the next extension / built-in provider.
     *
     * @param TextDocumentItem $document The document being hovered.
     * @param int $line Zero-based line number of the cursor.
     * @param int $character Zero-based character offset of the cursor.
     * @return array|null LSP Hover array, or null to defer.
     */
    public function hover(TextDocumentItem $document, int $line, int $character): ?array;
}
