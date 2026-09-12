<?php

use App\Services\MigrationManifest;

test('MigrationManifest parses from array with all policy types and configs', function () {
    $manifest = MigrationManifest::fromArray([
        'version' => '1.0',
        'name' => 'Test Manifest',
        'databases' => [
            'dojo.main' => [
                'default_policy' => 'full',
                'tables' => [
                    'telescope_entries' => 'schema_only',
                    'activity_logs' => [
                        'policy' => 'chunked',
                        'chunk_size' => 25000,
                        'threshold_rows' => 50000,
                    ],
                    'sessions' => 'transient',
                    'legacy_temp' => [
                        'policy' => 'ignore',
                        'description' => 'Temporary table to ignore',
                    ],
                ],
            ],
        ],
        'env_resolvers' => [
            'dracin' => [
                'API_BASE_URL' => '{{apps.dracin-api.envs.main.url}}/api',
            ],
        ],
    ]);

    expect($manifest->countTotalTableRules())->toBe(4);

    // Test telescope_entries (schema_only)
    $telescopePolicy = $manifest->getTablePolicy('telescope_entries', 'main', 'dojo');
    expect($telescopePolicy->isSchemaOnly())->toBeTrue();
    expect($telescopePolicy->shouldMigrateData())->toBeFalse();
    expect($manifest->isSchemaOnly('telescope_entries', 'main', 'dojo'))->toBeTrue();
    expect($manifest->isChunked('telescope_entries', 'main', 'dojo'))->toBeFalse();

    // Test activity_logs (chunked)
    $activityPolicy = $manifest->getTablePolicy('activity_logs', 'main', 'dojo');
    expect($activityPolicy->isChunked())->toBeTrue();
    expect($activityPolicy->chunkSize)->toBe(25000);
    expect($activityPolicy->thresholdRows)->toBe(50000);
    expect($activityPolicy->shouldMigrateData())->toBeTrue();
    expect($manifest->isChunked('activity_logs', 'main', 'dojo'))->toBeTrue();

    // Test sessions (transient)
    $sessionsPolicy = $manifest->getTablePolicy('sessions', 'main', 'dojo');
    expect($sessionsPolicy->isTransient())->toBeTrue();
    expect($sessionsPolicy->shouldMigrateData())->toBeFalse();
    expect($manifest->isTransient('sessions', 'main', 'dojo'))->toBeTrue();

    // Test legacy_temp (ignore)
    $legacyPolicy = $manifest->getTablePolicy('legacy_temp', 'main', 'dojo');
    expect($legacyPolicy->isIgnore())->toBeTrue();
    expect($legacyPolicy->shouldMigrateData())->toBeFalse();
    expect($manifest->isIgnored('legacy_temp', 'main', 'dojo'))->toBeTrue();

    // Default policy for unlisted table
    $usersPolicy = $manifest->getTablePolicy('users', 'main', 'dojo');
    expect($usersPolicy->isFull())->toBeTrue();
    expect($usersPolicy->shouldMigrateData())->toBeTrue();

    // Aggregations
    expect($manifest->getIgnoreTables('main', 'dojo'))->toBe(['legacy_temp']);
    expect($manifest->getSchemaOnlyTables('main', 'dojo'))->toBe(['telescope_entries']);
    expect($manifest->getTransientTables('main', 'dojo'))->toBe(['sessions']);

    // Env resolvers
    expect($manifest->getEnvResolvers('dracin'))->toBe([
        'API_BASE_URL' => '{{apps.dracin-api.envs.main.url}}/api',
    ]);
});

test('MigrationManifest parses JSON string correctly', function () {
    $json = json_encode([
        'version' => '1.0',
        'databases' => [
            'nerd' => [
                'tables' => [
                    'links' => 'ignore',
                ],
            ],
        ],
    ]);

    $manifest = MigrationManifest::fromString($json);
    expect($manifest->isIgnored('links', 'nerd'))->toBeTrue();
    expect($manifest->isIgnored('other', 'nerd'))->toBeFalse();
});

test('MigrationManifest loads and validates migration-plan.example.json', function () {
    $examplePath = __DIR__.'/../../migration-plan.example.json';
    expect(file_exists($examplePath))->toBeTrue();

    $manifest = MigrationManifest::fromFile($examplePath);
    expect($manifest->countTotalTableRules())->toBeGreaterThanOrEqual(10);
    expect($manifest->isSchemaOnly('telescope_entries', 'main', 'dojo'))->toBeTrue();
    expect($manifest->isTransient('sessions', 'main', 'dojo'))->toBeTrue();
    expect($manifest->isChunked('failed_jobs', 'main', 'dojo'))->toBeTrue();
    expect($manifest->isIgnored('nerd_urls', 'main', 'dojo'))->toBeTrue();
    expect($manifest->isIgnored('links', 'nerd'))->toBeTrue();
    expect($manifest->getEnvResolvers('dracin')['API_BASE_URL'])->toContain('{{apps.dracin-api.envs.main.url}}');
});

test('MigrationManifest matches database by schema name alone or cluster.schema', function () {
    $manifest = MigrationManifest::fromArray([
        'databases' => [
            'dracin_api.main' => [
                'tables' => [
                    'episodes' => 'chunked',
                ],
            ],
        ],
    ]);

    expect($manifest->isChunked('episodes', 'main', 'dracin_api'))->toBeTrue();
    expect($manifest->isChunked('episodes', 'main'))->toBeTrue();
    expect($manifest->isChunked('episodes', 'other_schema'))->toBeFalse();
});

test('MigrationManifest throws RuntimeException when file not found', function () {
    MigrationManifest::fromFile('/path/to/nonexistent/file.json');
})->throws(RuntimeException::class, 'Migration manifest file not found');

test('MigrationManifest throws InvalidArgumentException on invalid JSON string', function () {
    MigrationManifest::fromString('{invalid json}');
})->throws(InvalidArgumentException::class, 'Invalid JSON string');

test('MigrationManifest throws InvalidArgumentException on invalid table policy', function () {
    MigrationManifest::fromArray([
        'databases' => [
            'main' => [
                'tables' => [
                    'posts' => 'invalid_policy',
                ],
            ],
        ],
    ]);
})->throws(InvalidArgumentException::class, "Invalid table policy 'invalid_policy'");
