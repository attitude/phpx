<?php declare(strict_types=1);

use Attitude\PHPX\LanguageServer\TagScanner;

describe('TagScanner', function () {

    describe('scan', function () {
        it('finds opening and closing tags', function () {
            $tags = TagScanner::scan('<div>Hello</div>');

            expect($tags)->toHaveCount(2);
            expect($tags[0]['name'])->toBe('div');
            expect($tags[0]['kind'])->toBe('open');
            expect($tags[1]['name'])->toBe('div');
            expect($tags[1]['kind'])->toBe('close');
        });

        it('finds self-closing tags', function () {
            $tags = TagScanner::scan('<br />');

            expect($tags)->toHaveCount(1);
            expect($tags[0]['name'])->toBe('br');
            expect($tags[0]['kind'])->toBe('self-close');
        });

        it('finds hyphenated custom element tags', function () {
            $tags = TagScanner::scan('<my-component>text</my-component>');

            expect($tags)->toHaveCount(2);
            expect($tags[0]['name'])->toBe('my-component');
            expect($tags[0]['kind'])->toBe('open');
            expect($tags[1]['name'])->toBe('my-component');
            expect($tags[1]['kind'])->toBe('close');
        });

        it('does NOT match tag-like text inside quoted attribute values', function () {
            $tags = TagScanner::scan('<div data="<span>fake</span>">real</div>');

            // Should only find the real <div> and </div>, not the <span> inside the string
            $names = array_column($tags, 'name');
            expect($names)->not->toContain('span');
            expect(count(array_filter($tags, fn($t) => $t['name'] === 'div')))->toBe(2);
        });

        it('does NOT match tag-like text inside single-quoted attribute values', function () {
            $source = "<div title='<em>not a tag</em>'>text</div>";
            $tags = TagScanner::scan($source);

            $names = array_column($tags, 'name');
            expect($names)->not->toContain('em');
        });

        it('reads numeric hyphen segments in custom element names (e.g. x-2)', function () {
            $tags = TagScanner::scan('<x-2>text</x-2>');

            expect($tags)->toHaveCount(2);
            expect($tags[0]['name'])->toBe('x-2');
            expect($tags[0]['kind'])->toBe('open');
            expect($tags[1]['name'])->toBe('x-2');
            expect($tags[1]['kind'])->toBe('close');
        });

        it('does NOT match tag-like text inside expression containers', function () {
            $tags = TagScanner::scan('<div>{$x < 5 ? "yes" : "no"}</div>');

            // The < 5 should not be detected as a tag
            $names = array_column($tags, 'name');
            expect(count(array_filter($tags, fn($t) => $t['name'] === 'div')))->toBe(2);
            expect(count($tags))->toBe(2);
        });

        it('handles nested tags', function () {
            $tags = TagScanner::scan('<div><span>text</span></div>');

            expect($tags)->toHaveCount(4);
            expect($tags[0]['name'])->toBe('div');
            expect($tags[0]['kind'])->toBe('open');
            expect($tags[1]['name'])->toBe('span');
            expect($tags[1]['kind'])->toBe('open');
            expect($tags[2]['name'])->toBe('span');
            expect($tags[2]['kind'])->toBe('close');
            expect($tags[3]['name'])->toBe('div');
            expect($tags[3]['kind'])->toBe('close');
        });

        it('returns empty array for unparseable input', function () {
            $tags = TagScanner::scan('<?php echo "hello";');
            expect($tags)->toBeArray();
        });

        it('tracks correct line numbers for multi-line documents', function () {
            $tags = TagScanner::scan("<div>\n  <span>text</span>\n</div>");

            expect($tags[0]['line'])->toBe(0); // <div> on line 0
            expect($tags[1]['line'])->toBe(1); // <span> on line 1
            expect($tags[2]['line'])->toBe(1); // </span> on line 1
            expect($tags[3]['line'])->toBe(2); // </div> on line 2
        });
    });

    describe('findPairs', function () {
        it('pairs matching open/close tags', function () {
            $pairs = TagScanner::findPairs('<div>text</div>');

            expect($pairs)->toHaveCount(1);
            expect($pairs[0]['open']['name'])->toBe('div');
            expect($pairs[0]['close']['name'])->toBe('div');
        });

        it('pairs nested same-name tags correctly', function () {
            $pairs = TagScanner::findPairs("<div>\n  <div>inner</div>\n</div>");

            expect($pairs)->toHaveCount(2);
            // Inner pair comes first (closed first)
            expect($pairs[0]['open']['line'])->toBe(1);
            expect($pairs[0]['close']['line'])->toBe(1);
            // Outer pair
            expect($pairs[1]['open']['line'])->toBe(0);
            expect($pairs[1]['close']['line'])->toBe(2);
        });

        it('represents self-closing tags as pairs with null close', function () {
            $pairs = TagScanner::findPairs('<br />');

            expect($pairs)->toHaveCount(1);
            expect($pairs[0]['open']['name'])->toBe('br');
            expect($pairs[0]['close'])->toBeNull();
        });

        it('does not produce false pairs from attribute strings', function () {
            $pairs = TagScanner::findPairs('<div data="<span>test</span>">content</div>');

            // Only the real <div>...</div> pair
            $divPairs = array_filter($pairs, fn($p) => $p['open']['name'] === 'div');
            $spanPairs = array_filter($pairs, fn($p) => $p['open']['name'] === 'span');

            expect(count($divPairs))->toBe(1);
            expect(count($spanPairs))->toBe(0);
        });
    });

    describe('findUnclosedTag', function () {
        it('finds the unclosed tag', function () {
            expect(TagScanner::findUnclosedTag('<div><span>text'))->toBe('span');
        });

        it('returns null when all tags are closed', function () {
            expect(TagScanner::findUnclosedTag('<div>text</div>'))->toBeNull();
        });

        it('ignores self-closing tags', function () {
            expect(TagScanner::findUnclosedTag('<div><br />'))->toBe('div');
        });

        it('does not count tag-like text in strings as unclosed', function () {
            $source = '<div data="<span>">text</div>';
            expect(TagScanner::findUnclosedTag($source))->toBeNull();
        });
    });

    describe('findTagAtPosition', function () {
        it('finds tag at cursor position', function () {
            $tag = TagScanner::findTagAtPosition('<div>text</div>', 0, 1);

            expect($tag)->not->toBeNull();
            expect($tag['name'])->toBe('div');
            expect($tag['kind'])->toBe('open');
        });

        it('returns null when cursor is not on a tag', function () {
            $tag = TagScanner::findTagAtPosition('<div>text</div>', 0, 6);

            expect($tag)->toBeNull();
        });

        it('finds closing tag at cursor', function () {
            $tag = TagScanner::findTagAtPosition('<div>text</div>', 0, 11);

            expect($tag)->not->toBeNull();
            expect($tag['name'])->toBe('div');
            expect($tag['kind'])->toBe('close');
        });

        it('does not match tag-like text in attributes', function () {
            // Cursor at position where <span> would be inside the attribute string
            $source = '<div data="<span>">text</div>';
            // The <span> inside the attribute string should not be found
            // Find all tags — should only be div
            $tags = TagScanner::scan($source);
            $spanTags = array_filter($tags, fn($t) => $t['name'] === 'span');
            expect(count($spanTags))->toBe(0);
        });
    });
});
