<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Services\Authorization\Connectors\StaticLmsPermissionConnector;
use App\Services\Authorization\Oidc\OidcJwtValidator;
use App\Services\Authorization\PermissionGraph\PermissionGraphRelationshipFactory;
use App\Services\Authorization\Repositories\AuthorizationIdentityRepository;
use App\Services\Authorization\Values\LmsDocumentRelation;
use App\Services\Authorization\Values\LmsMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthorizationLmsNeutralTest extends TestCase
{
    use RefreshDatabase;

    public function test_oidc_jwt_identity_is_resolved_and_stored_without_lms_coupling(): void
    {
        [$privateKey, $jwk] = $this->rsaKeyPair();
        $claims = [
            'iss' => 'https://keycloak.example.test/realms/rawki',
            'sub' => 'subject-123',
            'aud' => 'rawki',
            'exp' => time() + 3600,
            'email' => 'learner@example.test',
            'preferred_username' => 'learner',
        ];
        $jwt = $this->jwt($claims, $privateKey);

        config()->set('authz.oidc.issuer', $claims['iss']);
        config()->set('authz.oidc.audience', 'rawki');
        config()->set('authz.oidc.jwks_url', 'https://keycloak.example.test/certs');
        config()->set('authz.oidc.provider', 'keycloak');
        Http::fake([
            'https://keycloak.example.test/certs' => Http::response(['keys' => [$jwk]]),
        ]);

        $resolved = app(OidcJwtValidator::class)->validate($jwt);
        $record = app(AuthorizationIdentityRepository::class)->upsertFromResolved($resolved);

        $this->assertSame('keycloak', $record->provider);
        $this->assertSame('subject-123', $record->external_user_id);
        $this->assertSame('learner@example.test', $record->email);
        $this->assertDatabaseHas('users', ['email' => 'learner@example.test']);
    }

    public function test_oidc_jwt_rejects_wrong_issuer(): void
    {
        [$privateKey, $jwk] = $this->rsaKeyPair();
        config()->set('authz.oidc.issuer', 'https://keycloak.example.test/realms/rawki');
        config()->set('authz.oidc.audience', 'rawki');
        config()->set('authz.oidc.jwks_url', 'https://keycloak.example.test/certs');
        Http::fake([
            'https://keycloak.example.test/certs' => Http::response(['keys' => [$jwk]]),
        ]);

        $jwt = $this->jwt([
            'iss' => 'https://other-issuer.example.test/realms/rawki',
            'sub' => 'subject-123',
            'aud' => 'rawki',
            'exp' => time() + 3600,
        ], $privateKey);

        $this->assertNull(app(OidcJwtValidator::class)->validate($jwt));
    }

    public function test_oidc_jwt_rejects_expired_token(): void
    {
        [$privateKey, $jwk] = $this->rsaKeyPair();
        config()->set('authz.oidc.issuer', 'https://keycloak.example.test/realms/rawki');
        config()->set('authz.oidc.audience', 'rawki');
        config()->set('authz.oidc.jwks_url', 'https://keycloak.example.test/certs');
        config()->set('authz.oidc.leeway_seconds', 0);
        Http::fake([
            'https://keycloak.example.test/certs' => Http::response(['keys' => [$jwk]]),
        ]);

        $jwt = $this->jwt([
            'iss' => 'https://keycloak.example.test/realms/rawki',
            'sub' => 'subject-123',
            'aud' => 'rawki',
            'exp' => time() - 1,
        ], $privateKey);

        $this->assertNull(app(OidcJwtValidator::class)->validate($jwt));
    }

    public function test_static_connector_normalizes_memberships_and_document_relations(): void
    {
        config()->set('authz.connectors.static.provider', 'local');
        config()->set('authz.connectors.static.memberships', 'user-1:course-1:instructor user-2:course-2:member');
        config()->set('authz.connectors.static.documents', 'course-1:doc-1 course-2:doc-2');

        $connector = app(StaticLmsPermissionConnector::class);
        $user = $connector->resolveUser('issuer', 'subject', ['preferred_username' => 'user-1']);

        $memberships = iterator_to_array($connector->membershipsForUser($user));
        $relations = iterator_to_array($connector->documentsForCourse('course-1'));

        $this->assertSame('local', $user->provider);
        $this->assertSame('user-1', $memberships[0]->externalUserId);
        $this->assertSame('course-1', $memberships[0]->courseId);
        $this->assertSame('instructor', $memberships[0]->role);
        $this->assertSame('doc-1', $relations[0]->documentId);
    }

    public function test_permission_graph_relationship_generation_is_lms_neutral(): void
    {
        $factory = app(PermissionGraphRelationshipFactory::class);

        $member = $factory->membership(new LmsMembership('local', 'user-1', 'course-1', 'member'));
        $document = $factory->documentRelation(new LmsDocumentRelation('local', 'course-1', 'doc-1'));

        $this->assertSame([
            'resource_type' => 'course',
            'resource_id' => 'local__course-1',
            'relation' => 'member',
            'subject_type' => 'user',
            'subject_id' => 'local__user-1',
            'subject_relation' => null,
        ], $member->toArray());
        $this->assertSame([
            'resource_type' => 'document',
            'resource_id' => 'doc-1',
            'relation' => 'course',
            'subject_type' => 'course',
            'subject_id' => 'local__course-1',
            'subject_relation' => null,
        ], $document->toArray());
    }

    public function test_document_show_is_denied_when_permission_graph_denies_viewer_relation(): void
    {
        $document = $this->documentWithUploadedFile();
        $this->actingAsApiUser();
        $this->denyPermissionGraph();

        $this->getJson('/api/documents/'.$document->id)
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_uploaded_document_download_is_denied_when_permission_graph_denies_access(): void
    {
        $document = $this->documentWithUploadedFile();
        $this->actingAsApiUser();
        $this->denyPermissionGraph();

        $this->getJson('/documents/uploads/download?'.http_build_query([
            'source_url' => $document->source_url,
            'content_hash' => $document->checksum_sha256,
        ]))
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    private function denyPermissionGraph(): void
    {
        config()->set('authz.document_api_enforced', true);
        config()->set('authz.graph.backend', 'spicedb');
        config()->set('authz.graph.spicedb.api_url', 'http://spicedb.test');
        config()->set('authz.graph.spicedb.preshared_key', 'secret-token');
        Http::fake([
            'http://spicedb.test/v1/permissions/checkbulk' => Http::response([
                'pairs' => [['item' => ['permissionship' => 'PERMISSIONSHIP_NO_PERMISSION']]],
            ]),
        ]);
    }

    private function documentWithUploadedFile(): Document
    {
        $root = storage_path('framework/testing/authz-uploads');
        File::ensureDirectoryExists($root.'/task-upload');
        config()->set('temporal.storage.shared_root', $root);
        config()->set('config.pipeline_root', $root);
        config()->set('config.shared_root', $root);
        config()->set('config.crawled_data_root', $root);

        $path = $root.'/task-upload/authz.pdf';
        File::put($path, '%PDF authz');

        return Document::query()->create([
            'dataset_id' => 'authz-dataset',
            'collection' => 'hawki_authz',
            'source_type' => Document::SOURCE_UPLOAD,
            'source_url' => 'upload://authz.pdf',
            'original_filename' => 'authz.pdf',
            'storage_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => filesize($path),
            'checksum_sha256' => hash_file('sha256', $path),
            'status' => Document::STATUS_COMPLETED,
            'metadata_json' => [],
        ]);
    }

    /**
     * @return array{0: \OpenSSLAsymmetricKey, 1: array<string, string>}
     */
    private function rsaKeyPair(): array
    {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        $details = openssl_pkey_get_details($privateKey);
        $rsa = $details['rsa'];

        return [$privateKey, [
            'kty' => 'RSA',
            'kid' => 'test-key',
            'alg' => 'RS256',
            'use' => 'sig',
            'n' => $this->base64Url($rsa['n']),
            'e' => $this->base64Url($rsa['e']),
        ]];
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function jwt(array $claims, \OpenSSLAsymmetricKey $privateKey): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'RS256', 'kid' => 'test-key'];
        $signed = $this->base64Url(json_encode($header)).'.'.$this->base64Url(json_encode($claims));
        openssl_sign($signed, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return $signed.'.'.$this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
