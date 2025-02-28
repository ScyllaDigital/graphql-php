<?php

declare(strict_types=1);

namespace GraphQL\Type;

use GraphQL\Error\Error;
use GraphQL\Language\AST\DirectiveDefinitionNode;
use GraphQL\Language\AST\DirectiveNode;
use GraphQL\Language\AST\EnumTypeDefinitionNode;
use GraphQL\Language\AST\EnumTypeExtensionNode;
use GraphQL\Language\AST\EnumValueDefinitionNode;
use GraphQL\Language\AST\FieldDefinitionNode;
use GraphQL\Language\AST\InputObjectTypeDefinitionNode;
use GraphQL\Language\AST\InputObjectTypeExtensionNode;
use GraphQL\Language\AST\InputValueDefinitionNode;
use GraphQL\Language\AST\InterfaceTypeDefinitionNode;
use GraphQL\Language\AST\InterfaceTypeExtensionNode;
use GraphQL\Language\AST\ListTypeNode;
use GraphQL\Language\AST\NamedTypeNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\NodeList;
use GraphQL\Language\AST\NonNullTypeNode;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use GraphQL\Language\AST\ObjectTypeExtensionNode;
use GraphQL\Language\AST\OperationTypeDefinitionNode;
use GraphQL\Language\AST\ScalarTypeDefinitionNode;
use GraphQL\Language\AST\ScalarTypeExtensionNode;
use GraphQL\Language\AST\SchemaDefinitionNode;
use GraphQL\Language\AST\SchemaTypeExtensionNode;
use GraphQL\Language\AST\TypeDefinitionNode;
use GraphQL\Language\AST\TypeNode;
use GraphQL\Language\AST\UnionTypeDefinitionNode;
use GraphQL\Language\AST\UnionTypeExtensionNode;
use GraphQL\Language\DirectiveLocation;
use GraphQL\Type\Definition\Directive;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\EnumValueDefinition;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\ImplementingType;
use GraphQL\Type\Definition\InputObjectField;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\NamedType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Type\Validation\InputObjectCircularRefs;
use GraphQL\Utils\TypeComparators;
use GraphQL\Utils\Utils;

use function array_filter;
use function array_key_exists;
use function array_merge;
use function count;
use function in_array;
use function is_array;
use function is_object;
use function sprintf;

class SchemaValidationContext
{
    /** @var array<int, Error> */
    private array $errors = [];

    private Schema $schema;

    private InputObjectCircularRefs $inputObjectCircularRefs;

    public function __construct(Schema $schema)
    {
        $this->schema                  = $schema;
        $this->inputObjectCircularRefs = new InputObjectCircularRefs($this);
    }

    /**
     * @return array<int, Error>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function validateRootTypes(): void
    {
        if ($this->schema->getQueryType() === null) {
            $this->reportError(
                'Query root type must be provided.',
                $this->schema->getAstNode()
            );
        }

        // Triggers a type error if wrong
        $this->schema->getMutationType();
        $this->schema->getSubscriptionType();
    }

    /**
     * @param array<Node>|Node|null $nodes
     */
    public function reportError(string $message, $nodes = null): void
    {
        $nodes = array_filter(is_array($nodes) ? $nodes : [$nodes]);
        $this->addError(new Error($message, $nodes));
    }

    private function addError(Error $error): void
    {
        $this->errors[] = $error;
    }

    /**
     * @return NamedTypeNode|(Node&TypeDefinitionNode)|null
     */
    private function getOperationTypeNode(Type $type, string $operation): ?Node
    {
        $astNode = $this->schema->getAstNode();

        $operationTypeNode = null;
        if ($astNode instanceof SchemaDefinitionNode) {
            /** @var OperationTypeDefinitionNode|null $operationTypeNode */
            $operationTypeNode = null;

            foreach ($astNode->operationTypes as $operationType) {
                if ($operationType->operation === $operation) {
                    $operationTypeNode = $operationType;
                    break;
                }
            }
        }

        return $operationTypeNode === null
            ? ($type === null ? null : $type->astNode)
            : $operationTypeNode->type;
    }

