<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Services\Authorization\Connectors\StaticLmsPermissionConnector;
use App\Services\Authorization\Connectors\StudIpLmsPermissionConnector;
use App\Services\Authorization\Connectors\UnsupportedLmsPermissionConnector;
use App\Services\Authorization\LmsPermissionConnectorRegistry;
use Tests\TestCase;

class LmsConnectorRegistryTest extends TestCase
{
    public function test_default_connector_comes_from_configuration_without_lms_coupling(): void
    {
        config()->set('authz.connectors.default', 'static');

        $this->assertInstanceOf(StaticLmsPermissionConnector::class, app(LmsPermissionConnectorRegistry::class)->default());
    }

    public function test_studip_is_registered_as_optional_connector_scaffold(): void
    {
        $connector = app(LmsPermissionConnectorRegistry::class)->forProvider('studip');
        $identity = $connector->resolveUser('issuer', 'subject-1', [
            'studip_user_id' => 'studip-user-1',
            'preferred_username' => 'learner',
            'email' => 'learner@example.test',
        ]);

        $this->assertInstanceOf(StudIpLmsPermissionConnector::class, $connector);
        $this->assertSame('studip', $identity->provider);
        $this->assertSame('studip-user-1', $identity->externalUserId);
        $this->assertSame([], iterator_to_array($connector->membershipsForUser($identity)));
        $this->assertSame([], iterator_to_array($connector->documentsForCourse('course-1')));
    }

    public function test_future_lms_connectors_are_explicit_unsupported_placeholders(): void
    {
        $registry = app(LmsPermissionConnectorRegistry::class);

        foreach (['moodle', 'ilias', 'canvas'] as $provider) {
            $connector = $registry->forProvider($provider);
            $identity = $connector->resolveUser('issuer', 'subject-1', []);

            $this->assertInstanceOf(UnsupportedLmsPermissionConnector::class, $connector);
            $this->assertSame($provider, $connector->providerId());
            $this->assertSame($provider, $identity->provider);
            $this->assertSame('subject-1', $identity->externalUserId);
            $this->assertSame([], iterator_to_array($connector->membershipsForUser($identity)));
            $this->assertSame([], iterator_to_array($connector->documentsForCourse('course-1')));
        }
    }
}
