<?php
declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Http\Requests\Pipeline\StartDirectIngestRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class StartDirectIngestRequestTest extends TestCase
{
    public function test_it_accepts_the_direct_ingest_start_payload_shape(): void
    {
        $request = new StartDirectIngestRequest();

        $validator = Validator::make([
            'path' => '/tmp/crawled',
            'collection' => 'hawki',
            'provider' => 'ollama',
            'embedding_model' => 'nomic-embed-text',
            'graph' => true,
            'graph_engine' => 'neo4j',
            'graph_model' => 'llama3',
            'neo4j_database' => 'neo4j',
            'graph_only' => false,
            'chunk_chars' => 1200,
            'chunk_overlap' => 100,
            'batch' => 10,
            'timeout' => 600,
            'resume_mode' => 'start',
            'job_id' => 'ingest-request-test',
        ], $request->rules());

        $this->assertFalse($validator->fails(), (string) json_encode($validator->errors()->toArray()));
    }

    public function test_it_rejects_missing_path_and_invalid_resume_mode(): void
    {
        $request = new StartDirectIngestRequest();

        $validator = Validator::make([
            'resume_mode' => 'invalid',
        ], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('path', $validator->errors()->toArray());
        $this->assertArrayHasKey('resume_mode', $validator->errors()->toArray());
    }
}
