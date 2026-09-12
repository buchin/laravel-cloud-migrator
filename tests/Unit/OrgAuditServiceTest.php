<?php

use App\Data\OrgAuditResult;
use App\Services\CloudApiClient;
use App\Services\HealthService;
use App\Services\MigrationManifest;
use App\Services\OrgAuditService;

describe('OrgAuditService Unit', function () {
    test('auditHealth checks all environments across applications', function () {
        $mockClient = Mockery::mock(CloudApiClient::class);
        $mockClient->shouldReceive('getAll')
            ->with('applications')
            ->once()
            ->andReturn([
                ['id' => 'app-1', 'attributes' => ['name' => 'Dojo', 'slug' => 'dojo']],
                ['id' => 'app-2', 'attributes' => ['name' => 'Dracin', 'slug' => 'dracin']],
            ]);

        $mockClient->shouldReceive('getAll')
            ->with('applications/app-1/environments')
            ->once()
            ->andReturn([
                ['id' => 'env-1', 'attributes' => ['name' => 'production', 'vanity_domain' => 'dojo.laravel.cloud']],
            ]);

        $mockClient->shouldReceive('getAll')
            ->with('applications/app-2/environments')
            ->once()
            ->andReturn([
                ['id' => 'env-2', 'attributes' => ['name' => 'main', 'vanity_domain' => 'dracin.laravel.cloud']],
            ]);

        $healthService = new HealthService(prober: function (string $url) {
            return [200, $url, 25];
        });

        $service = new OrgAuditService(
            targetClient: $mockClient,
            healthService: $healthService,
        );

        $results = $service->auditHealth();

        expect($results)->toHaveCount(2);
        expect($results[0]->appName)->toBe('Dojo');
        expect($results[0]->statusCode)->toBe(200);
        expect($results[0]->isHealthy())->toBeTrue();
        expect($results[1]->appName)->toBe('Dracin');
        expect($results[1]->statusCode)->toBe(200);
        expect($results[1]->isHealthy())->toBeTrue();
    });

    test('auditDatabase performs parity check between source and target clusters', function () {
        $targetClient = Mockery::mock(CloudApiClient::class);
        $sourceClient = Mockery::mock(CloudApiClient::class);

        $targetClient->shouldReceive('getAll')
            ->with('databases/clusters')
            ->andReturn([
                [
                    'id' => 'cluster-tgt-1',
                    'attributes' => [
                        'name' => 'dojo',
                        'connection' => ['hostname' => 'tgt.db', 'port' => 3306],
                    ],
                ],
            ]);

        $targetClient->shouldReceive('getAll')
            ->with('databases/clusters/cluster-tgt-1/databases')
            ->andReturn([
                ['id' => 'db-tgt-1', 'attributes' => ['name' => 'main']],
            ]);

        $sourceClient->shouldReceive('getAll')
            ->with('databases/clusters')
            ->andReturn([
                [
                    'id' => 'cluster-src-1',
                    'attributes' => [
                        'name' => 'dojo',
                        'connection' => ['hostname' => 'src.db', 'port' => 3306],
                    ],
                ],
            ]);

        $sourceClient->shouldReceive('getAll')
            ->with('databases/clusters/cluster-src-1/databases')
            ->andReturn([
                ['id' => 'db-src-1', 'attributes' => ['name' => 'main']],
            ]);

        $manifest = MigrationManifest::fromArray([
            'databases' => [
                'dojo.main' => [
                    'tables' => [
                        'sessions' => 'transient',
                        'telescope_entries' => 'schema_only',
                        'legacy_logs' => 'ignore',
                    ],
                ],
            ],
        ]);

        $dbMock = function ($conn, $schema, $query) {
            if ($query === 'tables') {
                return ['users', 'sessions', 'telescope_entries', 'legacy_logs', 'orders'];
            }
            if (str_starts_with($query, 'count:')) {
                $table = substr($query, 6);
                $isTarget = ($conn['hostname'] ?? '') === 'tgt.db';

                return match ($table) {
                    'users' => 100,
                    'sessions' => $isTarget ? 5 : 50,
                    'telescope_entries' => $isTarget ? 0 : 500,
                    'legacy_logs' => $isTarget ? 0 : 200,
                    'orders' => $isTarget ? 0 : 50, // Drift: target empty
                    default => 0,
                };
            }

            return [];
        };

        $service = new OrgAuditService(
            targetClient: $targetClient,
            sourceClient: $sourceClient,
            manifest: $manifest,
            dbQueryExecutor: $dbMock,
        );

        $results = $service->auditDatabase();

        expect($results)->toHaveCount(5);

        $byTable = [];
        foreach ($results as $item) {
            $byTable[$item->table] = $item;
        }

        expect($byTable['users']->status)->toBe('matched');
        expect($byTable['users']->isMatched())->toBeTrue();

        expect($byTable['sessions']->status)->toBe('transient');
        expect($byTable['sessions']->isMatched())->toBeTrue();

        expect($byTable['telescope_entries']->status)->toBe('schema_only');
        expect($byTable['telescope_entries']->isMatched())->toBeTrue();

        expect($byTable['legacy_logs']->status)->toBe('ignored');
        expect($byTable['legacy_logs']->isMatched())->toBeTrue();

        expect($byTable['orders']->status)->toBe('drift');
        expect($byTable['orders']->isDrift())->toBeTrue();
        expect($byTable['orders']->message)->toContain('Target table is empty');
    });

    test('auditDatabase detects missing table in target database as drift', function () {
        $targetClient = Mockery::mock(CloudApiClient::class);
        $sourceClient = Mockery::mock(CloudApiClient::class);

        $targetClient->shouldReceive('getAll')
            ->with('databases/clusters')
            ->andReturn([
                ['id' => 'c1', 'attributes' => ['name' => 'dojo', 'connection' => ['hostname' => 'tgt']]],
            ]);
        $targetClient->shouldReceive('getAll')
            ->with('databases/clusters/c1/databases')
            ->andReturn([
                ['id' => 'd1', 'attributes' => ['name' => 'main']],
            ]);

        $sourceClient->shouldReceive('getAll')
            ->with('databases/clusters')
            ->andReturn([
                ['id' => 's1', 'attributes' => ['name' => 'dojo', 'connection' => ['hostname' => 'src']]],
            ]);
        $sourceClient->shouldReceive('getAll')
            ->with('databases/clusters/s1/databases')
            ->andReturn([
                ['id' => 'sd1', 'attributes' => ['name' => 'main']],
            ]);

        $dbMock = function ($conn, $schema, $query) {
            if ($query === 'tables') {
                return ($conn['hostname'] === 'tgt') ? ['users'] : ['users', 'missing_tbl'];
            }
            if (str_starts_with($query, 'count:')) {
                return 10;
            }

            return [];
        };

        $service = new OrgAuditService(
            targetClient: $targetClient,
            sourceClient: $sourceClient,
            dbQueryExecutor: $dbMock,
        );

        $results = $service->auditDatabase();

        $missingItem = collect($results)->firstWhere('table', 'missing_tbl');
        expect($missingItem)->not->toBeNull();
        expect($missingItem->status)->toBe('drift');
        expect($missingItem->message)->toContain('missing in target');
    });

    test('auditConfig validates manifest-declared env_resolvers against target environments', function () {
        $targetClient = Mockery::mock(CloudApiClient::class);

        $targetClient->shouldReceive('getAll')
            ->with('applications')
            ->andReturn([
                [
                    'id' => 'app-api',
                    'attributes' => ['name' => 'dracin-api', 'slug' => 'dracin-api'],
                ],
                [
                    'id' => 'app-web',
                    'attributes' => ['name' => 'dracin', 'slug' => 'dracin'],
                ],
            ]);

        $targetClient->shouldReceive('getAll')
            ->with('applications/app-api/environments')
            ->andReturn([
                [
                    'id' => 'env-api-1',
                    'attributes' => [
                        'name' => 'main',
                        'slug' => 'main',
                        'vanity_domain' => 'dracin-api.laravel.cloud',
                        'environment_variables' => [],
                    ],
                ],
            ]);

        $targetClient->shouldReceive('getAll')
            ->with('applications/app-web/environments')
            ->andReturn([
                [
                    'id' => 'env-web-1',
                    'attributes' => [
                        'name' => 'main',
                        'slug' => 'main',
                        'vanity_domain' => 'dracin.laravel.cloud',
                        'environment_variables' => [
                            ['key' => 'API_BASE_URL', 'value' => 'https://dracin-api.laravel.cloud/api'],
                            ['key' => 'TARGET_VANITY_DOMAIN', 'value' => 'dracin-api.laravel.cloud'],
                        ],
                    ],
                ],
            ]);

        $manifest = MigrationManifest::fromArray([
            'env_resolvers' => [
                'dracin' => [
                    'API_BASE_URL' => '{{apps.dracin-api.envs.main.url}}/api',
                    'TARGET_VANITY_DOMAIN' => '{{apps.dracin-api.envs.main.vanity_domain}}',
                ],
            ],
        ]);

        $service = new OrgAuditService(
            targetClient: $targetClient,
            manifest: $manifest,
        );

        $results = $service->auditConfig();

        expect($results)->toHaveCount(2);
        expect($results[0]->variableKey)->toBe('API_BASE_URL');
        expect($results[0]->expectedValue)->toBe('https://dracin-api.laravel.cloud/api');
        expect($results[0]->actualValue)->toBe('https://dracin-api.laravel.cloud/api');
        expect($results[0]->status)->toBe('matched');
        expect($results[0]->isMatched())->toBeTrue();

        expect($results[1]->variableKey)->toBe('TARGET_VANITY_DOMAIN');
        expect($results[1]->expectedValue)->toBe('dracin-api.laravel.cloud');
        expect($results[1]->actualValue)->toBe('dracin-api.laravel.cloud');
        expect($results[1]->status)->toBe('matched');
    });

    test('auditConfig flags configuration drift and unresolved placeholders', function () {
        $targetClient = Mockery::mock(CloudApiClient::class);

        $targetClient->shouldReceive('getAll')
            ->with('applications')
            ->andReturn([
                [
                    'id' => 'app-1',
                    'attributes' => ['name' => 'dracin', 'slug' => 'dracin'],
                ],
            ]);

        $targetClient->shouldReceive('getAll')
            ->with('applications/app-1/environments')
            ->andReturn([
                [
                    'id' => 'env-1',
                    'attributes' => [
                        'name' => 'main',
                        'slug' => 'main',
                        'vanity_domain' => 'dracin.laravel.cloud',
                        'environment_variables' => [
                            ['key' => 'API_BASE_URL', 'value' => 'https://old-source-domain.com/api'], // Drift!
                            ['key' => 'UNRESOLVED_VAR', 'value' => '{{apps.missing.url}}'], // Unresolved placeholder!
                        ],
                    ],
                ],
            ]);

        $manifest = MigrationManifest::fromArray([
            'env_resolvers' => [
                'dracin' => [
                    'API_BASE_URL' => 'https://expected-target-domain.com/api',
                ],
            ],
        ]);

        $service = new OrgAuditService(
            targetClient: $targetClient,
            manifest: $manifest,
        );

        $results = $service->auditConfig();

        $apiVar = collect($results)->firstWhere('variableKey', 'API_BASE_URL');
        expect($apiVar->status)->toBe('drift');
        expect($apiVar->isDrift())->toBeTrue();

        $unresolvedVar = collect($results)->firstWhere('variableKey', 'UNRESOLVED_VAR');
        expect($unresolvedVar)->not->toBeNull();
        expect($unresolvedVar->status)->toBe('drift');
        expect($unresolvedVar->message)->toContain('Unresolved template placeholder');
    });

    test('runAudit compiles complete aggregate audit result and respects skip flags', function () {
        $mockClient = Mockery::mock(CloudApiClient::class);
        $mockClient->shouldReceive('getAll')
            ->with('applications')
            ->andReturn([]);

        $service = new OrgAuditService(targetClient: $mockClient);

        $fullResult = $service->runAudit([
            'skip_health' => false,
            'skip_db' => true,
            'skip_config' => true,
        ]);

        expect($fullResult)->toBeInstanceOf(OrgAuditResult::class);
        expect($fullResult->databaseItems)->toBeEmpty();
        expect($fullResult->configItems)->toBeEmpty();
        expect($fullResult->durationSeconds)->toBeGreaterThanOrEqual(0.0);
    });
});
