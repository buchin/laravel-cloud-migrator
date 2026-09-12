<?php

use App\Services\CloudApiClient;
use App\Services\VanityTransferService;

describe('VanityTransferService Unit', function () {
    test('normalizeVanity cleans subdomain from various formats', function () {
        $service = new VanityTransferService;

        expect($service->normalizeVanity('termapi'))->toBe('termapi')
            ->and($service->normalizeVanity('termapi.laravel.cloud'))->toBe('termapi')
            ->and($service->normalizeVanity('https://termapi.laravel.cloud'))->toBe('termapi')
            ->and($service->normalizeVanity('  dracin-api.laravel.cloud/  '))->toBe('dracin-api')
            ->and($service->normalizeVanity('http://sub-domain.laravel.cloud/path'))->toBe('sub-domain');
    });

    test('isReservationLocked identifies 422 status and domain taken messages', function () {
        $service = new VanityTransferService;

        $e422 = new RuntimeException('API Error [422]: This Laravel Cloud domain is already taken.', 422);
        $eTaken = new RuntimeException('Domain is taken by another app', 400);
        $eCooldown = new RuntimeException('Reservation cooldown lock active', 500);
        $eRandom = new RuntimeException('Internal Server Error', 500);

        expect($service->isReservationLocked($e422))->toBeTrue()
            ->and($service->isReservationLocked($eTaken))->toBeTrue()
            ->and($service->isReservationLocked($eCooldown))->toBeTrue()
            ->and($service->isReservationLocked($eRandom))->toBeFalse();
    });

    test('findApp matches by name, slug, or ID', function () {
        $mockClient = Mockery::mock(CloudApiClient::class);
        $mockClient->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'app-1', 'attributes' => ['name' => 'termapi', 'slug' => 'termapi-prod']],
            ['id' => 'app-2', 'attributes' => ['name' => 'dracin', 'slug' => 'dracin-app']],
        ]);

        $service = new VanityTransferService;

        expect($service->findApp($mockClient, 'termapi'))->toBe(['id' => 'app-1', 'attributes' => ['name' => 'termapi', 'slug' => 'termapi-prod']])
            ->and($service->findApp($mockClient, 'dracin-app'))->toBe(['id' => 'app-2', 'attributes' => ['name' => 'dracin', 'slug' => 'dracin-app']])
            ->and($service->findApp($mockClient, 'app-1'))->toBe(['id' => 'app-1', 'attributes' => ['name' => 'termapi', 'slug' => 'termapi-prod']])
            ->and($service->findApp($mockClient, 'non-existent'))->toBeNull();
    });

    test('findEnvironment matches by name, slug, or ID', function () {
        $mockClient = Mockery::mock(CloudApiClient::class);
        $mockClient->shouldReceive('getAll')->with('applications/app-1/environments')->andReturn([
            ['id' => 'env-1', 'attributes' => ['name' => 'production', 'slug' => 'production']],
            ['id' => 'env-2', 'attributes' => ['name' => 'staging', 'slug' => 'staging']],
        ]);

        $service = new VanityTransferService;

        expect($service->findEnvironment($mockClient, 'app-1', 'production'))
            ->toBe(['id' => 'env-1', 'attributes' => ['name' => 'production', 'slug' => 'production']])
            ->and($service->findEnvironment($mockClient, 'app-1', 'env-2'))
            ->toBe(['id' => 'env-2', 'attributes' => ['name' => 'staging', 'slug' => 'staging']])
            ->and($service->findEnvironment($mockClient, 'app-1', 'missing'))
            ->toBeNull();
    });

    test('transfer successfully renames source and claims target on attempt 1', function () {
        $source = Mockery::mock(CloudApiClient::class);
        $source->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'src-app-1', 'attributes' => ['name' => 'termapi', 'slug' => 'termapi']],
        ]);
        $source->shouldReceive('getAll')->with('applications/src-app-1/environments')->andReturn([
            ['id' => 'src-env-1', 'attributes' => ['name' => 'production', 'vanity_domain' => 'termapi.laravel.cloud']],
        ]);
        // Source should be renamed to archived name
        $source->shouldReceive('put')
            ->once()
            ->withArgs(function ($path, $data) {
                return $path === 'environments/src-env-1/vanity-domain' &&
                    str_starts_with($data['name'], 'termapi-archived-');
            })
            ->andReturn(['data' => ['id' => 'src-env-1']]);

        $target = Mockery::mock(CloudApiClient::class);
        $target->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'tgt-app-1', 'attributes' => ['name' => 'termapi', 'slug' => 'termapi-target']],
        ]);
        $target->shouldReceive('getAll')->with('applications/tgt-app-1/environments')->andReturn([
            ['id' => 'tgt-env-1', 'attributes' => ['name' => 'production', 'vanity_domain' => 'temp-123.laravel.cloud']],
        ]);
        // Target claims vanity
        $target->shouldReceive('put')
            ->once()
            ->with('environments/tgt-env-1/vanity-domain', ['name' => 'termapi'])
            ->andReturn([
                'data' => [
                    'id' => 'tgt-env-1',
                    'attributes' => ['vanity_domain' => 'termapi.laravel.cloud'],
                ],
            ]);

        $service = new VanityTransferService;
        $result = $service->transfer(
            source: $source,
            target: $target,
            sourceAppName: 'termapi',
            sourceEnvName: 'production',
            targetAppName: 'termapi',
            targetEnvName: 'production',
            vanity: 'termapi',
            deleteSource: false,
        );

        expect($result->success)->toBeTrue()
            ->and($result->status)->toBe('transferred')
            ->and($result->vanity)->toBe('termapi')
            ->and($result->fullVanityDomain())->toBe('termapi.laravel.cloud')
            ->and($result->attempts)->toBe(1)
            ->and($result->releasedAs)->toStartWith('termapi-archived-')
            ->and($result->rolledBack)->toBeFalse();
    });

    test('transfer auto-detects vanity from source environment', function () {
        $source = Mockery::mock(CloudApiClient::class);
        $source->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'src-app-1', 'attributes' => ['name' => 'dracin-api', 'slug' => 'dracin-api']],
        ]);
        $source->shouldReceive('getAll')->with('applications/src-app-1/environments')->andReturn([
            ['id' => 'src-env-1', 'attributes' => ['name' => 'production', 'vanity_domain' => 'dracin-api.laravel.cloud']],
        ]);
        $source->shouldReceive('put')
            ->once()
            ->withArgs(fn ($path, $data) => $path === 'environments/src-env-1/vanity-domain' && str_starts_with($data['name'], 'dracin-api-archived-'))
            ->andReturn(['data' => ['id' => 'src-env-1']]);

        $target = Mockery::mock(CloudApiClient::class);
        $target->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'tgt-app-1', 'attributes' => ['name' => 'dracin-api', 'slug' => 'dracin-api-tgt']],
        ]);
        $target->shouldReceive('getAll')->with('applications/tgt-app-1/environments')->andReturn([
            ['id' => 'tgt-env-1', 'attributes' => ['name' => 'production', 'vanity_domain' => null]],
        ]);
        $target->shouldReceive('put')
            ->once()
            ->with('environments/tgt-env-1/vanity-domain', ['name' => 'dracin-api'])
            ->andReturn(['data' => ['id' => 'tgt-env-1', 'attributes' => ['vanity_domain' => 'dracin-api.laravel.cloud']]]);

        $service = new VanityTransferService;
        // vanity is null -> auto-detected as "dracin-api"
        $result = $service->transfer(
            source: $source,
            target: $target,
            sourceAppName: 'dracin-api',
            sourceEnvName: 'production',
            targetAppName: 'dracin-api',
            targetEnvName: 'production',
            vanity: null,
            deleteSource: false,
        );

        expect($result->success)->toBeTrue()
            ->and($result->vanity)->toBe('dracin-api')
            ->and($result->fullVanityDomain())->toBe('dracin-api.laravel.cloud');
    });

    test('transfer returns already_set when target already possesses vanity domain', function () {
        $source = Mockery::mock(CloudApiClient::class);
        $source->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'src-app-1', 'attributes' => ['name' => 'termapi', 'slug' => 'termapi']],
        ]);
        $source->shouldReceive('getAll')->with('applications/src-app-1/environments')->andReturn([
            ['id' => 'src-env-1', 'attributes' => ['name' => 'production', 'vanity_domain' => 'termapi.laravel.cloud']],
        ]);
        // Source should NOT be mutated
        $source->shouldNotReceive('put');
        $source->shouldNotReceive('delete');

        $target = Mockery::mock(CloudApiClient::class);
        $target->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'tgt-app-1', 'attributes' => ['name' => 'termapi', 'slug' => 'termapi']],
        ]);
        $target->shouldReceive('getAll')->with('applications/tgt-app-1/environments')->andReturn([
            ['id' => 'tgt-env-1', 'attributes' => ['name' => 'production', 'vanity_domain' => 'termapi.laravel.cloud']],
        ]);
        $target->shouldNotReceive('put');

        $service = new VanityTransferService;
        $result = $service->transfer(
            source: $source,
            target: $target,
            sourceAppName: 'termapi',
            sourceEnvName: 'production',
            targetAppName: 'termapi',
            targetEnvName: 'production',
            vanity: 'termapi',
        );

        expect($result->success)->toBeTrue()
            ->and($result->status)->toBe('already_set')
            ->and($result->message)->toContain('already possesses vanity domain');
    });

    test('transfer handles dynamic backoff on reservation cooldown lock and succeeds on retry', function () {
        $source = Mockery::mock(CloudApiClient::class);
        $source->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'src-app-1', 'attributes' => ['name' => 'termapi', 'slug' => 'termapi']],
        ]);
        $source->shouldReceive('getAll')->with('applications/src-app-1/environments')->andReturn([
            ['id' => 'src-env-1', 'attributes' => ['name' => 'production', 'vanity_domain' => 'termapi.laravel.cloud']],
        ]);
        $source->shouldReceive('put')->once()->andReturn(['data' => []]);

        $target = Mockery::mock(CloudApiClient::class);
        $target->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'tgt-app-1', 'attributes' => ['name' => 'termapi', 'slug' => 'termapi-target']],
        ]);
        $target->shouldReceive('getAll')->with('applications/tgt-app-1/environments')->andReturn([
            ['id' => 'tgt-env-1', 'attributes' => ['name' => 'production', 'vanity_domain' => null]],
        ]);

        // Attempt 1: 422 reservation locked
        // Attempt 2: 422 reservation locked
        // Attempt 3: 200 OK claimed!
        $callCount = 0;
        $target->shouldReceive('put')
            ->with('environments/tgt-env-1/vanity-domain', ['name' => 'termapi'])
            ->times(3)
            ->andReturnUsing(function () use (&$callCount) {
                $callCount++;
                if ($callCount <= 2) {
                    throw new RuntimeException('API Error [422]: This Laravel Cloud domain is already taken.', 422);
                }

                return ['data' => ['id' => 'tgt-env-1', 'attributes' => ['vanity_domain' => 'termapi.laravel.cloud']]];
            });

        $sleepCalls = [];
        $mockSleeper = function (int $us) use (&$sleepCalls) {
            $sleepCalls[] = $us;
        };

        $service = new VanityTransferService($mockSleeper);
        $result = $service->transfer(
            source: $source,
            target: $target,
            sourceAppName: 'termapi',
            sourceEnvName: 'production',
            targetAppName: 'termapi',
            targetEnvName: 'production',
            vanity: 'termapi',
            maxAttempts: 5,
            initialDelayMs: 500,
            backoffMultiplier: 2.0,
            maxDelayMs: 3000,
        );

        expect($result->success)->toBeTrue()
            ->and($result->status)->toBe('transferred')
            ->and($result->attempts)->toBe(3)
            ->and($sleepCalls)->toHaveCount(2)
            ->and($sleepCalls[0])->toBe(500000) // 500ms
            ->and($sleepCalls[1])->toBe(1000000); // 1000ms (500 * 2.0)
    });

    test('transfer with delete-source deletes source environment for instant release', function () {
        $source = Mockery::mock(CloudApiClient::class);
        $source->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'src-app-1', 'attributes' => ['name' => 'termapi', 'slug' => 'termapi']],
        ]);
        $source->shouldReceive('getAll')->with('applications/src-app-1/environments')->andReturn([
            ['id' => 'src-env-1', 'attributes' => ['name' => 'production', 'vanity_domain' => 'termapi.laravel.cloud']],
        ]);
        // Must delete source environment
        $source->shouldReceive('delete')
            ->once()
            ->with('environments/src-env-1');

        $target = Mockery::mock(CloudApiClient::class);
        $target->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'tgt-app-1', 'attributes' => ['name' => 'termapi', 'slug' => 'termapi-target']],
        ]);
        $target->shouldReceive('getAll')->with('applications/tgt-app-1/environments')->andReturn([
            ['id' => 'tgt-env-1', 'attributes' => ['name' => 'production', 'vanity_domain' => null]],
        ]);
        $target->shouldReceive('put')
            ->once()
            ->with('environments/tgt-env-1/vanity-domain', ['name' => 'termapi'])
            ->andReturn(['data' => ['id' => 'tgt-env-1', 'attributes' => ['vanity_domain' => 'termapi.laravel.cloud']]]);

        $service = new VanityTransferService;
        $result = $service->transfer(
            source: $source,
            target: $target,
            sourceAppName: 'termapi',
            sourceEnvName: 'production',
            targetAppName: 'termapi',
            targetEnvName: 'production',
            vanity: 'termapi',
            deleteSource: true,
        );

        expect($result->success)->toBeTrue()
            ->and($result->status)->toBe('transferred')
            ->and($result->releasedAs)->toBe('deleted');
    });

    test('transfer rolls back to source environment when max attempts are exhausted on cooldown lock', function () {
        $source = Mockery::mock(CloudApiClient::class);
        $source->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'src-app-1', 'attributes' => ['name' => 'termapi', 'slug' => 'termapi']],
        ]);
        $source->shouldReceive('getAll')->with('applications/src-app-1/environments')->andReturn([
            ['id' => 'src-env-1', 'attributes' => ['name' => 'production', 'vanity_domain' => 'termapi.laravel.cloud']],
        ]);
        // Release rename
        $source->shouldReceive('put')
            ->once()
            ->withArgs(fn ($path, $data) => $path === 'environments/src-env-1/vanity-domain' && str_starts_with($data['name'], 'termapi-archived-'))
            ->andReturn(['data' => []]);

        // Rollback call on source
        $source->shouldReceive('put')
            ->once()
            ->with('environments/src-env-1/vanity-domain', ['name' => 'termapi'])
            ->andReturn(['data' => ['id' => 'src-env-1', 'attributes' => ['vanity_domain' => 'termapi.laravel.cloud']]]);

        $target = Mockery::mock(CloudApiClient::class);
        $target->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'tgt-app-1', 'attributes' => ['name' => 'termapi', 'slug' => 'termapi-target']],
        ]);
        $target->shouldReceive('getAll')->with('applications/tgt-app-1/environments')->andReturn([
            ['id' => 'tgt-env-1', 'attributes' => ['name' => 'production', 'vanity_domain' => null]],
        ]);
        // Always fails with 422
        $target->shouldReceive('put')
            ->with('environments/tgt-env-1/vanity-domain', ['name' => 'termapi'])
            ->times(3)
            ->andThrow(new RuntimeException('API Error [422]: This Laravel Cloud domain is already taken.', 422));

        $mockSleeper = fn (int $us) => null;
        $service = new VanityTransferService($mockSleeper);

        $result = $service->transfer(
            source: $source,
            target: $target,
            sourceAppName: 'termapi',
            sourceEnvName: 'production',
            targetAppName: 'termapi',
            targetEnvName: 'production',
            vanity: 'termapi',
            maxAttempts: 3,
        );

        expect($result->success)->toBeFalse()
            ->and($result->status)->toBe('rolled_back')
            ->and($result->rolledBack)->toBeTrue()
            ->and($result->attempts)->toBe(3)
            ->and($result->message)->toContain('Safety rollback restored');
    });

    test('transfer fails with clear message when source application does not exist', function () {
        $source = Mockery::mock(CloudApiClient::class);
        $source->shouldReceive('getAll')->with('applications')->andReturn([]);

        $target = Mockery::mock(CloudApiClient::class);

        $service = new VanityTransferService;
        $result = $service->transfer(
            source: $source,
            target: $target,
            sourceAppName: 'non-existent-app',
            sourceEnvName: 'production',
        );

        expect($result->success)->toBeFalse()
            ->and($result->status)->toBe('failed')
            ->and($result->message)->toContain('Source application "non-existent-app" not found');
    });

    test('transfer fails when source environment has no vanity domain and none was specified', function () {
        $source = Mockery::mock(CloudApiClient::class);
        $source->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'src-app-1', 'attributes' => ['name' => 'termapi', 'slug' => 'termapi']],
        ]);
        $source->shouldReceive('getAll')->with('applications/src-app-1/environments')->andReturn([
            ['id' => 'src-env-1', 'attributes' => ['name' => 'production', 'vanity_domain' => null]],
        ]);

        $target = Mockery::mock(CloudApiClient::class);
        $target->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'tgt-app-1', 'attributes' => ['name' => 'termapi', 'slug' => 'termapi']],
        ]);
        $target->shouldReceive('getAll')->with('applications/tgt-app-1/environments')->andReturn([
            ['id' => 'tgt-env-1', 'attributes' => ['name' => 'production']],
        ]);

        $service = new VanityTransferService;
        $result = $service->transfer(
            source: $source,
            target: $target,
            sourceAppName: 'termapi',
            sourceEnvName: 'production',
            targetAppName: 'termapi',
            targetEnvName: 'production',
            vanity: null,
        );

        expect($result->success)->toBeFalse()
            ->and($result->status)->toBe('failed')
            ->and($result->message)->toContain('has no vanity domain configured');
    });
});
