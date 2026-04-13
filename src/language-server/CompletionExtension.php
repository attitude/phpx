<?php declare(strict_types=1);

namespace Attitude\PHPX\LanguageServer;

/**
 * LSP completion extension.
 *
 * Implementations return additional completion items that are merged with the
 * built-in items produced by CompletionProvider. All registered extensions are
 * always called; their results are concatenated.
 *
 * Example — add completions for a custom <x-icon> component library:
 *
 *   class IconCompletionExtension implements CompletionExtension {
 *       public function complete(TextDocumentItem $document, int $line, int $character): array {
 *           return [
 *               ['label' => 'x-icon', 'kind' => 14, 'detail' => 'Icon component'],
 *           ];
 *       }
 *
 *       public function getCapabilities(): array {
 *           return ['triggerCharacters' => ['x']];
 *       }
 *   }
 */
interface CompletionExtension
{
    /**
     * Return LSP CompletionItem objects to append to the built-in completion list.
     *
     * @param TextDocumentItem $document The document being edited.
     * @param int $line Zero-based line number of the cursor.
     * @param int $character Zero-based character offset of the cursor.
     * @return array[] Array of LSP CompletionItem arrays.
     */
    public function complete(TextDocumentItem $document, int $line, int $character): array;

    /**
     * Return LSP completionProvider capability overrides to merge into the server capabilities.
     *
     * Recognised keys:
     *   - 'triggerCharacters' (string[]) — merged (deduplicated) with the built-in trigger chars.
     *
     * @return array Partial completionProvider capability map.
     */
    public function getCapabilities(): array;
}
