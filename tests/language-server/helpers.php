<?php declare(strict_types=1);

/**
 * Apply LSP TextEdit[] to source text, producing the resulting document.
 * Edits are sorted in reverse document order so earlier offsets stay valid.
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
