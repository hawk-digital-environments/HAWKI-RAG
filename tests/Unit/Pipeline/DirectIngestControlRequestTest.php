<?php
declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Http\Requests\Pipeline\ClearDirectIngestStatusRequest;
use App\Http\Requests\Pipeline\DeleteCrawledFolderRequest;
use App\Http\Requests\Pipeline\ListDirectIngestLiveRequest;
use App\Http\Requests\Pipeline\ShowDirectIngestStatusRequest;
use App\Http\Requests\Pipeline\StopDirectIngestRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class DirectIngestControlRequestTest extends TestCase
{
    public function test_stop_request_accepts_pid_list_and_mode(): void
    {
        $request = new StopDirectIngestRequest();

        $validator = Validator::make([
            'pids' => [123, 456],
            'mode' => 'neo4j',
        ], $request->rules());

        $this->assertFalse($validator->fails(), (string) json_encode($validator->errors()->toArray()));
    }

    public function test_stop_request_rejects_invalid_pid_and_mode(): void
    {
        $request = new StopDirectIngestRequest();

        $validator = Validator::make([
            'pid' => 0,
            'mode' => 'other',
        ], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('pid', $validator->errors()->toArray());
        $this->assertArrayHasKey('mode', $validator->errors()->toArray());
    }

    public function test_delete_folder_request_requires_path(): void
    {
        $request = new DeleteCrawledFolderRequest();

        $validator = Validator::make([], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('path', $validator->errors()->toArray());
    }

    public function test_live_request_accepts_mode_as_a_string(): void
    {
        $request = new ListDirectIngestLiveRequest();

        $validator = Validator::make(['mode' => 'default'], $request->rules());

        $this->assertFalse($validator->fails(), (string) json_encode($validator->errors()->toArray()));
    }

    public function test_status_requests_accept_mode_as_a_string(): void
    {
        $showRequest = new ShowDirectIngestStatusRequest();
        $clearRequest = new ClearDirectIngestStatusRequest();

        $showValidator = Validator::make(['mode' => 'neo4j'], $showRequest->rules());
        $clearValidator = Validator::make(['mode' => 'both'], $clearRequest->rules());

        $this->assertFalse($showValidator->fails(), (string) json_encode($showValidator->errors()->toArray()));
        $this->assertFalse($clearValidator->fails(), (string) json_encode($clearValidator->errors()->toArray()));
    }
}