    public function validateDirectives(): void
    {
        $this->validateDirectiveDefinitions();

        // Validate directives that are used on the schema
        $this->validateDirectivesAtLocation(
            $this->getDirectives($this->schema),
            DirectiveLocation::SCHEMA
        );
    }

    public function validateDirectiveDefinitions(): void
    {
        $directiveDefinitions = [];

        $directives = $this->schema->getDirectives();
        foreach ($directives as $directive) {
            // Ensure all directives are in fact GraphQL directives.
            if (! $directive instanceof Directive) {
                $nodes = is_object($directive)
                    ? $directive->astNode
                    : null;

                $this->reportError(
                    'Expected directive but got: ' . Utils::printSafe($directive) . '.',
                    $nodes
                );
                continue;
            }

            $existingDefinitions                    = $directiveDefinitions[$directive->name] ?? [];
            $existingDefinitions[]                  = $directive;
            $directiveDefinitions[$directive->name] = $existingDefinitions;

            // Ensure they are named correctly.
            $this->validateName($directive);

            // TODO: Ensure proper locations.

            $argNames = [];
            foreach ($directive->args as $arg) {
                // Ensure they are named correctly.
                $this->validateName($arg);

                $argName = $arg->name;

                if (isset($argNames[$argName])) {
                    $this->reportError(
                        sprintf('Argument @%s(%s:) can only be defined once.', $directive->name, $argName),
                        $this->getAllDirectiveArgNodes($directive, $argName)
                    );
                    continue;
                }

                $argNames[$argName] = true;

                // Ensure the type is an input type.
                if (Type::isInputType($arg->getType())) {
                    continue;
                }

                $this->reportError(
                    sprintf(
                        'The type of @%s(%s:) must be Input Type but got: %s.',
                        $directive->name,
                        $argName,
                        Utils::printSafe($arg->getType())
                    ),
                    $this->getDirectiveArgTypeNode($directive, $argName)
                );
            }
        }

        foreach ($directiveDefinitions as $directiveName => $directiveList) {
            if (count($directiveList) <= 1) {
                continue;
            }

            $nodes = [];
            foreach ($directiveList as $dir) {
                if ($dir->astNode === null) {
                    continue;
                }

                $nodes[] = $dir->astNode;
            }

            $this->reportError(
                sprintf('Directive @%s defined multiple times.', $directiveName),
                $nodes
            );
        }
    }

    /**
     * @param Type|Directive|FieldDefinition|EnumValueDefinition|InputObjectField $object
     */
    private function validateName(object $object): void
    {
        // Ensure names are valid, however introspection types opt out.
        $error = Utils::isValidNameError($object->name, $object->astNode);
        if (
            $error === null
            || ($object instanceof Type && Introspection::isIntrospectionType($object))
        ) {
            return;
        }

        $this->addError($error);
    }

    /**
     * @return array<int, InputValueDefinitionNode>
     */
    private function getAllDirectiveArgNodes(Directive $directive, string $argName): array
    {
        $subNodes = $this->getAllSubNodes(
            $directive,
            /**
             * @return NodeList<InputValueDefinitionNode>
             */
            static function (DirectiveDefinitionNode $directiveNode): NodeList {
                return $directiveNode->arguments;
            }
        );

        return Utils::filter(
            $subNodes,
            static function (InputValueDefinitionNode $argNode) use ($argName): bool {
                return $argNode->name->value === $argName;
            }
        );
    }

    /**
     * @return NamedTypeNode|ListTypeNode|NonNullTypeNode|null
     */
    private function getDirectiveArgTypeNode(Directive $directive, string $argName): ?TypeNode
    {
        $argNode = $this->getAllDirectiveArgNodes($directive, $argName)[0] ?? null;

        return $argNode === null
            ? null
            : $argNode->type;
    }

