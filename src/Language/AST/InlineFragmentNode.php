<?php

declare(strict_types=1);

namespace GraphQL\Language\AST;

class InlineFragmentNode extends Node implements SelectionNode
{
    /** @var string */
    public $kind = NodeKind::INLINE_FRAGMENT;

    public ?NamedTypeNode $typeCondition = null;

    /** @var NodeList<DirectiveNode> */
    public NodeList $directives;

    public SelectionSetNode $selectionSet;
}
