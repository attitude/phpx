<?php declare(strict_types=1);

namespace Attitude\PHPX\LanguageServer;

/**
 * LSP diagnostics extension.
 *
 * Implementations return additional diagnostics that are merged with the
 * built-in diagnostics produced by DiagnosticsProvider. All registered
 * extensions are always called; their results are concatenated.
 *
 * Example — warn on deprecated attribute usage:
 *
 *   class DeprecatedAttrDiagnosticsExtension implements DiagnosticsExtension {
 *       public function diagnose(TextDocumentItem $document): array {
 *           $diagnostics = [];
 *           // ... scan $document->text for deprecated attributes ...
 *           return $diagnostics;
 *       }
 *   }
 */
interface DiagnosticsExtension
{
    /**
     * Return LSP Diagnostic objects to append to the built-in diagnostics list.
     *
     * @param TextDocumentItem $document The document to analyse.
     * @return array[] Array of LSP Diagnostic arrays.
     */
    public function diagnose(TextDocumentItem $document): array;
}