    public function validateTypes(): void
    {
        $typeMap = $this->schema->getTypeMap();
        foreach ($typeMap as $typeName => $type) {
            // Ensure all provided types are in fact GraphQL type.
            if (! $type instanceof NamedType) {
                $this->reportError(
                    'Expected GraphQL named type but got: ' . Utils::printSafe($type) . '.',
                    $type instanceof Type ? $type->astNode : null
                );
                continue;
            }

            $this->validateName($type);

            if ($type instanceof ObjectType) {
                // Ensure fields are valid
                $this->validateFields($type);

                // Ensure objects implement the interfaces they claim to.
                $this->validateInterfaces($type);

                // Ensure directives are valid
                $this->validateDirectivesAtLocation(
                    $this->getDirectives($type),
                    DirectiveLocation::OBJECT
                );
            } elseif ($type instanceof InterfaceType) {
                // Ensure fields are valid.
                $this->validateFields($type);

                // Ensure interfaces implement the interfaces they claim to.
                $this->validateInterfaces($type);

                // Ensure directives are valid
                $this->validateDirectivesAtLocation(
                    $this->getDirectives($type),
                    DirectiveLocation::IFACE
                );
            } elseif ($type instanceof UnionType) {
                // Ensure Unions include valid member types.
                $this->validateUnionMembers($type);

                // Ensure directives are valid
                $this->validateDirectivesAtLocation(
                    $this->getDirectives($type),
                    DirectiveLocation::UNION
                );
            } elseif ($type instanceof EnumType) {
                // Ensure Enums have valid values.
                $this->validateEnumValues($type);

                // Ensure directives are valid
                $this->validateDirectivesAtLocation(
                    $this->getDirectives($type),
                    DirectiveLocation::ENUM
                );
            } elseif ($type instanceof InputObjectType) {
                // Ensure Input Object fields are valid.
                $this->validateInputFields($type);

                // Ensure directives are valid
                $this->validateDirectivesAtLocation(
                    $this->getDirectives($type),
                    DirectiveLocation::INPUT_OBJECT
                );

                // Ensure Input Objects do not contain non-nullable circular references
                $this->inputObjectCircularRefs->validate($type);
            } elseif ($type instanceof ScalarType) {
                // Ensure directives are valid
                $this->validateDirectivesAtLocation(
                    $this->getDirectives($type),
                    DirectiveLocation::SCALAR
                );
            }
        }
    }

    /**
     * @param NodeList<DirectiveNode> $directives
     */
    private function validateDirectivesAtLocation(NodeList $directives, string $location): void
    {
        /** @var array<string, array<int, DirectiveNode>> $potentiallyDuplicateDirectives */
        $potentiallyDuplicateDirectives = [];
        $schema                         = $this->schema;
        foreach ($directives as $directive) {
            $directiveName = $directive->name->value;

            // Ensure directive used is also defined
            $schemaDirective = $schema->getDirective($directiveName);
            if ($schemaDirective === null) {
                $this->reportError(
                    sprintf('No directive @%s defined.', $directiveName),
                    $directive
                );
                continue;
            }

            $includes = Utils::some(
                $schemaDirective->locations,
                static function ($schemaLocation) use ($location): bool {
                    return $schemaLocation === $location;
                }
            );
            if (! $includes) {
                $errorNodes = $schemaDirective->astNode === null
                    ? [$directive]
                    : [$directive, $schemaDirective->astNode];
                $this->reportError(
                    sprintf('Directive @%s not allowed at %s location.', $directiveName, $location),
                    $errorNodes
                );
            }

            if ($schemaDirective->isRepeatable) {
                continue;
            }

            $existingNodes                                  = $potentiallyDuplicateDirectives[$directiveName] ?? [];
            $existingNodes[]                                = $directive;
            $potentiallyDuplicateDirectives[$directiveName] = $existingNodes;
        }

        foreach ($potentiallyDuplicateDirectives as $directiveName => $directiveList) {
            if (count($directiveList) <= 1) {
                continue;
            }

            $this->reportError(
                sprintf('Non-repeatable directive @%s used more than once at the same location.', $directiveName),
                $directiveList
            );
        }
    }

