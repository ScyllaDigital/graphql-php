<?php

declare(strict_types=1);

namespace GraphQL\Language\AST;

class OperationDefinitionNode extends Node implements ExecutableDefinitionNode, HasSelectionSet
{
    /** @var string */
    public $kind = NodeKind::OPERATION_DEFINITION;

    public ?NameNode $name = null;

    /** @var string (oneOf 'query', 'mutation', 'subscription')) */
    public string $operation;

    /** @var NodeList<VariableDefinitionNode> */
    public NodeList $variableDefinitions;

    /** @var NodeList<DirectiveNode> */
    public NodeList $directives;

    public SelectionSetNode $selectionSet;
}
