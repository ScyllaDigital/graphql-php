<?php

declare(strict_types=1);

namespace GraphQL\Type;

use GraphQL\Language\AST\SchemaDefinitionNode;
use GraphQL\Language\AST\SchemaTypeExtensionNode;
use GraphQL\Type\Definition\Directive;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

use function count;

/**
 * Configuration options for schema construction.
 *
 * The options accepted by the **create** method are described
 * in the [schema definition docs](schema-definition.md#configuration-options).
 *
 * Usage example:
 *
 *     $config = SchemaConfig::create()
 *         ->setQuery($myQueryType)
 *         ->setTypeLoader($myTypeLoader);
 *
 *     $schema = new Schema($config);
 */
class SchemaConfig
{
    public ?ObjectType $query = null;

    public ?ObjectType $mutation = null;

    public ?ObjectType $subscription = null;

    /** @var array<Type>|(callable(): array<Type>) */
    public $types = [];

    /** @var array<Directive>|null */
    public ?array $directives = null;

    /** @var (callable(string $typeName): Type|(callable(): Type)|null)|null */
    public $typeLoader = null;

    public bool $assumeValid = false;

    public ?SchemaDefinitionNode $astNode = null;

    /** @var array<SchemaTypeExtensionNode> */
    public array $extensionASTNodes = [];

    /**
     * Converts an array of options to instance of SchemaConfig
     * (or just returns empty config when array is not passed).
     *
     * @param array<string, mixed> $options
     *
     * @api
     */
    public static function create(array $options = []): self
    {
        $config = new static();

        if (count($options) > 0) {
            if (isset($options['query'])) {
                $config->setQuery($options['query']);
            }

            if (isset($options['mutation'])) {
                $config->setMutation($options['mutation']);
            }

            if (isset($options['subscription'])) {
                $config->setSubscription($options['subscription']);
            }

            if (isset($options['types'])) {
                $config->setTypes($options['types']);
            }

            if (isset($options['directives'])) {
                $config->setDirectives($options['directives']);
            }

            if (isset($options['typeLoader'])) {
                $config->setTypeLoader($options['typeLoader']);
            }

            if (isset($options['assumeValid'])) {
                $config->setAssumeValid($options['assumeValid']);
            }

            if (isset($options['astNode'])) {
                $config->setAstNode($options['astNode']);
            }

            if (isset($options['extensionASTNodes'])) {
                $config->setExtensionASTNodes($options['extensionASTNodes']);
            }
        }

        return $config;
    }

    /**
     * @api
     */
    public function getQuery(): ?ObjectType
    {
        return $this->query;
    }

    /**
     * @api
     */
    public function setQuery(?ObjectType $query): self
    {
        $this->query = $query;

        return $this;
    }

    /**
     * @api
     */
    public function getMutation(): ?ObjectType
    {
        return $this->mutation;
    }

    /**
     * @api
     */
    public function setMutation(?ObjectType $mutation): self
    {
        $this->mutation = $mutation;

        return $this;
    }

    /**
     * @api
     */
    public function getSubscription(): ?ObjectType
    {
        return $this->subscription;
    }

    /**
     * @api
     */
    public function setSubscription(?ObjectType $subscription): self
    {
        $this->subscription = $subscription;

        return $this;
    }

    /**
     * @return array<Type>|(callable(): array<Type>)
     *
     * @api
     */
    public function getTypes()
    {
        return $this->types;
    }

    /**
     * @param array<Type>|(callable(): array<Type>) $types
     *
     * @api
     */
    public function setTypes($types): self
    {
        $this->types = $types;

        return $this;
    }

    /**
     * @return array<Directive>|null
     *
     * @api
     */
    public function getDirectives(): ?array
    {
        return $this->directives;
    }

    /**
     * @param array<Directive>|null $directives
     *
     * @api
     */
    public function setDirectives(?array $directives): self
    {
        $this->directives = $directives;

        return $this;
    }

    /**
     * @return (callable(string $typeName): Type|(callable(): Type)|null)|null
     *
     * @api
     */
    public function getTypeLoader(): ?callable
    {
        return $this->typeLoader;
    }

    /**
     * @param (callable(string $typeName): Type|(callable(): Type)|null)|null $typeLoader
     *
     * @api
     */
    public function setTypeLoader(?callable $typeLoader): self
    {
        $this->typeLoader = $typeLoader;

        return $this;
    }

    public function getAssumeValid(): bool
    {
        return $this->assumeValid;
    }

    public function setAssumeValid(bool $assumeValid): self
    {
        $this->assumeValid = $assumeValid;

        return $this;
    }

    public function getAstNode(): ?SchemaDefinitionNode
    {
        return $this->astNode;
    }

    public function setAstNode(SchemaDefinitionNode $astNode): self
    {
        $this->astNode = $astNode;

        return $this;
    }

    /**
     * @return array<SchemaTypeExtensionNode>
     */
    public function getExtensionASTNodes(): array
    {
        return $this->extensionASTNodes;
    }

    /**
     * @param array<SchemaTypeExtensionNode> $extensionASTNodes
     */
    public function setExtensionASTNodes(array $extensionASTNodes): self
    {
        $this->extensionASTNodes = $extensionASTNodes;

        return $this;
    }
}
