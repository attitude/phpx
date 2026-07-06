<?php declare(strict_types=1);

namespace Attitude\PHPX\Compiler;

/**
 * Transforms the PHPX AST between parsing and compilation.
 *
 * This is the tree-transformation extension point — orthogonal to
 * FormatterInterface, which controls output shape. A visitor inspects, rewrites,
 * or removes AST nodes; the Compiler then compiles whatever tree it produces.
 *
 * Nodes are associative arrays tagged with a `'$$type' => NodeType`. The
 * NodeTraverser walks every semantic node (anything with a `$$type`); structural
 * tokens (brackets, whitespace) are left untouched.
 *
 * enterNode() runs top-down before a node's children are visited; leaveNode()
 * runs bottom-up after. Both may:
 *   - return null to keep the node unchanged;
 *   - return a replacement node array;
 *   - return NodeTraverser::REMOVE_NODE to drop the node (only meaningful for
 *     nodes inside a list, e.g. element children or attributes).
 * enterNode() may additionally return NodeTraverser::DONT_TRAVERSE_CHILDREN to
 * skip the node's subtree.
 *
 * Visitors are applied in registration order, each as a full pass over the tree.
 *
 * Example — drop every comment node:
 *
 *   class StripComments extends AbstractNodeVisitor {
 *       public function leaveNode(array $node): array|int|null {
 *           return $node['$$type'] === NodeType::PHPX_COMMENT
 *               ? NodeTraverser::REMOVE_NODE
 *               : null;
 *       }
 *   }
 */
interface NodeVisitor
{
    /**
     * @param array $node The node being entered (top-down).
     * @return array|int|null Replacement node, a NodeTraverser control constant, or null to keep.
     */
    public function enterNode(array $node): array|int|null;

    /**
     * @param array $node The node being left (bottom-up), after its children were visited.
     * @return array|int|null Replacement node, NodeTraverser::REMOVE_NODE, or null to keep.
     */
    public function leaveNode(array $node): array|int|null;
}
