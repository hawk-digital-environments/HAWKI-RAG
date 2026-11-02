<?php

declare(strict_types=1);

namespace Tests\Unit\Rag;

use App\Models\User;
use App\Services\Rag\Clients\PythonQueryClient;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tests\Support\QueryBffFixture;

class PythonQueryClientTest extends TestCase
{
    private QueryBffFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new QueryBffFixture;
        JWT::$timestamp = QueryBffFixture::TIME;
    }

    protected function tearDown(): void
    {
        JWT::$timestamp = null;
        $this->fixture->close();
    }

    public function test_only_public_fields_are_forwarded_with_an_internal_token(): void
    {
        $http = new Factory;
        $http->fake(function (Request $request, array $options) {
            self::assertSame('http://python.test/v1/query', $request->url());
            $claims = JWT::decode(substr($request->header('Authorization')[0], 7), new Key($this->fixture->publicPem, 'RS256'));
            self::assertSame('42', $claims->sub);
            self::assertSame('rag:query', $claims->scope);
            self::assertSame(300, $options['timeout']);
            self::assertSame(3, $options['connect_timeout']);
            self::assertFalse($options['allow_redirects']);
            self::assertFalse($options['cookies']);
            self::assertFalse($request->hasHeader('Cookie'));
            self::assertFalse($request->hasHeader('X-User-Id'));
            self::assertSame([
                'dataset_id' => 'dataset-a', 'query' => 'Question', 'top_k' => 7,
                'fast_mode' => false, 'generate' => true, 'preferred_tags' => ['tag'],
            ], $request->data());

            return Factory::response(self::success());
        });
        $result = $this->client($http)->query($this->user(), [
            'dataset_id' => 'dataset-a', 'query' => 'Question', 'top_k' => '7',
            'fast_mode' => '0', 'generate' => '1', 'preferred_tags' => ['tag'], 'filters' => [],
            'authorized_scope' => ['qdrant_collection' => 'secret'], 'qdrant_collection' => 'secret',
            'neo4j_namespace' => 'secret', 'provider' => 'attacker', 'embedding_provider' => 'attacker',
            'embedding_model' => 'attacker', 'chat_model' => 'attacker', 'vision_model' => 'attacker',
            'graph_model' => 'attacker', 'sub' => 'attacker', 'scope' => '*', 'tenant_id' => 'other',
            'Authorization' => 'Bearer browser-token', 'cookies' => 'browser-cookie',
        ]);
        self::assertSame(200, $result['status']);
        self::assertSame('{}', json_encode($result['payload']->retrieval));
        $http->assertSentCount(1);
    }

    public function test_nonempty_metadata_filters_are_preserved(): void
    {
        $http = new Factory;
        $http->fake(fn (Request $r) => Factory::response(self::success()));
        $this->client($http)->query($this->user(), ['dataset_id' => 'a', 'query' => 'Q', 'filters' => ['language' => 'en']]);
        $http->assertSent(fn (Request $r) => $r->data()['filters'] === ['language' => 'en']);
    }

    public function test_real_query_hit_and_graph_shapes_preserve_empty_json_objects(): void
    {
        $body = self::success();
        $body['count'] = 1;
        $body['hits'] = [['id' => 'hit-1', 'score' => 0.5, 'payload' => new \stdClass]];
        $body['kg'] = [['subject' => 'A', 'predicate' => 'relates', 'object' => 'B']];
        $http = new Factory;
        $http->fake(fn () => Factory::response($body));
        $result = $this->client($http)->query($this->user(), []);
        self::assertSame(200, $result['status']);
        self::assertSame(json_encode($body), json_encode($result['payload']));
    }

    #[DataProvider('safeErrors')]
    public function test_documented_python_errors_are_preserved(int $status, array $body): void
    {
        $http = new Factory;
        $http->fake(fn () => Factory::response($body, $status));
        $result = $this->client($http)->query($this->user(), []);
        self::assertSame($status, $result['status']);
        self::assertSame($body, json_decode(json_encode($result['payload']), true));
        $http->assertSentCount(1);
    }

    public static function safeErrors(): array
    {
        return [
            [401, ['detail' => 'invalid_token']], [403, ['detail' => 'insufficient_scope']],
            [404, ['detail' => ['error' => 'dataset_not_found', 'message' => 'The requested dataset is not available.']]],
            [409, ['detail' => ['error' => 'dataset_not_ready', 'message' => 'The requested dataset is not ready for querying.']]],
            [500, ['detail' => ['error' => 'query_failed', 'message' => 'Query execution failed.']]],
            [502, ['detail' => ['error' => 'answer_generation_failed', 'message' => 'Answer generation failed.']]],
            [503, ['detail' => 'authentication_unavailable']],
            [503, ['detail' => ['error' => 'authorization_unavailable', 'message' => 'Authorization could not be completed.']]],
            [422, ['detail' => [['type' => 'value_error', 'loc' => ['body', 'filters'], 'msg' => 'Invalid filter.', 'input' => []]]]],
        ];
    }

    #[DataProvider('unsafeResponses')]
    public function test_unrecognized_upstream_data_never_reaches_the_browser(int $status, string $body, string $type): void
    {
        $http = new Factory;
        $http->fake(fn () => Factory::response($body, $status, ['Content-Type' => $type]));
        $result = $this->client($http)->query($this->user(), []);
        self::assertSame(502, $result['status']);
        self::assertStringNotContainsString('secret.internal', json_encode($result));
    }

    public static function unsafeResponses(): array
    {
        return [
            [302, '{"detail":"secret.internal"}', 'application/json'],
            [500, '<html>secret.internal stack trace</html>', 'text/html'],
            [200, 'not-json secret.internal', 'application/json'],
            [500, '{"detail":{"error":"query_failed","message":"secret.internal"}}', 'application/json'],
            [500, '{"detail":"secret.internal"}', 'application/json'],
            [200, '{"ok":true,"debug":"secret.internal"}', 'application/json'],
            [200, '{"ok":true,"count":0,"hits":[],"kg":[],"answer":"","retrieval":{"trace":"secret.internal"}}', 'application/json'],
            [422, '{"detail":[{"type":"value_error","loc":1,"msg":"secret.internal"}]}', 'application/json'],
            [200, '{"ok":true,"count":1,"hits":["secret.internal"],"kg":[],"answer":"","retrieval":{}}', 'application/json'],
            [200, '{"ok":true,"count":0,"hits":[],"kg":[{"fact":{"debug":"secret.internal"}}],"answer":"","retrieval":{}}', 'application/json'],
            [200, '{"ok":true,"count":1,"hits":[{"id":"hit","score":1e999,"payload":{}}],"kg":[],"answer":"","retrieval":{}}', 'application/json'],
        ];
    }

    #[DataProvider('connectionFailures')]
    public function test_transport_errors_are_sanitized_and_never_retried(bool $timeout, int $expected): void
    {
        $http = new Factory;
        $calls = 0;
        $http->fake(function (Request $request) use ($timeout, &$calls) {
            $calls++;
            $previous = new ConnectException('Bearer secret.internal', $request->toPsrRequest(), null, ['errno' => $timeout ? 28 : 6]);

            return Create::rejectionFor(new ConnectionException('Bearer secret.internal', 0, $previous));
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('Python query boundary failed.', ['status' => $expected, 'category' => $timeout ? 'timeout' : 'connection']);
        $result = $this->client($http, $logger)->query($this->user(), []);
        self::assertSame($expected, $result['status']);
        self::assertSame(1, $calls);
        self::assertStringNotContainsString('secret.internal', json_encode($result));
    }

    public static function connectionFailures(): array
    {
        return [[false, 502], [true, 504]];
    }

    public function test_signing_failure_never_sends_a_request(): void
    {
        $this->fixture->config->set('config.query_bff.private_key_path', '/missing/private.pem');
        $http = new Factory;
        $http->fake();
        self::assertSame(503, $this->client($http)->query($this->user(), [])['status']);
        $http->assertNothingSent();
    }

    public function test_response_cannot_echo_the_internal_jwt(): void
    {
        $http = new Factory;
        $http->fake(function (Request $request) {
            $body = self::success();
            $body['answer'] = $request->header('Authorization')[0];

            return Factory::response($body);
        });
        self::assertSame(502, $this->client($http)->query($this->user(), [])['status']);
    }

    public function test_json_escaped_internal_token_is_also_refused(): void
    {
        $http = new Factory;
        $http->fake(function (Request $request) {
            $token = substr($request->header('Authorization')[0], 7);
            $body = json_encode(self::success());
            $escaped = implode('', array_map(static fn (string $c): string => sprintf('\\u%04x', ord($c)), str_split($token)));
            $body = str_replace('"Answer"', '"'.$escaped.'"', $body);

            return Factory::response($body, 200, ['Content-Type' => 'application/json']);
        });
        self::assertSame(502, $this->client($http)->query($this->user(), [])['status']);
    }

    #[DataProvider('invalidUrls')]
    public function test_invalid_fixed_endpoint_does_not_receive_a_token(string $url): void
    {
        $this->fixture->config->set('config.python_query_url', $url);
        $http = new Factory;
        $http->fake();
        self::assertSame(503, $this->client($http)->query($this->user(), [])['status']);
        $http->assertNothingSent();
    }

    public static function invalidUrls(): array
    {
        return [['file:///v1/query'], ['http://user:pass@python.test/v1/query'], ['http://python.test/v1/query?token=x'], ['http://python.test/v1/query#fragment'], ['http://python.test/redirect']];
    }

    private function client(Factory $http, ?LoggerInterface $logger = null): PythonQueryClient
    {
        return new PythonQueryClient($this->fixture->config, $http, $this->fixture->signer(), $logger ?? new NullLogger);
    }

    private function user(): User
    {
        $user = new User;
        $user->id = 42;

        return $user;
    }

    public static function success(): array
    {
        return ['ok' => true, 'count' => 0, 'hits' => [], 'kg' => [], 'answer' => 'Answer', 'retrieval' => new \stdClass];
    }
}
