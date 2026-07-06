<?php declare(strict_types=1);

use Attitude\PHPX\Compiler\AbstractNodeVisitor;
use Attitude\PHPX\Compiler\Compiler;
use Attitude\PHPX\Compiler\NodeTraverser;
use Attitude\PHPX\Parser\NodeType;
use Attitude\PHPX\Parser\Parser;
use Attitude\PHPX\Parser\Token;
use Attitude\PHPX\Parser\TokensList;

/** Parse PHPX source into its AST (list of top-level nodes). */
function astOf(string $source): array
{
    return (new Parser())->parse(new TokensList(Token::tokenize($source)));
}

describe('NodeTraverser', function () {
    it('leaves the tree unchanged when no visitor touches it', function () {
        $ast = astOf('<div id="x">hi</div>');
        $out = (new NodeTraverser(new class extends AbstractNodeVisitor {}))->traverse($ast);

        expect($out)->toEqual($ast);
    });

    it('visits every semantic node exactly once (enter)', function () {
        $collector = new class extends AbstractNodeVisitor {
            public array $seen = [];
            public function enterNode(array $node): array|int|null {
                $this->seen[] = $node['$$type'];
                return null;
            }
        };

        (new NodeTraverser($collector))->traverse(astOf('<div>{$x}<span>hi</span></div>'));

        // element(div) → expression container → block → element(span) → text
        expect($collector->seen)->toContain(NodeType::PHPX_ELEMENT);
        expect($collector->seen)->toContain(NodeType::PHPX_EXPRESSION_CONTAINER);
        expect($collector->seen)->toContain(NodeType::PHPX_TEXT);
        // div and span are both elements
        $elements = array_filter($collector->seen, fn($t) => $t === NodeType::PHPX_ELEMENT);
        expect(count($elements))->toBe(2);
    });

    it('replaces a node returned from enterNode', function () {
        $ast = astOf('<foo />');
        $rename = new class extends AbstractNodeVisitor {
            public function enterNode(array $node): array|int|null {
                if ($node['$$type'] !== NodeType::PHPX_ELEMENT) {
                    return null;
                }
                $name = $node['openingElement'][1];
                $origin = is_array($name) ? $name[0] : $name;
                $node['openingElement'][1] = new Token(T_STRING, 'bar', $origin->line, $origin->pos);
                return $node;
            }
        };

        $out = (new NodeTraverser($rename))->traverse($ast);

        expect($out[0]['openingElement'][1]->text)->toBe('bar');
    });

    it('removes a node when leaveNode returns REMOVE_NODE', function () {
        $ast = astOf('<div>{/* keep me out */}</div>');
        $strip = new class extends AbstractNodeVisitor {
            public function leaveNode(array $node): array|int|null {
                return $node['$$type'] === NodeType::PHPX_COMMENT
                    ? NodeTraverser::REMOVE_NODE
                    : null;
            }
        };

        $out = (new NodeTraverser($strip))->traverse($ast);
        $children = $out[0]['children'];
        $comments = array_filter(
            $children,
            fn($c) => is_array($c) && $c['$$type'] === NodeType::PHPX_COMMENT,
        );

        expect($comments)->toBe([]);
    });

    it('skips a subtree when enterNode returns DONT_TRAVERSE_CHILDREN', function () {
        $collector = new class extends AbstractNodeVisitor {
            public array $seen = [];
            public function enterNode(array $node): array|int|null {
                $this->seen[] = $node['$$type'];
                // Do not descend into the outer element's children.
                return $node['$$type'] === NodeType::PHPX_ELEMENT
                    ? NodeTraverser::DONT_TRAVERSE_CHILDREN
                    : null;
            }
        };

        (new NodeTraverser($collector))->traverse(astOf('<div><span>hi</span></div>'));

        // Only the outer <div> is visited; its children (span, text) are skipped.
        expect($collector->seen)->toBe([NodeType::PHPX_ELEMENT]);
    });

    it('applies visitors in registration order, each seeing the prior result', function () {
        $toBar = new class extends AbstractNodeVisitor {
            public function enterNode(array $node): array|int|null {
                if ($node['$$type'] !== NodeType::PHPX_ELEMENT) return null;
                $o = $node['openingElement'][1];
                $node['openingElement'][1] = new Token(T_STRING, 'bar', $o->line, $o->pos);
                return $node;
            }
        };
        $barToBaz = new class extends AbstractNodeVisitor {
            public function enterNode(array $node): array|int|null {
                if ($node['$$type'] !== NodeType::PHPX_ELEMENT) return null;
                if ($node['openingElement'][1]->text !== 'bar') return null;
                $o = $node['openingElement'][1];
                $node['openingElement'][1] = new Token(T_STRING, 'baz', $o->line, $o->pos);
                return $node;
            }
        };

        $out = (new NodeTraverser($toBar, $barToBaz))->traverse(astOf('<foo />'));

        expect($out[0]['openingElement'][1]->text)->toBe('baz');
    });
});

describe('NodeTraverser via Compiler', function () {
    it('produces identical output with no visitors', function () {
        $src = '<div id="x">hi</div>';
        $plain = (new Compiler())->compile($src);
        $withEmpty = (new Compiler(visitors: [new class extends AbstractNodeVisitor {}]))->compile($src);

        expect($withEmpty)->toBe($plain);
    });

    it('rewrites a tag name end-to-end', function () {
        $rename = new class extends AbstractNodeVisitor {
            public function enterNode(array $node): array|int|null {
                if ($node['$$type'] !== NodeType::PHPX_ELEMENT) return null;
                $name = $node['openingElement'][1];
                $text = is_array($name)
                    ? implode('', array_map(fn(Token $t) => $t->text, $name))
                    : $name->text;
                if ($text !== 'foo') return null;
                $origin = is_array($name) ? $name[0] : $name;
                $node['openingElement'][1] = new Token(T_STRING, 'bar', $origin->line, $origin->pos);
                return $node;
            }
        };

        expect((new Compiler(visitors: [$rename]))->compile('<foo>hi</foo>'))
            ->toBe("['\$', 'bar', null, ['hi']]");
    });

    it('strips comment nodes end-to-end', function () {
        $strip = new class extends AbstractNodeVisitor {
            public function leaveNode(array $node): array|int|null {
                return $node['$$type'] === NodeType::PHPX_COMMENT
                    ? NodeTraverser::REMOVE_NODE
                    : null;
            }
        };

        // Without the visitor the comment is emitted; with it, it is gone.
        $withComment = (new Compiler())->compile('<div>a{/* x */}b</div>');
        $stripped = (new Compiler(visitors: [$strip]))->compile('<div>a{/* x */}b</div>');

        expect($withComment)->toContain('/* x */');
        expect($stripped)->not->toContain('/* x */');
    });

    it('removes an attribute node end-to-end', function () {
        $dropDataX = new class extends AbstractNodeVisitor {
            public function leaveNode(array $node): array|int|null {
                if ($node['$$type'] !== NodeType::PHPX_ATTRIBUTE) return null;
                $name = $node['name'];
                $text = is_array($name)
                    ? implode('', array_map(fn(Token $t) => $t->text, $name))
                    : $name->text;
                return $text === 'id' ? NodeTraverser::REMOVE_NODE : null;
            }
        };

        expect((new Compiler(visitors: [$dropDataX]))->compile('<div id="x" className="y">hi</div>'))
            ->toBe("['\$', 'div', ['className'=>\"y\"], ['hi']]");
    });
});
