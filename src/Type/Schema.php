<?php

declare(strict_types=1);

namespace GraphQL\Type;

use Generator;
use GraphQL\Error\Error;
use GraphQL\Error\InvariantViolation;
use GraphQL\GraphQL;
use GraphQL\Language\AST\SchemaDefinitionNode;
use GraphQL\Language\AST\SchemaTypeExtensionNode;
use GraphQL\Type\Definition\AbstractType;
use GraphQL\Type\Definition\Directive;
use GraphQL\Type\Definition\ImplementingType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Utils\InterfaceImplementations;
use GraphQL\Utils\TypeInfo;
use GraphQL\Utils\Utils;
use InvalidArgumentException;
use Traversable;

use function get_class;
use function implode;
use function is_array;
use function is_callable;
use function sprintf;

/**
 * Schema Definition (see [schema definition docs](schema-definition.md))
 *
 * A Schema is created by supplying the root types of each type of operation:
 * query, mutation (optional) and subscription (optional). A schema definition is
 * then supplied to the validator and executor. Usage Example:
 *
 *     $schema = new GraphQL\Type\Schema([
 *       'query' => $MyAppQueryRootType,
 *       'mutation' => $MyAppMutationRootType,
 *     ]);
 *
 * Or using Schema Config instance:
 *
 *     $config = GraphQL\Type\SchemaConfig::create()
 *         ->setQuery($MyAppQueryRootType)
 *         ->setMutation($MyAppMutationRootType);
 *
 *     $schema = new GraphQL\Type\Schema($config);
 */
class Schema
{
    private SchemaConfig $config;

    /**
     * Contains currently resolved schema types
     *
     * @var array<string, Type>
     */
    private array $resolvedTypes = [];

    /**
     * Lazily initialised.
     *
     * @var array<string, InterfaceImplementations>
     */
    private array $implementationsMap;

    /**
     * True when $resolvedTypes contains all possible schema types.
     */
    private bool $fullyLoaded = false;

    /** @var array<int, Error> */
    private array $validationErrors;

    /** @var array<int, SchemaTypeExtensionNode> */
    public $extensionASTNodes = [];

    /**
     * @param SchemaConfig|array<string, mixed> $config
     *
     * @api
     */
    public function __construct($config)
    {
        if (is_array($config)) {
            $config = SchemaConfig::create($config);
        }

        // If this schema was built from a source known to be valid, then it may be
        // marked with assumeValid to avoid an additional type system validation.
        if ($config->getAssumeValid()) {
            $this->validationErrors = [];
        }

        $this->config            = $config;
        $this->extensionASTNodes = $config->extensionASTNodes;

        // TODO can we make the following assumption hold true?
        // No need to check for the existence of the root query type
        // since we already validated the schema thus it must exist.
        $query = $config->query;
        if ($query !== null) {
            $this->resolvedTypes[$query->name] = $query;
        }

        $mutation = $config->mutation;
        if ($mutation !== null) {
            $this->resolvedTypes[$mutation->name] = $mutation;
        }

        $subscription = $config->subscription;
        if ($subscription !== null) {
            $this->resolvedTypes[$subscription->name] = $subscription;
        }

        $types = $this->config->types;
        if (is_array($types)) {
            foreach ($this->resolveAdditionalTypes() as $type) {
                $typeName = $type->name;
                if (isset($this->resolvedTypes[$typeName])) {
                    Utils::invariant(
                        $type === $this->resolvedTypes[$typeName],
                        'Schema must contain unique named types but contains multiple types named "' . $type . '" (see https://webonyx.github.io/graphql-php/type-definitions/#type-registry).'
                    );
                }

                $this->resolvedTypes[$typeName] = $type;
            }
        } elseif (! is_callable($types)) {
            throw new InvariantViolation(
                '"types" must be array or callable if provided but got: ' . Utils::getVariableType($types)
            );
        }

        $this->resolvedTypes += Type::getStandardTypes() + Introspection::getTypes();

        if (isset($this->config->typeLoader)) {
            return;
        }

        // Perform full scan of the schema
        $this->getTypeMap();
    }

    private function resolveAdditionalTypes(): Generator
    {
        $types = $this->config->types;

        if (is_callable($types)) {
            $types = $types();
        }

        if (! is_array($types) && ! $types instanceof Traversable) {
            throw new InvariantViolation(sprintf(
                'Schema types callable must return array or instance of Traversable but got: %s',
                Utils::getVariableType($types)
            ));
        }

        foreach ($types as $index => $type) {
            $type = self::resolveType($type);
            if (! $type instanceof Type) {
                throw new InvariantViolation(sprintf(
                    'Each entry of schema types must be instance of GraphQL\Type\Definition\Type but entry at %s is %s',
                    $index,
                    Utils::printSafe($type)
                ));
            }

            yield $type;
        }
    }

