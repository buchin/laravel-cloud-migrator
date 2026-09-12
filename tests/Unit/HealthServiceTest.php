<?php

use App\Data\HealthCheckItem;
use App\Services\HealthService;

describe('HealthService Unit', function () {
    test('probe executes prober callable and returns status, final url, and latency', function () {
        $service = new HealthService(prober: function (string $url, int $timeout) {
            return [200, $url, 42];
        });

        [$code, $finalUrl, $ms] = $service->probe('https://example.com');
        expect($code)->toBe(200);
        expect($finalUrl)->toBe('https://example.com');
        expect($ms)->toBe(42);
    });

    test('resolveUrl extracts vanity domain with https prefix', function () {
        $service = new HealthService;

        $envWithVanity = [
            'attributes' => [
                'vanity_domain' => 'myapp.laravel.cloud',
            ],
        ];
        expect($service->resolveUrl($envWithVanity))->toBe('https://myapp.laravel.cloud');

        $envWithProtocol = [
            'attributes' => [
                'vanity_domain' => 'https://custom.laravel.cloud',
            ],
        ];
        expect($service->resolveUrl($envWithProtocol))->toBe('https://custom.laravel.cloud');
    });

    test('resolveUrl falls back to custom domains and url', function () {
        $service = new HealthService;

        $envWithCustomDomain = [
            'attributes' => [
                'vanity_domain' => null,
                'custom_domains' => ['app.example.com'],
            ],
        ];
        expect($service->resolveUrl($envWithCustomDomain))->toBe('https://app.example.com');

        $envWithUrl = [
            'attributes' => [
                'vanity_domain' => null,
                'custom_domains' => [],
                'url' => 'https://fallback.example.com',
            ],
        ];
        expect($service->resolveUrl($envWithUrl))->toBe('https://fallback.example.com');

        $envEmpty = [
            'attributes' => [],
        ];
        expect($service->resolveUrl($envEmpty))->toBeNull();
    });

    test('classify maps HTTP status codes to icon, color, and status', function () {
        expect(HealthService::classify(200))->toBe(['✓', 'green', 'healthy']);
        expect(HealthService::classify(204))->toBe(['✓', 'green', 'healthy']);
        expect(HealthService::classify(301))->toBe(['↪', 'cyan', 'redirect']);
        expect(HealthService::classify(302))->toBe(['↪', 'cyan', 'redirect']);
        expect(HealthService::classify(401))->toBe(['🔒', 'yellow', 'warning']);
        expect(HealthService::classify(403))->toBe(['🔒', 'yellow', 'warning']);
        expect(HealthService::classify(404))->toBe(['⚠', 'yellow', 'warning']);
        expect(HealthService::classify(422))->toBe(['⚠', 'yellow', 'warning']);
        expect(HealthService::classify(500))->toBe(['✗', 'red', 'unhealthy']);
        expect(HealthService::classify(503))->toBe(['✗', 'red', 'unhealthy']);
        expect(HealthService::classify(null))->toBe(['✗', 'red', 'unhealthy']);
    });

    test('checkEnvironment handles healthy 200 responses', function () {
        $service = new HealthService(prober: function ($url, $timeout) {
            return [200, $url, 35];
        });

        $app = ['id' => 'app-1', 'attributes' => ['name' => 'Dojo', 'slug' => 'dojo']];
        $env = ['id' => 'env-1', 'attributes' => ['name' => 'production', 'vanity_domain' => 'dojo.laravel.cloud']];

        $item = $service->checkEnvironment($app, $env);

        expect($item)->toBeInstanceOf(HealthCheckItem::class);
        expect($item->appName)->toBe('Dojo');
        expect($item->envName)->toBe('production');
        expect($item->url)->toBe('https://dojo.laravel.cloud');
        expect($item->statusCode)->toBe(200);
        expect($item->isHealthy())->toBeTrue();
        expect($item->isFailed())->toBeFalse();
    });

    test('checkEnvironment handles redirects and warnings', function () {
        $service = new HealthService(prober: function ($url, $timeout) {
            return [301, 'https://redirected.example.com', 20];
        });

        $app = ['id' => 'app-1', 'attributes' => ['name' => 'Dojo', 'slug' => 'dojo']];
        $env = ['id' => 'env-1', 'attributes' => ['name' => 'production', 'vanity_domain' => 'dojo.laravel.cloud']];

        $item = $service->checkEnvironment($app, $env);
        expect($item->isWarning())->toBeTrue();
        expect($item->status)->toBe('redirect');
        expect($item->finalUrl)->toBe('https://redirected.example.com');
    });

    test('checkEnvironment handles unreachable connection failure', function () {
        $service = new HealthService(prober: function ($url, $timeout) {
            return [null, $url, 5000];
        });

        $app = ['id' => 'app-1', 'attributes' => ['name' => 'Dojo', 'slug' => 'dojo']];
        $env = ['id' => 'env-1', 'attributes' => ['name' => 'production', 'vanity_domain' => 'dojo.laravel.cloud']];

        $item = $service->checkEnvironment($app, $env);
        expect($item->isFailed())->toBeTrue();
        expect($item->statusCode)->toBeNull();
        expect($item->status)->toBe('unhealthy');
    });

    test('checkEnvironment returns warning when environment has no URL', function () {
        $service = new HealthService;

        $app = ['id' => 'app-1', 'attributes' => ['name' => 'Dojo', 'slug' => 'dojo']];
        $env = ['id' => 'env-1', 'attributes' => ['name' => 'production']];

        $item = $service->checkEnvironment($app, $env);
        expect($item->isWarning())->toBeTrue();
        expect($item->message)->toContain('No URL');
    });
});