    /**
     * @param ObjectType|InterfaceType $type
     */
    private function validateFields(Type $type): void
    {
        $fieldMap = $type->getFields();

        // Objects and Interfaces both must define one or more fields.
        if ($fieldMap === []) {
            $this->reportError(
                sprintf('Type %s must define one or more fields.', $type->name),
                $this->getAllNodes($type)
            );
        }

        foreach ($fieldMap as $fieldName => $field) {
            // Ensure they are named correctly.
            $this->validateName($field);

            // Ensure they were defined at most once.
            $fieldNodes = $this->getAllFieldNodes($type, $fieldName);
            if (count($fieldNodes) > 1) {
                $this->reportError(
                    sprintf('Field %s.%s can only be defined once.', $type->name, $fieldName),
                    $fieldNodes
                );
                continue;
            }

            // Ensure the type is an output type
            if (! Type::isOutputType($field->getType())) {
                $this->reportError(
                    sprintf(
                        'The type of %s.%s must be Output Type but got: %s.',
                        $type->name,
                        $fieldName,
                        Utils::printSafe($field->getType())
                    ),
                    $this->getFieldTypeNode($type, $fieldName)
                );
            }

            // Ensure the arguments are valid
            $argNames = [];
            foreach ($field->args as $arg) {
                $argName = $arg->name;

                // Ensure they are named correctly.
                $this->validateName($arg);

                if (isset($argNames[$argName])) {
                    $this->reportError(
                        sprintf(
                            'Field argument %s.%s(%s:) can only be defined once.',
                            $type->name,
                            $fieldName,
                            $argName
                        ),
                        $this->getAllFieldArgNodes($type, $fieldName, $argName)
                    );
                }

                $argNames[$argName] = true;

                // Ensure the type is an input type
                if (! Type::isInputType($arg->getType())) {
                    $this->reportError(
                        sprintf(
                            'The type of %s.%s(%s:) must be Input Type but got: %s.',
                            $type->name,
                            $fieldName,
                            $argName,
                            Utils::printSafe($arg->getType())
                        ),
                        $this->getFieldArgTypeNode($type, $fieldName, $argName)
                    );
                }

                // Ensure argument definition directives are valid
                if (! isset($arg->astNode, $arg->astNode->directives)) {
                    continue;
                }

                $this->validateDirectivesAtLocation(
                    $arg->astNode->directives,
                    DirectiveLocation::ARGUMENT_DEFINITION
                );
            }

            // Ensure any directives are valid
            if (! isset($field->astNode, $field->astNode->directives)) {
                continue;
            }

            $this->validateDirectivesAtLocation(
                $field->astNode->directives,
                DirectiveLocation::FIELD_DEFINITION
            );
        }
    }

    /**
     * @param Schema|ObjectType|InterfaceType|UnionType|EnumType|InputObjectType|Directive $obj
     *
     * @return array<int, SchemaDefinitionNode|SchemaTypeExtensionNode>|array<int, ObjectTypeDefinitionNode|ObjectTypeExtensionNode>|array<int, InterfaceTypeDefinitionNode|InterfaceTypeExtensionNode>|array<int, UnionTypeDefinitionNode|UnionTypeExtensionNode>|array<int, EnumTypeDefinitionNode|EnumTypeExtensionNode>|array<int, InputObjectTypeDefinitionNode|InputObjectTypeExtensionNode>|array<int, DirectiveDefinitionNode>
     */
    private function getAllNodes(object $obj): array
    {
        if ($obj instanceof Schema) {
            $astNode        = $obj->getAstNode();
            $extensionNodes = $obj->extensionASTNodes;
        } elseif ($obj instanceof Directive) {
            $astNode        = $obj->astNode;
            $extensionNodes = [];
        } else {
            $astNode        = $obj->astNode;
            $extensionNodes = $obj->extensionASTNodes;
        }

        return $astNode !== null
            ? array_merge([$astNode], $extensionNodes)
            : $extensionNodes;
    }

