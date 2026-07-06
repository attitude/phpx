<?php declare(strict_types=1);

namespace Attitude\PHPX\Compiler;

/**
 * Convenience base for node visitors: both hooks keep the node unchanged, so a
 * visitor only overrides the one(s) it needs.
 */
abstract class AbstractNodeVisitor implements NodeVisitor
{
    public function enterNode(array $node): array|int|null
    {
        return null;
    }

    public function leaveNode(array $node): array|int|null
    {
        return null;
    }
}
