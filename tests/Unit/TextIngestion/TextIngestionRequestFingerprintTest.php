<?php

declare(strict_types=1);

namespace Tests\Unit\TextIngestion;

use App\Services\TextIngestion\TextIngestionRequestFingerprint;
use App\Services\TextIngestion\Values\TextIngestionInput;
use PHPUnit\Framework\TestCase;

final class TextIngestionRequestFingerprintTest extends TestCase
{
    private TextIngestionRequestFingerprint $fingerprints;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fingerprints = new TextIngestionRequestFingerprint;
    }

    public function test_object_key_order_is_ignored_recursively(): void
    {
        $first = $this->input([
            'metadata' => [
                'z' => ['b' => 2, 'a' => 1],
                'a' => ['value' => 'Grüße', 'nested' => ['y' => true, 'x' => null]],
            ],
        ]);
        $second = $this->input([
            'metadata' => [
                'a' => ['nested' => ['x' => null, 'y' => true], 'value' => 'Grüße'],
                'z' => ['a' => 1, 'b' => 2],
            ],
        ]);

        self::assertSame(
            $this->fingerprints->forInput($first),
            $this->fingerprints->forInput($second),
        );
    }

    public function test_list_order_remains_significant(): void
    {
        self::assertNotSame(
            $this->fingerprints->forInput($this->input([
                'metadata' => ['items' => ['a', 'b']],
            ])),
            $this->fingerprints->forInput($this->input([
                'metadata' => ['items' => ['b', 'a']],
            ])),
        );
    }

    public function test_changed_scalar_value_and_type_remain_significant(): void
    {
        $integer = $this->fingerprints->forInput($this->input([
            'metadata' => ['value' => 1],
        ]));

        self::assertNotSame(
            $integer,
            $this->fingerprints->forInput($this->input([
                'metadata' => ['value' => 2],
            ])),
        );
        self::assertNotSame(
            $integer,
            $this->fingerprints->forInput($this->input([
                'metadata' => ['value' => '1'],
            ])),
        );
        self::assertNotSame(
            $integer,
            $this->fingerprints->forInput($this->input([
                'metadata' => ['value' => true],
            ])),
        );
    }

    public function test_null_is_not_dropped_from_metadata(): void
    {
        self::assertNotSame(
            $this->fingerprints->forInput($this->input([
                'metadata' => ['value' => null],
            ])),
            $this->fingerprints->forInput($this->input([
                'metadata' => [],
            ])),
        );
    }

    public function test_unicode_content_is_not_normalized(): void
    {
        self::assertNotSame(
            $this->fingerprints->forInput($this->input([
                'metadata' => ['value' => 'é'],
            ])),
            $this->fingerprints->forInput($this->input([
                'metadata' => ['value' => "e\u{0301}"],
            ])),
        );
    }

    public function test_each_known_top_level_value_remains_significant(): void
    {
        $original = $this->input();
        $originalHash = $this->fingerprints->forInput($original);
        $changes = [
            ['external_document_id' => 'document-456'],
            ['dataset_id' => 'assistant_84'],
            ['text' => 'Changed text.'],
            ['content_format' => 'plain_text'],
            ['display_name' => 'Changed name'],
            ['source_url' => 'https://hawki.example/document-456'],
        ];

        foreach ($changes as $change) {
            self::assertNotSame(
                $originalHash,
                $this->fingerprints->forInput($this->input($change)),
            );
        }
    }

    public function test_versionless_legacy_hash_accepts_semantically_identical_reordered_metadata(): void
    {
        $original = $this->input([
            'metadata' => ['a' => 1, 'b' => ['x' => true, 'y' => false]],
        ]);
        $reordered = $this->input([
            'metadata' => ['b' => ['y' => false, 'x' => true], 'a' => 1],
        ]);
        $request = [...$original->toArray(), 'text' => null];
        $taskMetadata = [
            'request_hash' => hash(
                'sha256',
                json_encode($original->toArray(), JSON_THROW_ON_ERROR),
            ),
            'request' => $request,
        ];

        self::assertTrue($this->fingerprints->matchesPersisted($reordered, $taskMetadata));
        self::assertFalse($this->fingerprints->matchesPersisted(
            $this->input(['metadata' => ['a' => 2, 'b' => ['x' => true, 'y' => false]]]),
            $taskMetadata,
        ));
    }

    public function test_current_and_unknown_fingerprint_versions_are_handled_explicitly(): void
    {
        $input = $this->input();
        $metadata = [
            'request_hash' => $this->fingerprints->forInput($input),
            'request_hash_version' => TextIngestionRequestFingerprint::VERSION,
        ];

        self::assertTrue($this->fingerprints->matchesPersisted($input, $metadata));

        $metadata['request_hash_version'] = 999;
        self::assertFalse($this->fingerprints->matchesPersisted($input, $metadata));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function input(array $overrides = []): TextIngestionInput
    {
        return TextIngestionInput::fromValidated(array_replace([
            'external_document_id' => 'document-123',
            'dataset_id' => 'assistant_42',
            'text' => "# Example\n\nDocument content.",
            'content_format' => 'markdown',
            'display_name' => 'API document',
            'source_url' => 'https://hawki.example/document-123',
            'metadata' => ['assistant_id' => 'assistant-42'],
        ], $overrides));
    }
}