    /**
     * Returns all types in this schema.
     *
     * This operation requires a full schema scan. Do not use in production environment.
     *
     * @return array<string, Type> Keys represent type names, values are instances of corresponding type definitions
     *
     * @api
     */
    public function getTypeMap(): array
    {
        if (! $this->fullyLoaded) {
            $this->resolvedTypes = $this->collectAllTypes();
            $this->fullyLoaded   = true;
        }

        return $this->resolvedTypes;
    }

    /**
     * @return array<Type>
     */
    private function collectAllTypes(): array
    {
        $typeMap = [];
        foreach ($this->resolvedTypes as $type) {
            $typeMap = TypeInfo::extractTypes($type, $typeMap);
        }

        foreach ($this->getDirectives() as $directive) {
            if (! ($directive instanceof Directive)) {
                continue;
            }

            $typeMap = TypeInfo::extractTypesFromDirectives($directive, $typeMap);
        }

        // When types are set as array they are resolved in constructor
        if (is_callable($this->config->types)) {
            foreach ($this->resolveAdditionalTypes() as $type) {
                $typeMap = TypeInfo::extractTypes($type, $typeMap);
            }
        }

        return $typeMap;
    }

    /**
     * Returns a list of directives supported by this schema
     *
     * @return array<Directive>
     *
     * @api
     */
    public function getDirectives(): array
    {
        return $this->config->directives ?? GraphQL::getStandardDirectives();
    }

    public function getOperationType(string $operation): ?ObjectType
    {
        switch ($operation) {
            case 'query':
                return $this->getQueryType();

            case 'mutation':
                return $this->getMutationType();

            case 'subscription':
                return $this->getSubscriptionType();

            default:
                return null;
        }
    }

    /**
     * Returns root query type.
     *
     * @api
     */
    public function getQueryType(): ?ObjectType
    {
        return $this->config->query;
    }

    /**
     * Returns root mutation type.
     *
     * @api
     */
    public function getMutationType(): ?ObjectType
    {
        return $this->config->mutation;
    }

    /**
     * Returns schema subscription
     *
     * @api
     */
    public function getSubscriptionType(): ?ObjectType
    {
        return $this->config->subscription;
    }

    /**
     * @api
     */
    public function getConfig(): SchemaConfig
    {
        return $this->config;
    }

    /**
     * Returns a type by name.
     *
     * @api
     */
    public function getType(string $name): ?Type
    {
        if (! isset($this->resolvedTypes[$name])) {
            $type = $this->loadType($name);

            if ($type === null) {
                return null;
            }

            $this->resolvedTypes[$name] = self::resolveType($type);
        }

        return $this->resolvedTypes[$name];
    }

    public function hasType(string $name): bool
    {
        return $this->getType($name) !== null;
    }

    private function loadType(string $typeName): ?Type
    {
        $typeLoader = $this->config->typeLoader;

        if (! isset($typeLoader)) {
            return $this->defaultTypeLoader($typeName);
        }

        $type = $typeLoader($typeName);

        if (! $type instanceof Type) {
            // Unless you know what you're doing, kindly resist the temptation to refactor or simplify this block. The
            // twisty logic here is tuned for performance, and meant to prioritize the "happy path" (the result returned
            // from the type loader is already a Type), and only checks for callable if that fails. If the result is
            // neither a Type nor a callable, then we throw an exception.

            if (is_callable($type)) {
                $type = $type();

                if (! $type instanceof Type) {
                    $this->throwNotAType($type, $typeName);
                }
            } else {
                $this->throwNotAType($type, $typeName);
            }
        }

        if ($type->name !== $typeName) {
            throw new InvariantViolation(
                sprintf('Type loader is expected to return type "%s", but it returned "%s"', $typeName, $type->name)
            );
        }

        return $type;
    }

    protected function throwNotAType($type, string $typeName): void
    {
        throw new InvariantViolation(
            sprintf(
                'Type loader is expected to return a callable or valid type "%s", but it returned %s',
                $typeName,
                Utils::printSafe($type)
            )
        );
    }

    private function defaultTypeLoader(string $typeName): ?Type
    {
        // Default type loader simply falls back to collecting all types
        $typeMap = $this->getTypeMap();

        return $typeMap[$typeName] ?? null;
    }

    /**
     * @param Type|callable $type
     * @phpstan-param T|callable():T $type
     *
     * @phpstan-return T
     *
     * @template T of Type
     */
    public static function resolveType($type): Type
    {
        if ($type instanceof Type) {
            /** @phpstan-var T $type */
            return $type;
        }

        return $type();
    }

