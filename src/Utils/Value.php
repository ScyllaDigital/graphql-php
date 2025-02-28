<?php

declare(strict_types=1);

namespace GraphQL\Utils;

use GraphQL\Error\Error;
use GraphQL\Language\AST\Node;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\EnumValueDefinition;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InputType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ScalarType;
use stdClass;
use Throwable;
use Traversable;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Coerces a PHP value given a GraphQL Type.
 *
 * Returns either a value which is valid for the provided type or a list of
 * encountered coercion errors.
 */
class Value
{
    /**
     * Given a type and any value, return a runtime value coerced to match the type.
     *
     * @param ScalarType|EnumType|InputObjectType|ListOfType|NonNull $type
     * @param mixed[]                                                $path
     */
    public static function coerceValue($value, InputType $type, $blameNode = null, ?array $path = null)
    {
        if ($type instanceof NonNull) {
            if ($value === null) {
                return self::ofErrors([
                    self::coercionError(
                        sprintf('Expected non-nullable type %s not to be null', $type),
                        $blameNode,
                        $path
                    ),
                ]);
            }

            return self::coerceValue($value, $type->getWrappedType(), $blameNode, $path);
        }

        if ($value === null) {
            // Explicitly return the value null.
            return self::ofValue(null);
        }

        if ($type instanceof ScalarType) {
            // Scalars determine if a value is valid via parseValue(), which can
            // throw to indicate failure. If it throws, maintain a reference to
            // the original error.
            try {
                return self::ofValue($type->parseValue($value));
            } catch (Throwable $error) {
                return self::ofErrors([
                    self::coercionError(
                        sprintf('Expected type %s', $type->name),
                        $blameNode,
                        $path,
                        $error->getMessage(),
                        $error
                    ),
                ]);
            }
        }

        if ($type instanceof EnumType) {
            if (is_string($value)) {
                $enumValue = $type->getValue($value);
                if ($enumValue !== null) {
                    return self::ofValue($enumValue->value);
                }
            }

            $suggestions = Utils::suggestionList(
                Utils::printSafe($value),
                array_map(
                    static fn (EnumValueDefinition $enumValue): string => $enumValue->name,
                    $type->getValues()
                )
            );

            $didYouMean = $suggestions === []
                ? null
                : 'did you mean ' . Utils::orList($suggestions) . '?';

            return self::ofErrors([
                self::coercionError(
                    sprintf('Expected type %s', $type->name),
                    $blameNode,
                    $path,
                    $didYouMean
                ),
            ]);
        }

        if ($type instanceof ListOfType) {
            $itemType = $type->getWrappedType();
            if (is_array($value) || $value instanceof Traversable) {
                $errors       = [];
                $coercedValue = [];
                foreach ($value as $index => $itemValue) {
                    $coercedItem = self::coerceValue(
                        $itemValue,
                        $itemType,
                        $blameNode,
                        self::atPath($path, $index)
                    );
                    if ($coercedItem['errors']) {
                        $errors = self::add($errors, $coercedItem['errors']);
                    } else {
                        $coercedValue[] = $coercedItem['value'];
                    }
                }

                return $errors === []
                    ? self::ofValue($coercedValue)
                    : self::ofErrors($errors);
            }

            // Lists accept a non-list value as a list of one.
            $coercedItem = self::coerceValue($value, $itemType, $blameNode);

            return $coercedItem['errors']
                ? $coercedItem
                : self::ofValue([$coercedItem['value']]);
        }

        if ($type instanceof InputObjectType) {
            if ($value instanceof stdClass) {
                // Cast objects to associative array before checking the fields.
                // Note that the coerced value will be an array.
                $value = (array) $value;
            } elseif (! is_array($value)) {
                return self::ofErrors([
                    self::coercionError(
                        sprintf('Expected type %s to be an object', $type->name),
                        $blameNode,
                        $path
                    ),
                ]);
            }

            $errors       = [];
            $coercedValue = [];
            $fields       = $type->getFields();
            foreach ($fields as $fieldName => $field) {
                if (array_key_exists($fieldName, $value)) {
                    $fieldValue   = $value[$fieldName];
                    $coercedField = self::coerceValue(
                        $fieldValue,
                        $field->getType(),
                        $blameNode,
                        self::atPath($path, $fieldName)
                    );
                    if ($coercedField['errors']) {
                        $errors = self::add($errors, $coercedField['errors']);
                    } else {
                        $coercedValue[$fieldName] = $coercedField['value'];
                    }
                } elseif ($field->defaultValueExists()) {
                    $coercedValue[$fieldName] = $field->defaultValue;
                } elseif ($field->getType() instanceof NonNull) {
                    $fieldPath = self::printPath(self::atPath($path, $fieldName));
                    $errors    = self::add(
                        $errors,
                        self::coercionError(
                            sprintf(
                                'Field %s of required type %s was not provided',
                                $fieldPath,
                                $field->getType()->toString()
                            ),
                            $blameNode
                        )
                    );
                }
            }

            // Ensure every provided field is defined.
            foreach ($value as $fieldName => $field) {
                if (array_key_exists($fieldName, $fields)) {
                    continue;
                }

                $suggestions = Utils::suggestionList(
                    (string) $fieldName,
                    array_keys($fields)
                );
                $didYouMean  = $suggestions === []
                    ? null
                    : 'did you mean ' . Utils::orList($suggestions) . '?';
                $errors      = self::add(
                    $errors,
                    self::coercionError(
                        sprintf('Field "%s" is not defined by type %s', $fieldName, $type->name),
                        $blameNode,
                        $path,
                        $didYouMean
                    )
                );
            }

            return $errors === []
                ? self::ofValue($coercedValue)
                : self::ofErrors($errors);
        }

        throw new Error(sprintf('Unexpected type %s', $type->name));
    }

