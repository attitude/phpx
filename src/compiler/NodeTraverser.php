<?php declare(strict_types=1);

namespace Attitude\PHPX\Compiler;

/**
 * Walks the PHPX AST and applies NodeVisitors.
 *
 * The AST is a concrete tree of associative arrays. A value is treated as a
 * traversable node when it is an array carrying a `'$$type'` key; plain lists
 * (children, attributes, opening/closing element token runs) are walked element
 * by element, and Tokens/scalars are left alone. This is schema-free: only
 * semantic nodes are visited, because only they carry `$$type`.
 *
 * Each visitor is applied as a complete pass in registration order, so a later
 * visitor sees the tree as transformed by earlier ones.
 */
final class NodeTraverser
{
    /** Return from leaveNode()/enterNode() to drop a node from its parent list. */
    public const REMOVE_NODE = 1;

    /** Return from enterNode() to skip traversing the node's children. */
    public const DONT_TRAVERSE_CHILDREN = 2;

    /** @var NodeVisitor[] */
    private array $visitors;

    public function __construct(NodeVisitor ...$visitors)
    {
        $this->visitors = $visitors;
    }

    /**
     * @param array $ast List of top-level nodes.
     * @return array Transformed list of top-level nodes.
     */
    public function traverse(array $ast): array
    {
        foreach ($this->visitors as $visitor) {
            $ast = $this->traverseList($ast, $visitor);
        }

        return $ast;
    }

    /** Traverse a list of values, visiting node elements and honouring replace/remove. */
    private function traverseList(array $nodes, NodeVisitor $visitor): array
    {
        $result = [];

        foreach ($nodes as $value) {
            if (!self::isNode($value)) {
                $result[] = $value; // Token, token run, scalar — untouched.
                continue;
            }

            $visited = $this->traverseNode($value, $visitor);
            if ($visited === self::REMOVE_NODE) {
                continue;
            }

            $result[] = $visited;
        }

        return $result;
    }

    /**
     * Visit one node: enterNode, recurse children, leaveNode.
     *
     * @return array|int The (possibly replaced) node, or REMOVE_NODE.
     */
    private function traverseNode(array $node, NodeVisitor $visitor): array|int
    {
        $traverseChildren = true;

        $entered = $visitor->enterNode($node);
        if ($entered === null) {
            // keep node unchanged
        } elseif ($entered === self::REMOVE_NODE) {
            return self::REMOVE_NODE;
        } elseif ($entered === self::DONT_TRAVERSE_CHILDREN) {
            $traverseChildren = false;
        } elseif (self::isNode($entered)) {
            $node = $entered;
        } else {
            throw new \InvalidArgumentException(
                'NodeVisitor::enterNode() must return null, a node array with a $$type, '
                . 'or a NodeTraverser control constant (REMOVE_NODE / DONT_TRAVERSE_CHILDREN).',
            );
        }

        if ($traverseChildren) {
            $node = $this->traverseChildren($node, $visitor);
        }

        $left = $visitor->leaveNode($node);
        if ($left === null) {
            // keep node unchanged
        } elseif ($left === self::REMOVE_NODE) {
            return self::REMOVE_NODE;
        } elseif (self::isNode($left)) {
            $node = $left;
        } else {
            throw new \InvalidArgumentException(
                'NodeVisitor::leaveNode() must return null, a node array with a $$type, '
                . 'or NodeTraverser::REMOVE_NODE.',
            );
        }

        return $node;
    }

    /** Recurse into every field that holds a node or a list of nodes. */
    private function traverseChildren(array $node, NodeVisitor $visitor): array
    {
        foreach ($node as $key => $value) {
            if ($key === '$$type') {
                continue;
            }

            if (self::isNode($value)) {
                $visited = $this->traverseNode($value, $visitor);
                // A required single-child field can't be removed without corrupting
                // the parent; removal is only honoured inside lists.
                if ($visited !== self::REMOVE_NODE) {
                    $node[$key] = $visited;
                }
            } elseif (is_array($value) && array_is_list($value)) {
                $node[$key] = $this->traverseList($value, $visitor);
            }
            // else: Token / scalar / bool, or an associative (non-node) array — leaf.
        }

        return $node;
    }

    private static function isNode(mixed $value): bool
    {
        return is_array($value) && isset($value['$$type']);
    }
}
