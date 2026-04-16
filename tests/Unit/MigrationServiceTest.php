<?php

use App\Data\ApplicationData;
use App\Data\EnvironmentData;
use App\Data\MigrationPlan;
use App\Services\CloudApiClient;
use App\Services\MigrationService;

function makeApp(string $id = 'app-1', string $name = 'myapp', string $slug = 'myapp'): array
{
    return [
        'id' => $id,
        'attributes' => [
            'name' => $name,
            'slug' => $slug,
            'repository' => 'org/repo',
            'region' => 'us-east-1',
            'source_control_provider_type' => 'github',
        ],
        'relationships' => [],
    ];
}

function makeEnv(string $id = 'env-1', string $name = 'production'): array
{
    return [
        'id' => $id,
        'attributes' => [
            'name' => $name,
            'slug' => $name,
            'branch' => 'main',
            'php_version' => '8.4',
            'node_version' => '20',
            'build_command' => null,
            'deploy_command' => null,
            'uses_octane' => false,
            'environment_variables' => [],
        ],
        'relationships' => [],
    ];
}

function makePlan(string $appId = 'app-1', string $appSlug = 'myapp'): MigrationPlan
{
    return new MigrationPlan(
        application: new ApplicationData(
            id: $appId,
            name: 'myapp',
            slug: $appSlug,
            repository: 'org/repo',
            region: 'us-east-1',
            sourceControlProviderType: 'github',
        ),
        environments: [
            new EnvironmentData(
                id: 'env-1',
                name: 'production',
                slug: 'production',
                branch: 'main',
                phpVersion: '8.4',
                nodeVersion: '20',
                buildCommand: null,
                deployCommand: null,
                usesOctane: false,
                databaseSchemaId: null,
                cacheId: null,
                variables: [],
                instances: [],
            ),
        ],
        variables: [],
        databases: [],
        caches: [],
        instances: [],
    );
}

test('applicationExistsInTarget returns true when name matches', function () {
    $source = Mockery::mock(CloudApiClient::class);
    $target = Mockery::mock(CloudApiClient::class);

    $target->shouldReceive('getAll')
        ->with('applications')
        ->andReturn([makeApp('app-2', 'myapp', 'myapp-2')]);

    $service = new MigrationService($source, $target);

    expect($service->applicationExistsInTarget('myapp', 'myapp'))->toBeTrue();
});

test('applicationExistsInTarget returns false when not found', function () {
    $source = Mockery::mock(CloudApiClient::class);
    $target = Mockery::mock(CloudApiClient::class);

    $target->shouldReceive('getAll')
        ->with('applications')
        ->andReturn([makeApp('app-2', 'otherapp', 'otherapp')]);

    $service = new MigrationService($source, $target);

    expect($service->applicationExistsInTarget('myapp', 'myapp'))->toBeFalse();
});

test('execute creates app and returns new slug', function () {
    $source = Mockery::mock(CloudApiClient::class);
    $target = Mockery::mock(CloudApiClient::class);

    // App creation
    $target->shouldReceive('post')
        ->with('applications', Mockery::any())
        ->andReturn(['data' => makeApp('app-new', 'myapp', 'myapp')]);

    // Environment creation
    $target->shouldReceive('post')
        ->with('applications/app-new/environments', Mockery::any())
        ->andReturn(['data' => makeEnv('env-new', 'production')]);

    // No variables, databases, caches, instances to migrate
    $source->shouldReceive('getAll')->andReturn([]);
    $target->shouldReceive('getAll')->andReturn([]);
    $target->shouldReceive('patch')->andReturn(['data' => makeEnv('env-new')]);

    $service = new MigrationService($source, $target);
    $slug = $service->execute(makePlan(), fn () => null);

    expect($slug)->toBe('myapp');
    expect($service->getLastCreatedAppId())->toBe('app-new');
});

test('getLastCreatedAppId is null before execute', function () {
    $source = Mockery::mock(CloudApiClient::class);
    $target = Mockery::mock(CloudApiClient::class);

    $service = new MigrationService($source, $target);

    expect($service->getLastCreatedAppId())->toBeNull();
});

test('setLastCreatedAppId and setEnvIdMap are reflected in getters', function () {
    $source = Mockery::mock(CloudApiClient::class);
    $target = Mockery::mock(CloudApiClient::class);

    $service = new MigrationService($source, $target);
    $service->setLastCreatedAppId('app-123');
    $service->setEnvIdMap(['env-src' => 'env-tgt']);

    expect($service->getLastCreatedAppId())->toBe('app-123');
    expect($service->getEnvIdMap())->toBe(['env-src' => 'env-tgt']);
});

test('useSharedRegistries shares cluster and cache state across apps', function () {
    $source = Mockery::mock(CloudApiClient::class);
    $target = Mockery::mock(CloudApiClient::class);

    $clusterRegistry = [];
    $cacheRegistry = [];

    $service = new MigrationService($source, $target);
    $service->useSharedRegistries($clusterRegistry, $cacheRegistry);

    // Simulate a cluster being registered externally.
    $clusterRegistry['cluster-src-1'] = 'cluster-tgt-1';

    // The service's registry is the same array reference.
    $ref = new ReflectionProperty(MigrationService::class, 'clusterRegistry');
    $ref->setAccessible(true);
    $actual = $ref->getValue($service);

    expect($actual)->toBe(['cluster-src-1' => 'cluster-tgt-1']);
});
