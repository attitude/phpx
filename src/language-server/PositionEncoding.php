<?php declare(strict_types=1);

namespace Attitude\PHPX\LanguageServer;

/**
 * Converts LSP character offsets between UTF-16 code units (the LSP default
 * encoding) and byte offsets (how PHP strings — and every provider here — are
 * indexed).
 *
 * The two operations are inverses of each other on a single line string. Both
 * make a single left-to-right pass over the line, classifying each character by
 * its UTF-8 lead byte rather than decoding it, so cost is O(line length):
 *
 *   - lead byte 0xxxxxxx (U+0000–U+007F)   → 1 byte,  1 UTF-16 unit
 *   - lead byte 110xxxxx (U+0080–U+07FF)   → 2 bytes, 1 UTF-16 unit
 *   - lead byte 1110xxxx (U+0800–U+FFFF)   → 3 bytes, 1 UTF-16 unit
 *   - lead byte 11110xxx (U+10000–U+10FFFF)→ 4 bytes, 2 UTF-16 units (surrogate pair)
 *
 * Offsets past the end of the line are clamped to the line length; an offset
 * that lands inside a character is resolved to that character's boundary.
 */
final class PositionEncoding
{
    /**
     * Convert a UTF-16 code unit offset into a byte offset within $line.
     */
    public static function utf16ToByte(string $line, int $utf16Offset): int
    {
        $len = strlen($line);
        $units = 0;
        $i = 0;

        while ($i < $len && $units < $utf16Offset) {
            $c = ord($line[$i]);
            if ($c < 0x80) {
                $i += 1;
                $units += 1;
            } elseif ($c < 0xE0) {
                $i += 2;
                $units += 1;
            } elseif ($c < 0xF0) {
                $i += 3;
                $units += 1;
            } else {
                $i += 4;
                $units += 2;
            }
        }

        return min($i, $len);
    }

    /**
     * Convert a byte offset within $line into a UTF-16 code unit offset.
     */
    public static function byteToUtf16(string $line, int $byteOffset): int
    {
        $limit = min($byteOffset, strlen($line));
        $units = 0;
        $i = 0;

        while ($i < $limit) {
            $c = ord($line[$i]);
            if ($c < 0x80) {
                $i += 1;
                $units += 1;
            } elseif ($c < 0xE0) {
                $i += 2;
                $units += 1;
            } elseif ($c < 0xF0) {
                $i += 3;
                $units += 1;
            } else {
                $i += 4;
                $units += 2;
            }
        }

        return $units;
    }
}
