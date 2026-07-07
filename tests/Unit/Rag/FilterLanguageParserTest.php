<?php
declare(strict_types=1);

namespace Tests\Unit\Rag;

use App\Services\Rag\CanonicalFilterExpressionSerializer;
use App\Services\Rag\FilterLanguageParser;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FilterLanguageParserTest extends TestCase
{
    public function test_it_parses_spec_leaf_expression(): void
    {
        $this->assertSame(
            ['course', 'design'],
            $this->serialize(['course', 'design']),
        );
    }

    public function test_it_parses_boolean_expressions_from_spec_grammar(): void
    {
        $this->assertSame(
            [
                'AND',
                [
                    ['heap', 'heap-design'],
                    [
                        'OR',
                        [
                            ['visibility', 'hidden'],
                            ['protected', false],
                        ],
                    ],
                    ['NOT', ['document_id', 'doc-deleted']],
                ],
            ],
            $this->serialize([
                'AND',
                [
                    ['heap', 'heap-design'],
                    [
                        'OR',
                        [
                            ['visibility', 'hidden'],
                            ['protected', false],
                        ],
                    ],
                    ['NOT', ['document_id', 'doc-deleted']],
                ],
            ]),
        );
    }

    public function test_it_treats_root_siblings_as_implicit_and(): void
    {
        $this->assertSame(
            [
                'AND',
                [
                    ['heap', 'heap-design'],
                    ['course', 'architecture'],
                ],
            ],
            $this->serialize([
                ['heap', 'heap-design'],
                ['course', 'architecture'],
            ]),
        );
    }

    public function test_it_rejects_object_style_and_qdrant_shaped_filters(): void
    {
        $this->assertInvalid(['visibility' => 'discoverable']);
        $this->assertInvalid([
            'must' => [
                ['key' => 'visibility', 'match' => ['value' => 'discoverable']],
            ],
        ]);
    }

    public function test_it_rejects_invalid_arrays_reserved_metadata_fields_and_empty_values(): void
    {
        $this->assertInvalid(['visibility']);
        $this->assertInvalid(['AND' => []]);
        $this->assertInvalid(['course', '']);
        $this->assertInvalid(['course', []]);
        $this->assertInvalid(['course', ['design', '']]);
        $this->assertInvalid(['AND', 'design']);
        $this->assertInvalid(['AND' => [['course', 'design']]]);
        $this->assertInvalid(['__rawki', 'forged']);
    }

    public function test_not_requires_one_child_expression(): void
    {
        $this->assertSame(
            ['NOT', ['protected', true]],
            $this->serialize(['NOT', ['protected', true]]),
        );

        $this->assertInvalid([
            'NOT',
            [
                ['protected', true],
                ['visibility', 'hidden'],
            ],
        ]);
    }

    /**
     * @param array<mixed> $input
     * @return array<mixed>
     */
    private function serialize(array $input): array
    {
        return app(CanonicalFilterExpressionSerializer::class)->serialize(
            app(FilterLanguageParser::class)->parse($input),
        );
    }

    /**
     * @param array<mixed> $input
     */
    private function assertInvalid(array $input): void
    {
        try {
            app(FilterLanguageParser::class)->parse($input);
            $this->fail('Expected filter validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('filters', $exception->errors());
        }
    }
}
