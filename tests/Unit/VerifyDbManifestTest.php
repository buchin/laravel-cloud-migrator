<?php

use App\Commands\VerifyDbCommand;
use App\Services\MigrationManifest;

function callIsTransient(string $table, ?MigrationManifest $manifest = null, ?string $schema = null, ?string $cluster = null): bool
{
    $cmd = new ReflectionClass(VerifyDbCommand::class);
    $method = $cmd->getMethod('isTransient');
    $method->setAccessible(true);

    return $method->invoke($cmd->newInstanceWithoutConstructor(), $table, $manifest, $schema, $cluster);
}

function callIsIgnored(string $schema, string $table, array $ignoreTables = [], ?MigrationManifest $manifest = null, ?string $cluster = null): bool
{
    $cmd = new ReflectionClass(VerifyDbCommand::class);
    $method = $cmd->getMethod('isIgnored');
    $method->setAccessible(true);

    return $method->invoke($cmd->newInstanceWithoutConstructor(), $schema, $table, $ignoreTables, $manifest, $cluster);
}

function callIsSchemaOnly(string $schema, string $table, ?MigrationManifest $manifest = null, ?string $cluster = null): bool
{
    $cmd = new ReflectionClass(VerifyDbCommand::class);
    $method = $cmd->getMethod('isSchemaOnly');
    $method->setAccessible(true);

    return $method->invoke($cmd->newInstanceWithoutConstructor(), $schema, $table, $manifest, $cluster);
}

test('VerifyDbCommand recognizes manifest transient tables', function () {
    $manifest = MigrationManifest::fromArray([
        'databases' => [
            'dojo.main' => [
                'tables' => [
                    'custom_queue' => 'transient',
                    'auth_tokens' => 'transient',
                ],
            ],
        ],
    ]);

    expect(callIsTransient('custom_queue', $manifest, 'main', 'dojo'))->toBeTrue();
    expect(callIsTransient('auth_tokens', $manifest, 'main', 'dojo'))->toBeTrue();
    expect(callIsTransient('users', $manifest, 'main', 'dojo'))->toBeFalse();
    // Default built-in transient table still recognized
    expect(callIsTransient('jobs', $manifest, 'main', 'dojo'))->toBeTrue();
});

test('VerifyDbCommand recognizes manifest schema_only tables', function () {
    $manifest = MigrationManifest::fromArray([
        'databases' => [
            'dojo.main' => [
                'tables' => [
                    'telescope_entries' => 'schema_only',
                ],
            ],
        ],
    ]);

    expect(callIsSchemaOnly('main', 'telescope_entries', $manifest, 'dojo'))->toBeTrue();
    expect(callIsSchemaOnly('main', 'users', $manifest, 'dojo'))->toBeFalse();
});

test('VerifyDbCommand recognizes manifest ignore tables and cluster prefix', function () {
    $manifest = MigrationManifest::fromArray([
        'databases' => [
            'dojo.main' => [
                'tables' => [
                    'nerd_urls' => 'ignore',
                ],
            ],
        ],
    ]);

    expect(callIsIgnored('main', 'nerd_urls', [], $manifest, 'dojo'))->toBeTrue();
    expect(callIsIgnored('main', 'orders', [], $manifest, 'dojo'))->toBeFalse();

    // CLI ignore table with cluster prefix
    expect(callIsIgnored('main', 'temp_table', ['dojo.temp_table'], null, 'dojo'))->toBeTrue();
});