    /**
     * @param Schema|ObjectType|InterfaceType|UnionType|EnumType|Directive $obj
     * @param callable(Node): (iterable<Node>|null)                        $getter
     */
    private function getAllSubNodes(object $obj, callable $getter): NodeList
    {
        $result = new NodeList([]);
        foreach ($this->getAllNodes($obj) as $astNode) {
            if ($astNode === null) {
                continue;
            }

            $subNodes = $getter($astNode);
            if ($subNodes === null) {
                continue;
            }

            $result = $result->merge($subNodes);
        }

        return $result;
    }

    /**
     * @param ObjectType|InterfaceType $type
     *
     * @return array<int, FieldDefinitionNode>
     */
    private function getAllFieldNodes(Type $type, string $fieldName): array
    {
        $subNodes = $this->getAllSubNodes(
            $type,
            /**
             * @return NodeList<FieldDefinitionNode>
             */
            static function (Node $typeNode): NodeList {
                /** @var ObjectTypeDefinitionNode|ObjectTypeExtensionNode|InterfaceTypeDefinitionNode|InterfaceTypeExtensionNode $typeNode */
                return $typeNode->fields;
            }
        );

        return Utils::filter($subNodes, static function ($fieldNode) use ($fieldName): bool {
            return $fieldNode->name->value === $fieldName;
        });
    }

    /**
     * @param ObjectType|InterfaceType $type
     *
     * @return NamedTypeNode|ListTypeNode|NonNullTypeNode|null
     */
    private function getFieldTypeNode(Type $type, string $fieldName): ?TypeNode
    {
        $fieldNode = $this->getFieldNode($type, $fieldName);

        return $fieldNode === null
            ? null
            : $fieldNode->type;
    }

    /**
     * @param ObjectType|InterfaceType $type
     */
    private function getFieldNode(Type $type, string $fieldName): ?FieldDefinitionNode
    {
        $nodes = $this->getAllFieldNodes($type, $fieldName);

        return $nodes[0] ?? null;
    }

    /**
     * @param ObjectType|InterfaceType $type
     *
     * @return array<int, InputValueDefinitionNode>
     */
    private function getAllFieldArgNodes(Type $type, string $fieldName, string $argName): array
    {
        $argNodes  = [];
        $fieldNode = $this->getFieldNode($type, $fieldName);
        if ($fieldNode !== null && $fieldNode->arguments !== null) {
            foreach ($fieldNode->arguments as $node) {
                if ($node->name->value !== $argName) {
                    continue;
                }

                $argNodes[] = $node;
            }
        }

        return $argNodes;
    }

    /**
     * @param ObjectType|InterfaceType $type
     *
     * @return NamedTypeNode|ListTypeNode|NonNullTypeNode|null
     */
    private function getFieldArgTypeNode(Type $type, string $fieldName, string $argName): ?TypeNode
    {
        $fieldArgNode = $this->getFieldArgNode($type, $fieldName, $argName);

        return $fieldArgNode === null
            ? null
            : $fieldArgNode->type;
    }

    /**
     * @param ObjectType|InterfaceType $type
     */
    private function getFieldArgNode(Type $type, string $fieldName, string $argName): ?InputValueDefinitionNode
    {
        $nodes = $this->getAllFieldArgNodes($type, $fieldName, $argName);

        return $nodes[0] ?? null;
    }

    /**
     * @param ObjectType|InterfaceType $type
     */
    private function validateInterfaces(ImplementingType $type): void
    {
        $ifaceTypeNames = [];
        foreach ($type->getInterfaces() as $iface) {
            if (! $iface instanceof InterfaceType) {
                $safeIface = Utils::printSafe($iface);
                $this->reportError(
                    "Type {$type->name} must only implement Interface types, it cannot implement {$safeIface}.",
                    $this->getImplementsInterfaceNode($type, $iface)
                );
                continue;
            }

            if ($type === $iface) {
                $this->reportError(
                    "Type {$type->name} cannot implement itself because it would create a circular reference.",
                    $this->getImplementsInterfaceNode($type, $iface)
                );
                continue;
            }

            if (isset($ifaceTypeNames[$iface->name])) {
                $this->reportError(
                    "Type {$type->name} can only implement {$iface->name} once.",
                    $this->getAllImplementsInterfaceNodes($type, $iface)
                );
                continue;
            }

            $ifaceTypeNames[$iface->name] = true;

            $this->validateTypeImplementsAncestors($type, $iface);
            $this->validateTypeImplementsInterface($type, $iface);
        }
    }

