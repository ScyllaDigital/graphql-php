<?php

declare(strict_types=1);

namespace GraphQL\Tests\Validator;

use GraphQL\Language\SourceLocation;
use GraphQL\Tests\ErrorHelper;
use GraphQL\Utils\BuildSchema;
use GraphQL\Validator\Rules\KnownTypeNames;

class KnownTypeNamesTest extends ValidatorTestCase
{
    // Validate: Known type names

    /**
     * @see it('known type names are valid')
     */
    public function testKnownTypeNamesAreValid(): void
    {
        $this->expectPassesRule(
            new KnownTypeNames(),
            '
      query Foo(
        $var: String
        $required: [Int!]!
        $introspectionType: __EnumValue
      ) {
        user(id: 4) {
          pets { ... on Pet { name }, ...PetFields, ... { name } }
        }
      }
      fragment PetFields on Pet {
        name
      }
        '
        );
    }

    /**
     * @see it('unknown type names are invalid')
     */
    public function testUnknownTypeNamesAreInvalid(): void
    {
        $this->expectFailsRule(
            new KnownTypeNames(),
            '
      query Foo($var: [JumbledUpLetters!]!) {
        user(id: 4) {
          name
          pets { ... on Badger { name }, ...PetFields }
        }
      }
      fragment PetFields on Peat {
        name
      }
        ',
            [
                $this->unknownType('JumbledUpLetters', [], 2, 24),
                $this->unknownType('Badger', [], 5, 25),
                $this->unknownType('Peat', ['Pet', 'Cat'], 8, 29),
            ]
        );
    }

    /**
     * @see it('references to standard scalars that are missing in schema')
     */
    public function testReferencesToStandardScalarsThatAreMissingInSchema(): void
    {
        $schema = BuildSchema::build('type Query { foo: String }');
        $query  = '
      query ($id: ID, $float: Float, $int: Int) {
        __typename
      }
    ';
        $this->expectFailsRuleWithSchema(
            $schema,
            new KnownTypeNames(),
            $query,
            [
                $this->unknownType('Unknown type "ID".', [], 2, 19),
                $this->unknownType('Unknown type "Float".', [], 2, 31),
                $this->unknownType('Unknown type "Int".', [], 2, 44),
            ]
        );
    }

    private function unknownType($typeName, $suggestedTypes, $line, $column)
    {
        return ErrorHelper::create(
            KnownTypeNames::unknownTypeMessage($typeName, $suggestedTypes),
            [new SourceLocation($line, $column)]
        );
    }
}
