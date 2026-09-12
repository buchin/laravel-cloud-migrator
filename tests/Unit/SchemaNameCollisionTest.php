<?php

use App\Commands\MigrateDbCommand;
use App\Commands\StatusCommand;
use App\Commands\VerifyDbCommand;
use App\Services\CloudApiClient;

function callPrivate(object $object, string $method, array $args = [])
{
    $ref = new ReflectionMethod($object, $method);
    $ref->setAccessible(true);

    return $ref->invokeArgs($object, $args);
}

function makeCluster(string $id, string $name): array
{
    return [
        'id' => $id,
        'attributes' => [
            'name' => $name,
            'type' => 'laravel_mysql_84',
            'status' => 'available',
            'connection' => ['hostname' => "{$id}.example.com", 'port' => 3306],
        ],
    ];
}

function makeSchema(string $id, string $name): array
{
    return [
        'id' => $id,
        'attributes' => ['name' => $name],
    ];
}

// Two different clusters that both happen to contain a schema named "main" —
// this is what broke db:migrate and org:status before the cluster-qualified key fix.
test('MigrateDbCommand keeps same-named schemas in different clusters distinct', function () {
    $client = Mockery::mock(CloudApiClient::class);

    $client->shouldReceive('getAll')
        ->with('databases/clusters')
        ->andReturn([
            makeCluster('cluster-a', 'app-one'),
            makeCluster('cluster-b', 'app-two'),
        ]);

    $client->shouldReceive('getAll')
        ->with('databases/clusters/cluster-a/databases')
        ->andReturn([makeSchema('schema-a', 'main')]);

    $client->shouldReceive('getAll')
        ->with('databases/clusters/cluster-b/databases')
        ->andReturn([makeSchema('schema-b', 'main')]);

    $command = new MigrateDbCommand;

    $map = callPrivate($command, 'buildSourcePairs', [$client]);

    expect($map)->toHaveCount(2)
        ->and($map)->toHaveKey('app-one.main')
        ->and($map)->toHaveKey('app-two.main')
        ->and($map['app-one.main']['connection']['hostname'])->toBe('cluster-a.example.com')
        ->and($map['app-two.main']['connection']['hostname'])->toBe('cluster-b.example.com');
});

test('StatusCommand keeps same-named schemas in different clusters distinct', function () {
    $client = Mockery::mock(CloudApiClient::class);

    $client->shouldReceive('getAll')
        ->with('databases/clusters')
        ->andReturn([
            makeCluster('cluster-a', 'app-one'),
            makeCluster('cluster-b', 'app-two'),
        ]);

    $client->shouldReceive('getAll')
        ->with('databases/clusters/cluster-a/databases')
        ->andReturn([makeSchema('schema-a', 'main')]);

    $client->shouldReceive('getAll')
        ->with('databases/clusters/cluster-b/databases')
        ->andReturn([makeSchema('schema-b', 'main')]);

    $command = new StatusCommand;

    $map = callPrivate($command, 'buildTargetConnMap', [$client]);

    expect($map)->toHaveCount(2)
        ->and($map)->toHaveKey('app-one.main')
        ->and($map)->toHaveKey('app-two.main')
        ->and($map['app-one.main']['hostname'])->toBe('cluster-a.example.com')
        ->and($map['app-two.main']['hostname'])->toBe('cluster-b.example.com');
});

test('VerifyDbCommand keeps same-named schemas in different clusters distinct', function () {
    $client = Mockery::mock(CloudApiClient::class);

    $client->shouldReceive('getAll')
        ->with('databases/clusters')
        ->andReturn([
            makeCluster('cluster-a', 'app-one'),
            makeCluster('cluster-b', 'app-two'),
        ]);

    $client->shouldReceive('getAll')
        ->with('databases/clusters/cluster-a/databases')
        ->andReturn([makeSchema('schema-a', 'main')]);

    $client->shouldReceive('getAll')
        ->with('databases/clusters/cluster-b/databases')
        ->andReturn([makeSchema('schema-b', 'main')]);

    $command = new VerifyDbCommand;

    $map = callPrivate($command, 'fetchClusterSchemas', [$client]);

    expect($map)->toHaveCount(2)
        ->and($map)->toHaveKey('app-one.main')
        ->and($map)->toHaveKey('app-two.main')
        ->and($map['app-one.main']['connection']['hostname'])->toBe('cluster-a.example.com')
        ->and($map['app-two.main']['connection']['hostname'])->toBe('cluster-b.example.com');
});