    /**
     * @param Schema|Type $object
     *
     * @return NodeList<DirectiveNode>
     */
    private function getDirectives(object $object): NodeList
    {
        return $this->getAllSubNodes(
            $object,
            /**
             * @return NodeList<DirectiveNode>
             */
            static function (Node $node): NodeList {
                /** @var SchemaDefinitionNode|SchemaTypeExtensionNode|ObjectTypeDefinitionNode|ObjectTypeExtensionNode|InterfaceTypeDefinitionNode|InterfaceTypeExtensionNode|UnionTypeDefinitionNode|UnionTypeExtensionNode|EnumTypeDefinitionNode|EnumTypeExtensionNode|InputObjectTypeDefinitionNode|InputObjectTypeExtensionNode|ScalarTypeDefinitionNode|ScalarTypeExtensionNode $node */
                return $node->directives;
            }
        );
    }

    /**
     * @param ObjectType|InterfaceType $type
     */
    private function getImplementsInterfaceNode(ImplementingType $type, Type $shouldBeInterface): ?NamedTypeNode
    {
        $nodes = $this->getAllImplementsInterfaceNodes($type, $shouldBeInterface);

        return $nodes[0] ?? null;
    }

    /**
     * @param ObjectType|InterfaceType $type
     *
     * @return array<int, NamedTypeNode>
     */
    private function getAllImplementsInterfaceNodes(ImplementingType $type, Type $shouldBeInterface): array
    {
        $subNodes = $this->getAllSubNodes(
            $type,
            /**
             * @return NodeList<NamedTypeNode>
             */
            static function (Node $typeNode): NodeList {
                /** @var ObjectTypeDefinitionNode|ObjectTypeExtensionNode|InterfaceTypeDefinitionNode|InterfaceTypeExtensionNode $typeNode */
                return $typeNode->interfaces;
            }
        );

        return Utils::filter($subNodes, static function (NamedTypeNode $ifaceNode) use ($shouldBeInterface): bool {
            return $ifaceNode->name->value === $shouldBeInterface->name;
        });
    }

