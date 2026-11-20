<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Services\Authorization\QueryTokenSigner;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Clock\Clock;
use Tests\TestCase;

class CutoverRelayReadinessTest extends TestCase
{
    #[TestWith(['none'])]
    #[TestWith(['text_ingestion'])]
    #[TestWith(['url_ingestion'])]
    #[TestWith(['managed_document'])]
    #[TestWith(['bff'])]
    #[TestWith(['wrong_path'])]
    public function test_effective_relay_failures_prevent_readiness(string $fault): void
    {
        config()->set('temporal.control_url', 'http://owner.test');
        config()->set('config.python_text_ingestion_events_url', 'http://owner.test/internal/text-ingestion/events');
        config()->set('config.python_url_ingestion_url', 'http://owner.test');
        config()->set('config.python_managed_document_url', 'http://owner.test');
        config()->set('config.python_query_url', 'http://owner.test/v1/query');
        config()->set('config.query_bff.tenant', 'default');
        config()->set('config.query_bff.client_id', 'hawki-bff');
        if ($fault === 'wrong_path') {
            config()->set('config.python_url_ingestion_url', 'http://owner.test/wrong');
        }
        $signer = new RelayTestSigner(config(), new Clock);
        $this->app->instance(QueryTokenSigner::class, $signer);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($fault) {
            if (str_ends_with($request->url(), '/v1/readiness/identity')) {
                return Http::response(['tenant_id' => $fault === 'bff' ? 'foreign' : 'default', 'client_id' => 'hawki-bff']);
            }
            $this->assertStringEndsWith('/internal/workflow-control/relay-readiness', $request->url());
            $this->assertTrue($request->hasHeader('X-Hawki-Signature'));
            $family = $request['family'];

            return Http::response(['ready' => true, 'family' => $family], $family === $fault ? 503 : 200);
        });
        $this->artisan('rag:cutover-relay-readiness')->assertExitCode($fault === 'none' ? 0 : 1);
    }
}

readonly class RelayTestSigner extends QueryTokenSigner
{
    public function signDatasetAdmin(): string
    {
        return 'fixture-token';
    }
}