    private static function ofErrors($errors)
    {
        return ['errors' => $errors, 'value' => Utils::undefined()];
    }

    /**
     * @param array<mixed>|null $path
     */
    private static function coercionError(
        string $message,
        ?Node $blameNode,
        ?array $path = null,
        ?string $subMessage = null,
        ?Throwable $originalError = null
    ): Error {
        $pathStr = self::printPath($path);

        $fullMessage = $message
            . ($pathStr === ''
                ? ''
                : ' at ' . $pathStr)
            . ($subMessage === null || $subMessage === ''
                ? '.'
                : '; ' . $subMessage);

        return new Error(
            $fullMessage,
            $blameNode,
            null,
            [],
            null,
            $originalError
        );
    }

    /**
     * Build a string describing the path into the value where the error was found
     *
     * @param mixed[]|null $path
     */
    private static function printPath(?array $path = null): string
    {
        $pathStr     = '';
        $currentPath = $path;
        while ($currentPath) {
            $pathStr     = (is_string($currentPath['key'])
                    ? '.' . $currentPath['key']
                    : '[' . $currentPath['key'] . ']')
                . $pathStr;
            $currentPath = $currentPath['prev'];
        }

        return $pathStr === ''
            ? ''
            : 'value' . $pathStr;
    }

    /**
     * @param mixed $value
     *
     * @return (mixed|null)[]
     */
    private static function ofValue($value): array
    {
        return ['errors' => null, 'value' => $value];
    }

    /**
     * @param mixed|null $prev
     * @param mixed|null $key
     *
     * @return (mixed|null)[]
     */
    private static function atPath($prev, $key): array
    {
        return ['prev' => $prev, 'key' => $key];
    }

    /**
     * @param Error[]       $errors
     * @param Error|Error[] $moreErrors
     *
     * @return Error[]
     */
    private static function add($errors, $moreErrors): array
    {
        return array_merge($errors, is_array($moreErrors)
            ? $moreErrors
            : [$moreErrors]);
    }
}