    /**
     * @param ObjectType|InterfaceType $type
     */
    private function validateTypeImplementsInterface(ImplementingType $type, InterfaceType $iface): void
    {
        $typeFieldMap  = $type->getFields();
        $ifaceFieldMap = $iface->getFields();

        // Assert each interface field is implemented.
        foreach ($ifaceFieldMap as $fieldName => $ifaceField) {
            $typeField = array_key_exists($fieldName, $typeFieldMap)
                ? $typeFieldMap[$fieldName]
                : null;

            // Assert interface field exists on type.
            if ($typeField === null) {
                $this->reportError(
                    sprintf(
                        'Interface field %s.%s expected but %s does not provide it.',
                        $iface->name,
                        $fieldName,
                        $type->name
                    ),
                    array_merge(
                        [$this->getFieldNode($iface, $fieldName)],
                        $this->getAllNodes($type)
                    )
                );
                continue;
            }

            // Assert interface field type is satisfied by type field type, by being
            // a valid subtype. (covariant)
            if (
                ! TypeComparators::isTypeSubTypeOf(
                    $this->schema,
                    $typeField->getType(),
                    $ifaceField->getType()
                )
            ) {
                $this->reportError(
                    sprintf(
                        'Interface field %s.%s expects type %s but %s.%s is type %s.',
                        $iface->name,
                        $fieldName,
                        $ifaceField->getType(),
                        $type->name,
                        $fieldName,
                        Utils::printSafe($typeField->getType())
                    ),
                    [
                        $this->getFieldTypeNode($iface, $fieldName),
                        $this->getFieldTypeNode($type, $fieldName),
                    ]
                );
            }

            // Assert each interface field arg is implemented.
            foreach ($ifaceField->args as $ifaceArg) {
                $argName = $ifaceArg->name;
                $typeArg = null;

                foreach ($typeField->args as $arg) {
                    if ($arg->name === $argName) {
                        $typeArg = $arg;
                        break;
                    }
                }

                // Assert interface field arg exists on type field.
                if ($typeArg === null) {
                    $this->reportError(
                        sprintf(
                            'Interface field argument %s.%s(%s:) expected but %s.%s does not provide it.',
                            $iface->name,
                            $fieldName,
                            $argName,
                            $type->name,
                            $fieldName
                        ),
                        [
                            $this->getFieldArgNode($iface, $fieldName, $argName),
                            $this->getFieldNode($type, $fieldName),
                        ]
                    );
                    continue;
                }

                // Assert interface field arg type matches type field arg type.
                // (invariant)
                // TODO: change to contravariant?
                if (! TypeComparators::isEqualType($ifaceArg->getType(), $typeArg->getType())) {
                    $this->reportError(
                        sprintf(
                            'Interface field argument %s.%s(%s:) expects type %s but %s.%s(%s:) is type %s.',
                            $iface->name,
                            $fieldName,
                            $argName,
                            Utils::printSafe($ifaceArg->getType()),
                            $type->name,
                            $fieldName,
                            $argName,
                            Utils::printSafe($typeArg->getType())
                        ),
                        [
                            $this->getFieldArgTypeNode($iface, $fieldName, $argName),
                            $this->getFieldArgTypeNode($type, $fieldName, $argName),
                        ]
                    );
                }

                // TODO: validate default values?
            }

            // Assert additional arguments must not be required.
            foreach ($typeField->args as $typeArg) {
                $argName  = $typeArg->name;
                $ifaceArg = null;

                foreach ($ifaceField->args as $arg) {
                    if ($arg->name === $argName) {
                        $ifaceArg = $arg;
                        break;
                    }
                }

                if ($ifaceArg !== null || ! $typeArg->isRequired()) {
                    continue;
                }

                $this->reportError(
                    sprintf(
                        'Object field %s.%s includes required argument %s that is missing from the Interface field %s.%s.',
                        $type->name,
                        $fieldName,
                        $argName,
                        $iface->name,
                        $fieldName
                    ),
                    [
                        $this->getFieldArgNode($type, $fieldName, $argName),
                        $this->getFieldNode($iface, $fieldName),
                    ]
                );
            }
        }
    }

    /**
     * @param ObjectType|InterfaceType $type
     */
    private function validateTypeImplementsAncestors(ImplementingType $type, InterfaceType $iface): void
    {
        $typeInterfaces = $type->getInterfaces();
        foreach ($iface->getInterfaces() as $transitive) {
            if (in_array($transitive, $typeInterfaces, true)) {
                continue;
            }

            $error = $transitive === $type ?
                sprintf(
                    'Type %s cannot implement %s because it would create a circular reference.',
                    $type->name,
                    $iface->name
                ) :
                sprintf(
                    'Type %s must implement %s because it is implemented by %s.',
                    $type->name,
                    $transitive->name,
                    $iface->name
                );
            $this->reportError(
                $error,
                array_merge(
                    $this->getAllImplementsInterfaceNodes($iface, $transitive),
                    $this->getAllImplementsInterfaceNodes($type, $iface)
                )
            );
        }
    }

    private function validateUnionMembers(UnionType $union): void
    {
        $memberTypes = $union->getTypes();

        if ($memberTypes === []) {
            $this->reportError(
                sprintf('Union type %s must define one or more member types.', $union->name),
                $this->getAllNodes($union)
            );
        }

        $includedTypeNames = [];

        foreach ($memberTypes as $memberType) {
            if (! $memberType instanceof ObjectType) {
                $this->reportError(
                    sprintf(
                        'Union type %s can only include Object types, it cannot include %s.',
                        $union->name,
                        Utils::printSafe($memberType)
                    ),
                    $this->getUnionMemberTypeNodes($union, Utils::printSafe($memberType))
                );
                continue;
            }

            if (isset($includedTypeNames[$memberType->name])) {
                $this->reportError(
                    sprintf('Union type %s can only include type %s once.', $union->name, $memberType->name),
                    $this->getUnionMemberTypeNodes($union, $memberType->name)
                );
                continue;
            }

            $includedTypeNames[$memberType->name] = true;
        }
    }