    /**
     * Returns all possible concrete types for given abstract type
     * (implementations for interfaces and members of union type for unions)
     *
     * This operation requires full schema scan. Do not use in production environment.
     *
     * @param InterfaceType|UnionType $abstractType
     *
     * @return array<Type&ObjectType>
     *
     * @api
     */
    public function getPossibleTypes(Type $abstractType): array
    {
        return $abstractType instanceof UnionType
            ? $abstractType->getTypes()
            : $this->getImplementations($abstractType)->objects();
    }

    /**
     * Returns all types that implement a given interface type.
     *
     * This operations requires full schema scan. Do not use in production environment.
     *
     * @api
     */
    public function getImplementations(InterfaceType $abstractType): InterfaceImplementations
    {
        return $this->collectImplementations()[$abstractType->name];
    }

    /**
     * @return array<string, InterfaceImplementations>
     */
    private function collectImplementations(): array
    {
        if (! isset($this->implementationsMap)) {
            /** @var array<string, array<string, Type>> $foundImplementations */
            $foundImplementations = [];
            foreach ($this->getTypeMap() as $type) {
                if ($type instanceof InterfaceType) {
                    if (! isset($foundImplementations[$type->name])) {
                        $foundImplementations[$type->name] = ['objects' => [], 'interfaces' => []];
                    }

                    foreach ($type->getInterfaces() as $iface) {
                        if (! isset($foundImplementations[$iface->name])) {
                            $foundImplementations[$iface->name] = ['objects' => [], 'interfaces' => []];
                        }

                        $foundImplementations[$iface->name]['interfaces'][] = $type;
                    }
                } elseif ($type instanceof ObjectType) {
                    foreach ($type->getInterfaces() as $iface) {
                        if (! isset($foundImplementations[$iface->name])) {
                            $foundImplementations[$iface->name] = ['objects' => [], 'interfaces' => []];
                        }

                        $foundImplementations[$iface->name]['objects'][] = $type;
                    }
                }
            }

            foreach ($foundImplementations as $name => $implementations) {
                $this->implementationsMap[$name] = new InterfaceImplementations($implementations['objects'], $implementations['interfaces']);
            }
        }

        return $this->implementationsMap;
    }

    /**
     * Returns true if the given type is a sub type of the given abstract type.
     *
     * @param UnionType|InterfaceType  $abstractType
     * @param ObjectType|InterfaceType $maybeSubType
     *
     * @api
     */
    public function isSubType(AbstractType $abstractType, ImplementingType $maybeSubType): bool
    {
        if ($abstractType instanceof InterfaceType) {
            return $maybeSubType->implementsInterface($abstractType);
        }

        if ($abstractType instanceof UnionType) {
            return $abstractType->isPossibleType($maybeSubType);
        }

        throw new InvalidArgumentException(sprintf('$abstractType must be of type UnionType|InterfaceType got: %s.', get_class($abstractType)));
    }

    /**
     * Returns instance of directive by name
     *
     * @api
     */
    public function getDirective(string $name): ?Directive
    {
        foreach ($this->getDirectives() as $directive) {
            if ($directive->name === $name) {
                return $directive;
            }
        }

        return null;
    }

    public function getAstNode(): ?SchemaDefinitionNode
    {
        return $this->config->getAstNode();
    }

    /**
     * Throws if the schema is not valid.
     *
     * This operation requires a full schema scan. Do not use in production environment.
     *
     * @throws InvariantViolation
     *
     * @api
     */
    public function assertValid(): void
    {
        $errors = $this->validate();

        if ($errors !== []) {
            throw new InvariantViolation(implode("\n\n", $this->validationErrors));
        }

        $internalTypes = Type::getStandardTypes() + Introspection::getTypes();
        foreach ($this->getTypeMap() as $name => $type) {
            if (isset($internalTypes[$name])) {
                continue;
            }

            $type->assertValid();

            // Make sure type loader returns the same instance as registered in other places of schema
            if ($this->config->typeLoader === null) {
                continue;
            }

            Utils::invariant(
                $this->loadType($name) === $type,
                sprintf(
                    'Type loader returns different instance for %s than field/argument definitions. Make sure you always return the same instance for the same type name.',
                    $name
                )
            );
        }
    }

    /**
     * Validate the schema and return any errors.
     *
     * This operation requires a full schema scan. Do not use in production environment.
     *
     * @return array<int, Error>
     *
     * @api
     */
    public function validate(): array
    {
        // If this Schema has already been validated, return the previous results.
        if (isset($this->validationErrors)) {
            return $this->validationErrors;
        }

        // Validate the schema, producing a list of errors.
        $context = new SchemaValidationContext($this);
        $context->validateRootTypes();
        $context->validateDirectives();
        $context->validateTypes();

        // Persist the results of validation before returning to ensure validation
        // does not run multiple times for this schema.
        $this->validationErrors = $context->getErrors();

        return $this->validationErrors;
    }
}
