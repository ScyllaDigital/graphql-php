<?php
namespace GraphQL\Type\Definition;

use GraphQL\Error\InvariantViolation;
use GraphQL\Utils\Utils;

/**
 * Class ListOfType
 * @package GraphQL\Type\Definition
 */
class ListOfType extends Type implements WrappingType, OutputType, InputType
{
    /**
     * @var callable|Type
     */
    public $ofType;

    /**
     * @param callable|Type $type
     */
    public function __construct($type)
    {
        if (!$type instanceof Type && !is_callable($type)) {
            throw new InvariantViolation(
                'Can only create List of a GraphQLType but got: ' . Utils::printSafe($type)
            );
        }
        $this->ofType = $type;
    }

    /**
     * @return string
     */
    public function toString()
    {
        $type = $this->ofType;
        $str = $type instanceof Type ? $type->toString() : (string) $type;
        return '[' . $str . ']';
    }

    /**
     * @param bool $recurse
     * @return mixed
     */
    public function getWrappedType($recurse = false)
    {
        $type = $this->ofType;
        return ($recurse && $type instanceof WrappingType) ? $type->getWrappedType($recurse) : $type;
    }
}
