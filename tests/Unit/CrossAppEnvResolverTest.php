<?php

use App\Services\CloudApiClient;
use App\Services\CrossAppEnvResolver;

test('CrossAppEnvResolver resolves apps.<app>.envs.<env>.url with vanity domain', function () {
    $resolver = new CrossAppEnvResolver;
    $resolver->registerApp('dracin-api', ['id' => 'app-1', 'attributes' => ['name' => 'dracin-api', 'slug' => 'dracin-api']], [
        ['id' => 'env-main', 'attributes' => ['name' => 'main', 'slug' => 'main', 'vanity_domain' => 'dracin-api-main-uatz4m.laravel.cloud']],
    ]);

    $resolved = $resolver->resolve('{{apps.dracin-api.envs.main.url}}/api/v1');
    expect($resolved)->toBe('https://dracin-api-main-uatz4m.laravel.cloud/api/v1');
});

test('CrossAppEnvResolver resolves vanity_domain, id, name, and slug properties', function () {
    $resolver = new CrossAppEnvResolver;
    $resolver->registerApp('dracin-api', ['id' => 'app-123', 'attributes' => ['name' => 'dracin-api', 'slug' => 'dracin-api']], [
        ['id' => 'env-456', 'attributes' => ['name' => 'staging', 'slug' => 'staging', 'vanity_domain' => 'dracin-staging.laravel.cloud']],
    ]);

    expect($resolver->resolve('{{apps.dracin-api.envs.staging.vanity_domain}}'))->toBe('dracin-staging.laravel.cloud');
    expect($resolver->resolve('{{apps.dracin-api.envs.staging.id}}'))->toBe('env-456');
    expect($resolver->resolve('{{apps.dracin-api.envs.staging.name}}'))->toBe('staging');
    expect($resolver->resolve('{{apps.dracin-api.envs.staging.slug}}'))->toBe('staging');
    expect($resolver->resolve('{{apps.dracin-api.id}}'))->toBe('app-123');
});

test('CrossAppEnvResolver resolves apps.<app>.url defaulting to production or main env', function () {
    $resolver = new CrossAppEnvResolver;
    $resolver->registerApp('dojo', ['id' => 'app-dojo', 'attributes' => ['name' => 'dojo', 'slug' => 'dojo']], [
        ['id' => 'env-dev', 'attributes' => ['name' => 'dev', 'slug' => 'dev', 'vanity_domain' => 'dojo-dev.laravel.cloud']],
        ['id' => 'env-prod', 'attributes' => ['name' => 'production', 'slug' => 'production', 'vanity_domain' => 'dojo-prod.laravel.cloud']],
    ]);

    expect($resolver->resolve('{{apps.dojo.url}}'))->toBe('https://dojo-prod.laravel.cloud');
    expect($resolver->resolve('{{apps.dojo.vanity_domain}}'))->toBe('dojo-prod.laravel.cloud');
});

test('CrossAppEnvResolver resolves custom domains when vanity domain is absent', function () {
    $resolver = new CrossAppEnvResolver;
    $resolver->registerApp('myapp', ['id' => 'app-custom', 'attributes' => ['name' => 'myapp', 'slug' => 'myapp']], [
        [
            'id' => 'env-main',
            'attributes' => [
                'name' => 'main',
                'slug' => 'main',
                'vanity_domain' => null,
                'custom_domains' => [
                    ['domain' => 'api.example.com'],
                ],
            ],
        ],
    ]);

    expect($resolver->resolve('{{apps.myapp.envs.main.url}}'))->toBe('https://api.example.com');
});

test('CrossAppEnvResolver resolves target.vanity_domain from context', function () {
    $resolver = new CrossAppEnvResolver;
    $resolver->registerApp('stats', ['id' => 'app-stats', 'attributes' => ['name' => 'stats', 'slug' => 'stats']], [
        ['id' => 'env-main', 'attributes' => ['name' => 'main', 'slug' => 'main', 'vanity_domain' => 'stats.laravel.cloud']],
    ]);

    $resolved = $resolver->resolve('https://{{target.vanity_domain}}/callback', ['app' => 'stats', 'env' => 'main']);
    expect($resolved)->toBe('https://stats.laravel.cloud/callback');
});