    /**
     * @return array<int, NamedTypeNode>
     */
    private function getUnionMemberTypeNodes(UnionType $union, string $typeName): array
    {
        $subNodes = $this->getAllSubNodes(
            $union,
            /**
             * @return NodeList<NamedTypeNode>
             */
            static function (Node $unionNode): NodeList {
                /** @var UnionTypeDefinitionNode|UnionTypeExtensionNode $unionNode */
                return $unionNode->types;
            }
        );

        return Utils::filter($subNodes, static function (NamedTypeNode $typeNode) use ($typeName): bool {
            return $typeNode->name->value === $typeName;
        });
    }

    private function validateEnumValues(EnumType $enumType): void
    {
        $enumValues = $enumType->getValues();

        if ($enumValues === []) {
            $this->reportError(
                sprintf('Enum type %s must define one or more values.', $enumType->name),
                $this->getAllNodes($enumType)
            );
        }

        foreach ($enumValues as $enumValue) {
            $valueName = $enumValue->name;

            // Ensure no duplicates
            $allNodes = $this->getEnumValueNodes($enumType, $valueName);
            if (count($allNodes) > 1) {
                $this->reportError(
                    sprintf('Enum type %s can include value %s only once.', $enumType->name, $valueName),
                    $allNodes
                );
            }

            // Ensure valid name.
            $this->validateName($enumValue);
            if ($valueName === 'true' || $valueName === 'false' || $valueName === 'null') {
                $this->reportError(
                    sprintf('Enum type %s cannot include value: %s.', $enumType->name, $valueName),
                    $enumValue->astNode
                );
            }

            // Ensure valid directives
            if (! isset($enumValue->astNode, $enumValue->astNode->directives)) {
                continue;
            }

            $this->validateDirectivesAtLocation(
                $enumValue->astNode->directives,
                DirectiveLocation::ENUM_VALUE
            );
        }
    }

    /**
     * @return array<int, EnumValueDefinitionNode>
     */
    private function getEnumValueNodes(EnumType $enum, string $valueName): array
    {
        $subNodes = $this->getAllSubNodes(
            $enum,
            /**
             * @param EnumTypeDefinitionNode|EnumTypeExtensionNode $enumNode
             *
             * @return NodeList<EnumValueDefinitionNode>
             */
            static function (Node $enumNode): NodeList {
                return $enumNode->values;
            }
        );

        return Utils::filter($subNodes, static function ($valueNode) use ($valueName): bool {
            return $valueNode->name->value === $valueName;
        });
    }

    private function validateInputFields(InputObjectType $inputObj): void
    {
        $fieldMap = $inputObj->getFields();

        if ($fieldMap === []) {
            $this->reportError(
                sprintf('Input Object type %s must define one or more fields.', $inputObj->name),
                $this->getAllNodes($inputObj)
            );
        }

        // Ensure the arguments are valid
        foreach ($fieldMap as $fieldName => $field) {
            // Ensure they are named correctly.
            $this->validateName($field);

            // TODO: Ensure they are unique per field.

            // Ensure the type is an input type
            if (! Type::isInputType($field->getType())) {
                $this->reportError(
                    sprintf(
                        'The type of %s.%s must be Input Type but got: %s.',
                        $inputObj->name,
                        $fieldName,
                        Utils::printSafe($field->getType())
                    ),
                    $field->astNode !== null
                        ? $field->astNode->type
                        : null
                );
            }

            // Ensure valid directives
            if (! isset($field->astNode, $field->astNode->directives)) {
                continue;
            }

            $this->validateDirectivesAtLocation(
                $field->astNode->directives,
                DirectiveLocation::INPUT_FIELD_DEFINITION
            );
        }
    }
}
