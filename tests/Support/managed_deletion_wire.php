<?php

use App\Services\Pipeline\Clients\PythonTemporalBridgeClient;
use Illuminate\Config\Repository;
use Illuminate\Http\Client\Factory;
use Psr\Log\NullLogger;

// Runs the real Laravel client without booting a database or exposing deployment credentials.
require dirname(__DIR__, 2).'/vendor/autoload.php';

$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$config = new Repository([
    'temporal' => ['enabled' => true, 'callbacks' => ['secret' => 'contract-test-secret']],
    'config' => ['hawki_rag_bridge_url' => 'http://bridge.test'],
]);
$http = new Factory;
$http->fake(function ($request) {
    echo json_encode(['body' => $request->body(), 'headers' => [
        'X-Hawki-Timestamp' => $request->header('X-Hawki-Timestamp')[0],
        'X-Hawki-Signature' => $request->header('X-Hawki-Signature')[0],
    ]], JSON_THROW_ON_ERROR);

    return Factory::response(['workflow_id' => $request->data()['workflow_id'], 'run_id' => 'contract-run', 'status' => 'pending']);
});
if (($argv[1] ?? null) === 'replacement') {
    (new PythonTemporalBridgeClient($config, new NullLogger, $http))
        ->startIngestWorkflow($input, 'ingest-source-'.$input['source_id'], true);
} else {
    (new PythonTemporalBridgeClient($config, new NullLogger, $http))
        ->deleteOutputs('managed-'.$input['operation_id'], $input);
}