test('CrossAppEnvResolver resolves custom resolvers and env variables', function () {
    putenv('TEST_MIGRATION_ENV=testing123');

    $resolver = new CrossAppEnvResolver;
    $resolver->registerCustomResolver('custom.cluster_host', 'db.internal.cloud');
    $resolver->registerCustomResolver('custom.lambda', fn ($ctx) => 'prefix-'.($ctx['app'] ?? 'app'));

    expect($resolver->resolve('tcp://{{custom.cluster_host}}:3306'))->toBe('tcp://db.internal.cloud:3306');
    expect($resolver->resolve('val: {{custom.lambda}}', ['app' => 'nerd']))->toBe('val: prefix-nerd');
    expect($resolver->resolve('current: {{env.TEST_MIGRATION_ENV}}'))->toBe('current: testing123');

    putenv('TEST_MIGRATION_ENV');
});

test('CrossAppEnvResolver resolveVariables processes array of variables', function () {
    $resolver = new CrossAppEnvResolver;
    $resolver->registerApp('api', ['id' => 'app-api', 'attributes' => ['name' => 'api', 'slug' => 'api']], [
        ['id' => 'env-prod', 'attributes' => ['name' => 'production', 'vanity_domain' => 'api.laravel.cloud']],
    ]);

    $variables = [
        ['key' => 'APP_NAME', 'value' => 'ClientApp'],
        ['key' => 'BACKEND_URL', 'value' => '{{apps.api.envs.production.url}}/v1'],
    ];

    $resolved = $resolver->resolveVariables($variables);
    expect($resolved[0]['value'])->toBe('ClientApp');
    expect($resolved[1]['value'])->toBe('https://api.laravel.cloud/v1');
});

test('CrossAppEnvResolver fetches dynamically from CloudApiClient and caches', function () {
    $client = Mockery::mock(CloudApiClient::class);

    $client->shouldReceive('getAll')
        ->with('applications')
        ->once()
        ->andReturn([
            [
                'id' => 'app-remote',
                'attributes' => [
                    'name' => 'remote-app',
                    'slug' => 'remote-app',
                ],
            ],
        ]);

    $client->shouldReceive('getAll')
        ->with('applications/app-remote/environments')
        ->once()
        ->andReturn([
            [
                'id' => 'env-remote-main',
                'attributes' => [
                    'name' => 'main',
                    'slug' => 'main',
                    'vanity_domain' => 'remote-app-main.laravel.cloud',
                ],
            ],
        ]);

    $resolver = new CrossAppEnvResolver($client);

    // Call 1: fetches from client
    $res1 = $resolver->resolve('{{apps.remote-app.envs.main.url}}');
    expect($res1)->toBe('https://remote-app-main.laravel.cloud');

    // Call 2: served from memory cache (no additional getAll calls)
    $res2 = $resolver->resolve('{{apps.remote-app.envs.main.id}}');
    expect($res2)->toBe('env-remote-main');
});

test('CrossAppEnvResolver throws RuntimeException on unresolvable placeholder in strict mode', function () {
    $resolver = new CrossAppEnvResolver(strict: true);
    $resolver->resolve('{{apps.unknown-app.url}}');
})->throws(RuntimeException::class, "Could not resolve template placeholder '{{apps.unknown-app.url}}'");

test('CrossAppEnvResolver leaves placeholder untouched in non-strict mode', function () {
    $resolver = new CrossAppEnvResolver(strict: false);
    $resolved = $resolver->resolve('prefix-{{apps.unknown-app.url}}-suffix');
    expect($resolved)->toBe('prefix-{{apps.unknown-app.url}}-suffix');
});

test('CrossAppEnvResolver resolveVanityDomain resolves legacy hostnames', function () {
    $resolver = new CrossAppEnvResolver;
    $resolver->registerApp('dracin-api', ['id' => 'app-1', 'attributes' => ['name' => 'dracin-api', 'slug' => 'dracin-api']], [
        ['id' => 'env-main', 'attributes' => ['name' => 'main', 'vanity_domain' => 'dracin-api-target.laravel.cloud']],
    ]);

    expect($resolver->resolveVanityDomain('dracin-api.laravel.cloud'))->toBe('dracin-api-target.laravel.cloud');
    expect($resolver->resolveVanityDomain('unknown.laravel.cloud'))->toBeNull();
});
