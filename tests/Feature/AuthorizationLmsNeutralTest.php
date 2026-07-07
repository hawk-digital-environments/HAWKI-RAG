<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Document;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Authorization\IdentityProvisioningService;
use App\Services\Authorization\Connectors\StaticLmsPermissionConnector;
use App\Services\Authorization\Oidc\OidcJwtValidator;
use App\Services\Authorization\Oidc\OidcUserResolver;
use App\Services\Authorization\PermissionGraph\PermissionGraphRelationshipFactory;
use App\Services\Authorization\Values\LmsDocumentRelation;
use App\Services\Authorization\Values\LmsMembership;
use App\Services\Authorization\Values\ResolvedUserIdentity;
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
            'tenant_id' => 'uni-hawk',
            'application_id' => 'hawki-web',
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
        $record = app(IdentityProvisioningService::class)->upsertResolvedIdentity($resolved);

        $this->assertSame('keycloak', $record->provider);
        $this->assertSame('subject-123', $record->external_user_id);
        $this->assertSame('learner@example.test', $record->email);
        $this->assertSame('uni-hawk', $record->tenant_id);
        $this->assertSame('hawki-web', $record->application_id);
        $this->assertNotNull($record->internal_user_id);
        $this->assertNull($record->user_id);
        $this->assertDatabaseMissing('users', ['email' => 'learner@example.test']);
        $this->assertDatabaseHas('applications', ['id' => 'hawki-web', 'tenant_id' => 'uni-hawk']);
        $this->assertDatabaseHas('internal_users', ['id' => $record->internal_user_id, 'tenant_id' => 'uni-hawk']);
    }

    public function test_group_member_identity_mapping_does_not_merge_later_oidc_identity_by_email_fallback(): void
    {
        $assignments = app(IdentityProvisioningService::class)->groupMemberAssignments(
            'uni-hawk',
            'hawki-web',
            ['alice@hawk.de'],
        );

        $record = app(IdentityProvisioningService::class)->upsertResolvedIdentity(
            new ResolvedUserIdentity(
                issuer: 'https://keycloak.example.test/realms/rawki',
                subject: 'subject-999',
                provider: 'keycloak',
                externalUserId: 'subject-999',
                email: 'alice@hawk.de',
                username: 'alice',
                claims: [
                    'tenant_id' => 'uni-hawk',
                    'application_id' => 'hawki-web',
                ],
            ),
        );

        $this->assertNotSame($assignments[0]->internalUserId, $record->internal_user_id);
        $this->assertSame('uni-hawk', $record->tenant_id);
        $this->assertSame('hawki-web', $record->application_id);
        $this->assertDatabaseHas('user_identities', [
            'tenant_id' => 'uni-hawk',
            'provider' => UserIdentity::PROVIDER_TENANT_IDENTITY,
            'external_user_id' => 'alice@hawk.de',
            'internal_user_id' => $assignments[0]->internalUserId,
        ]);
        $this->assertDatabaseHas('user_identities', [
            'tenant_id' => 'uni-hawk',
            'provider' => 'keycloak',
            'external_user_id' => 'subject-999',
            'internal_user_id' => $record->internal_user_id,
        ]);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_duplicate_external_identifier_values_across_tenants_create_separate_internal_users(): void
    {
        $tenantA = app(IdentityProvisioningService::class)->userAssignments(
            'tenant-a',
            'app-a',
            ['shared-user'],
        );
        $tenantB = app(IdentityProvisioningService::class)->userAssignments(
            'tenant-b',
            'app-b',
            ['shared-user'],
        );

        $this->assertNotSame($tenantA[0]->internalUserId, $tenantB[0]->internalUserId);
        $this->assertDatabaseHas('user_identities', [
            'tenant_id' => 'tenant-a',
            'provider' => UserIdentity::PROVIDER_TENANT_IDENTITY,
            'external_user_id' => 'shared-user',
            'internal_user_id' => $tenantA[0]->internalUserId,
        ]);
        $this->assertDatabaseHas('user_identities', [
            'tenant_id' => 'tenant-b',
            'provider' => UserIdentity::PROVIDER_TENANT_IDENTITY,
            'external_user_id' => 'shared-user',
            'internal_user_id' => $tenantB[0]->internalUserId,
        ]);
    }

    public function test_duplicate_external_identifier_values_across_providers_within_one_tenant_remain_separate(): void
    {
        $moodle = app(IdentityProvisioningService::class)->connectorMemberAssignments(
            'uni-hawk',
            'hawki-web',
            'moodle',
            ['shared-user'],
        );
        $studip = app(IdentityProvisioningService::class)->connectorMemberAssignments(
            'uni-hawk',
            'hawki-web',
            'studip',
            ['shared-user'],
        );

        $this->assertNotSame($moodle[0]->internalUserId, $studip[0]->internalUserId);
        $this->assertDatabaseHas('user_identities', [
            'tenant_id' => 'uni-hawk',
            'provider' => 'moodle',
            'external_user_id' => 'shared-user',
            'internal_user_id' => $moodle[0]->internalUserId,
        ]);
        $this->assertDatabaseHas('user_identities', [
            'tenant_id' => 'uni-hawk',
            'provider' => 'studip',
            'external_user_id' => 'shared-user',
            'internal_user_id' => $studip[0]->internalUserId,
        ]);
    }

    public function test_oidc_request_resolution_creates_human_user_and_links_identity(): void
    {
        [$privateKey, $jwk] = $this->rsaKeyPair();
        $claims = [
            'iss' => 'https://keycloak.example.test/realms/rawki',
            'sub' => 'subject-123',
            'aud' => 'rawki',
            'exp' => time() + 3600,
            'tenant_id' => 'uni-hawk',
            'application_id' => 'hawki-web',
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

        $request = request()->create('/api/search', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$jwt,
        ]);

        $user = app(OidcUserResolver::class)->userFromRequest($request);

        $this->assertInstanceOf(User::class, $user);
        $this->assertDatabaseHas('users', ['email' => 'learner@example.test']);
        $this->assertDatabaseHas('user_identities', [
            'user_id' => $user->id,
            'issuer' => $claims['iss'],
            'subject' => 'subject-123',
        ]);
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

    public function test_canonical_document_show_route_is_available_on_v2_app_surface(): void
    {
        $document = $this->documentWithUploadedFile(false);
        $this->actingAsApplication([
            'id' => 'rawki-default',
            'tenant_id' => 'default',
            'permissions' => ['reads'],
        ]);
        config()->set('authz.enabled', true);

        $this->getJson('/api/documents/'.$document->id)
            ->assertOk()
            ->assertJsonPath('document_id', $document->id);
    }

    public function test_document_show_route_remains_available_when_authorization_is_disabled(): void
    {
        $document = $this->documentWithUploadedFile(false);
        $this->actingAsApplication([
            'id' => 'rawki-default',
            'tenant_id' => 'default',
            'permissions' => ['reads'],
        ]);
        config()->set('authz.enabled', false);
        config()->set('authz.document_api_enforced', true);

        $this->getJson('/api/documents/'.$document->id)
            ->assertOk()
            ->assertJsonPath('document_id', $document->id);
    }

    public function test_legacy_uploaded_document_download_route_is_not_registered(): void
    {
        $document = $this->documentWithUploadedFile();
        $this->actingAsApiUser();
        $this->denyPermissionGraph();

        $this->getJson('/documents/uploads/download?'.http_build_query([
            'source_url' => $document->source_url,
            'content_hash' => $document->checksum_sha256,
        ]))
            ->assertNotFound();
    }

    private function denyPermissionGraph(): void
    {
        config()->set('authz.enabled', true);
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

    private function documentWithUploadedFile(bool $protected = true): Document
    {
        $root = storage_path('framework/testing/authz-uploads');
        File::ensureDirectoryExists($root.'/task-upload');
        config()->set('temporal.storage.shared_root', $root);
        config()->set('config.pipeline_root', $root);
        config()->set('config.shared_root', $root);
        config()->set('config.crawled_data_root', $root);

        $path = $root.'/task-upload/authz.pdf';
        File::put($path, '%PDF authz');

        Dataset::query()->create([
            'dataset_id' => 'authz-dataset',
            'tenant_id' => 'default',
            'owner_application_id' => 'rawki-default',
            'name' => 'Authz Dataset',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => $protected,
            'metadata_json' => [],
            'qdrant_collection' => 'hawki_authz',
            'neo4j_namespace' => 'hawki_authz',
        ]);

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
