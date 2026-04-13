<?php declare(strict_types=1);

use Attitude\PHPX\LanguageServer\Message;
use Attitude\PHPX\LanguageServer\Transport;

/**
 * Reads all messages written to the output stream.
 *
 * @param resource $output
 * @return Message[]
 */
function readOutputMessages($output): array {
    rewind($output);
    $readTransport = new Transport($output, fopen('php://memory', 'r+'));
    $messages = [];

    while (($msg = $readTransport->read()) !== null) {
        $messages[] = $msg;
    }

    return $messages;
}

/**
 * Apply LSP TextEdit[] to source text, producing the resulting document.
 * Edits are sorted in reverse document order so earlier offsets stay valid.
 *
 * Limitation: each edit's newText must not introduce newlines. The splice
 * logic treats every edit as a single-line replacement. This is sufficient
 * for rename edits (which only replace tag names) but not for general LSP
 * text edits that insert or remove line breaks.
 */
function applyEdits(string $text, array $edits): string
{
    usort($edits, function ($a, $b) {
        $lineDiff = $b['range']['start']['line'] - $a['range']['start']['line'];
        if ($lineDiff !== 0) return $lineDiff;
        return $b['range']['start']['character'] - $a['range']['start']['character'];
    });

    $lines = explode("\n", $text);

    foreach ($edits as $edit) {
        $startLine = $edit['range']['start']['line'];
        $startChar = $edit['range']['start']['character'];
        $endLine = $edit['range']['end']['line'];
        $endChar = $edit['range']['end']['character'];

        $before = substr($lines[$startLine], 0, $startChar);
        $after = substr($lines[$endLine], $endChar);
        $lines[$startLine] = $before . $edit['newText'] . $after;

        if ($endLine > $startLine) {
            array_splice($lines, $startLine + 1, $endLine - $startLine);
        }
    }

    return implode("\n", $lines);
}
