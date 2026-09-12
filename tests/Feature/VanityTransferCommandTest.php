<?php

use App\Commands\VanityTransferCommand;
use App\Services\CloudApiClient;
use App\Services\VanityTransferService;

describe('VanityTransferCommand Feature', function () {
    test('executes transfer successfully with explicit vanity and --yes flag', function () {
        $mockSource = Mockery::mock(CloudApiClient::class);
        $mockSource->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'src-app-1', 'attributes' => ['name' => 'termapi', 'slug' => 'termapi']],
        ]);
        $mockSource->shouldReceive('getAll')->with('applications/src-app-1/environments')->andReturn([
            ['id' => 'src-env-1', 'attributes' => ['name' => 'production', 'slug' => 'production', 'vanity_domain' => 'termapi.laravel.cloud']],
        ]);
        $mockSource->shouldReceive('put')->once()->andReturn(['data' => []]);

        $mockTarget = Mockery::mock(CloudApiClient::class);
        $mockTarget->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'tgt-app-1', 'attributes' => ['name' => 'termapi', 'slug' => 'termapi-tgt']],
        ]);
        $mockTarget->shouldReceive('getAll')->with('applications/tgt-app-1/environments')->andReturn([
            ['id' => 'tgt-env-1', 'attributes' => ['name' => 'production', 'slug' => 'production', 'vanity_domain' => 'temp.laravel.cloud']],
        ]);
        $mockTarget->shouldReceive('put')->once()->with('environments/tgt-env-1/vanity-domain', ['name' => 'termapi'])->andReturn([
            'data' => ['id' => 'tgt-env-1', 'attributes' => ['vanity_domain' => 'termapi.laravel.cloud']],
        ]);

        $this->app->instance('CloudApiClient.src-token', $mockSource);
        $this->app->instance('CloudApiClient.tgt-token', $mockTarget);

        $this->artisan('vanity:transfer', [
            '--source-token' => 'src-token',
            '--target-token' => 'tgt-token',
            '--source-app' => 'termapi',
            '--source-env' => 'production',
            '--target-app' => 'termapi',
            '--target-env' => 'production',
            '--vanity' => 'termapi',
            '--yes' => true,
        ])
            ->expectsOutputToContain('Vanity Domain Transfer Plan')
            ->expectsOutputToContain('Executing Transfer Pipeline')
            ->expectsOutputToContain('SUCCESS:')
            ->assertExitCode(VanityTransferCommand::SUCCESS);
    });

    test('auto-detects vanity from source environment when --vanity option is omitted', function () {
        $mockSource = Mockery::mock(CloudApiClient::class);
        $mockSource->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'src-app-1', 'attributes' => ['name' => 'dracin-api', 'slug' => 'dracin-api']],
        ]);
        $mockSource->shouldReceive('getAll')->with('applications/src-app-1/environments')->andReturn([
            ['id' => 'src-env-1', 'attributes' => ['name' => 'production', 'slug' => 'production', 'vanity_domain' => 'dracin-api.laravel.cloud']],
        ]);
        $mockSource->shouldReceive('put')->once()->andReturn(['data' => []]);

        $mockTarget = Mockery::mock(CloudApiClient::class);
        $mockTarget->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'tgt-app-1', 'attributes' => ['name' => 'dracin-api', 'slug' => 'dracin-api-tgt']],
        ]);
        $mockTarget->shouldReceive('getAll')->with('applications/tgt-app-1/environments')->andReturn([
            ['id' => 'tgt-env-1', 'attributes' => ['name' => 'production', 'slug' => 'production', 'vanity_domain' => null]],
        ]);
        $mockTarget->shouldReceive('put')->once()->with('environments/tgt-env-1/vanity-domain', ['name' => 'dracin-api'])->andReturn([
            'data' => ['id' => 'tgt-env-1', 'attributes' => ['vanity_domain' => 'dracin-api.laravel.cloud']],
        ]);

        $this->app->instance('CloudApiClient.src-token', $mockSource);
        $this->app->instance('CloudApiClient.tgt-token', $mockTarget);

        $this->artisan('vanity:transfer', [
            '--source-token' => 'src-token',
            '--target-token' => 'tgt-token',
            '--source-app' => 'dracin-api',
            '--yes' => true,
        ])
            ->expectsOutputToContain('Vanity Domain Transfer Plan')
            ->expectsOutputToContain('Executing Transfer Pipeline')
            ->expectsOutputToContain('SUCCESS:')
            ->assertExitCode(VanityTransferCommand::SUCCESS);
    });

    test('supports --delete-source flag for instant release via deletion', function () {
        $mockSource = Mockery::mock(CloudApiClient::class);
        $mockSource->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'src-app-1', 'attributes' => ['name' => 'termapi', 'slug' => 'termapi']],
        ]);
        $mockSource->shouldReceive('getAll')->with('applications/src-app-1/environments')->andReturn([
            ['id' => 'src-env-1', 'attributes' => ['name' => 'production', 'slug' => 'production', 'vanity_domain' => 'termapi.laravel.cloud']],
        ]);
        $mockSource->shouldReceive('delete')->once()->with('environments/src-env-1');

        $mockTarget = Mockery::mock(CloudApiClient::class);
        $mockTarget->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'tgt-app-1', 'attributes' => ['name' => 'termapi', 'slug' => 'termapi']],
        ]);
        $mockTarget->shouldReceive('getAll')->with('applications/tgt-app-1/environments')->andReturn([
            ['id' => 'tgt-env-1', 'attributes' => ['name' => 'production', 'slug' => 'production', 'vanity_domain' => null]],
        ]);
        $mockTarget->shouldReceive('put')->once()->with('environments/tgt-env-1/vanity-domain', ['name' => 'termapi'])->andReturn([
            'data' => ['id' => 'tgt-env-1'],
        ]);

        $this->app->instance('CloudApiClient.src-token', $mockSource);
        $this->app->instance('CloudApiClient.tgt-token', $mockTarget);

        $this->artisan('vanity:transfer', [
            '--source-token' => 'src-token',
            '--target-token' => 'tgt-token',
            '--source-app' => 'termapi',
            '--delete-source' => true,
            '--yes' => true,
        ])
            ->expectsOutputToContain('Delete Source Env (Instant)')
            ->expectsOutputToContain('Source environment deleted. Vanity domain released globally.')
            ->assertExitCode(VanityTransferCommand::SUCCESS);
    });

    test('displays collision warning when requested vanity differs from source active vanity', function () {
        $mockSource = Mockery::mock(CloudApiClient::class);
        $mockSource->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'src-app-1', 'attributes' => ['name' => 'termapi', 'slug' => 'termapi']],
        ]);
        $mockSource->shouldReceive('getAll')->with('applications/src-app-1/environments')->andReturn([
            ['id' => 'src-env-1', 'attributes' => ['name' => 'production', 'slug' => 'production', 'vanity_domain' => 'other-vanity.laravel.cloud']],
        ]);
        $mockSource->shouldReceive('put')->once()->andReturn(['data' => []]);

        $mockTarget = Mockery::mock(CloudApiClient::class);
        $mockTarget->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'tgt-app-1', 'attributes' => ['name' => 'termapi', 'slug' => 'termapi']],
        ]);
        $mockTarget->shouldReceive('getAll')->with('applications/tgt-app-1/environments')->andReturn([
            ['id' => 'tgt-env-1', 'attributes' => ['name' => 'production', 'slug' => 'production', 'vanity_domain' => null]],
        ]);
        $mockTarget->shouldReceive('put')->once()->andReturn(['data' => []]);

        $this->app->instance('CloudApiClient.src-token', $mockSource);
        $this->app->instance('CloudApiClient.tgt-token', $mockTarget);

        $this->artisan('vanity:transfer', [
            '--source-token' => 'src-token',
            '--target-token' => 'tgt-token',
            '--source-app' => 'termapi',
            '--vanity' => 'different-vanity',
            '--yes' => true,
        ])
            ->expectsOutputToContain('Collision Alert')
            ->expectsOutputToContain('which differs from requested')
            ->assertExitCode(VanityTransferCommand::SUCCESS);
    });

    test('returns success early if target environment already has the requested vanity', function () {
        $mockSource = Mockery::mock(CloudApiClient::class);
        $mockSource->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'src-app-1', 'attributes' => ['name' => 'termapi', 'slug' => 'termapi']],
        ]);
        $mockSource->shouldReceive('getAll')->with('applications/src-app-1/environments')->andReturn([
            ['id' => 'src-env-1', 'attributes' => ['name' => 'production', 'slug' => 'production', 'vanity_domain' => 'termapi.laravel.cloud']],
        ]);

        $mockTarget = Mockery::mock(CloudApiClient::class);
        $mockTarget->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'tgt-app-1', 'attributes' => ['name' => 'termapi', 'slug' => 'termapi']],
        ]);
        $mockTarget->shouldReceive('getAll')->with('applications/tgt-app-1/environments')->andReturn([
            ['id' => 'tgt-env-1', 'attributes' => ['name' => 'production', 'slug' => 'production', 'vanity_domain' => 'termapi.laravel.cloud']],
        ]);

        $this->app->instance('CloudApiClient.src-token', $mockSource);
        $this->app->instance('CloudApiClient.tgt-token', $mockTarget);

        $this->artisan('vanity:transfer', [
            '--source-token' => 'src-token',
            '--target-token' => 'tgt-token',
            '--source-app' => 'termapi',
            '--vanity' => 'termapi',
            '--yes' => true,
        ])
            ->expectsOutputToContain('Target environment already possesses vanity domain: termapi.laravel.cloud')
            ->assertExitCode(VanityTransferCommand::SUCCESS);
    });

    test('fails with rollback notice when target claim times out on reservation lock', function () {
        $mockSource = Mockery::mock(CloudApiClient::class);
        $mockSource->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'src-app-1', 'attributes' => ['name' => 'termapi', 'slug' => 'termapi']],
        ]);
        $mockSource->shouldReceive('getAll')->with('applications/src-app-1/environments')->andReturn([
            ['id' => 'src-env-1', 'attributes' => ['name' => 'production', 'slug' => 'production', 'vanity_domain' => 'termapi.laravel.cloud']],
        ]);
        // Release rename
        $mockSource->shouldReceive('put')->once()->andReturn(['data' => []]);
        // Rollback call
        $mockSource->shouldReceive('put')->once()->with('environments/src-env-1/vanity-domain', ['name' => 'termapi'])->andReturn(['data' => []]);

        $mockTarget = Mockery::mock(CloudApiClient::class);
        $mockTarget->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'tgt-app-1', 'attributes' => ['name' => 'termapi', 'slug' => 'termapi']],
        ]);
        $mockTarget->shouldReceive('getAll')->with('applications/tgt-app-1/environments')->andReturn([
            ['id' => 'tgt-env-1', 'attributes' => ['name' => 'production', 'slug' => 'production', 'vanity_domain' => null]],
        ]);
        // Fail claim with 422
        $mockTarget->shouldReceive('put')->andThrow(new RuntimeException('API Error [422]: This Laravel Cloud domain is already taken.', 422));

        $this->app->instance('CloudApiClient.src-token', $mockSource);
        $this->app->instance('CloudApiClient.tgt-token', $mockTarget);

        // Inject zero-delay service for fast test execution
        $fastService = new VanityTransferService(fn ($us) => null);
        $this->app->instance(VanityTransferService::class, $fastService);

        $this->artisan('vanity:transfer', [
            '--source-token' => 'src-token',
            '--target-token' => 'tgt-token',
            '--source-app' => 'termapi',
            '--max-retries' => 2,
            '--retry-delay' => 1,
            '--yes' => true,
        ])
            ->expectsOutputToContain('ABORTED WITH ROLLBACK')
            ->expectsOutputToContain('Source environment safely reclaimed its original vanity domain')
            ->assertExitCode(VanityTransferCommand::FAILURE);
    });

    test('fails with safety violation if source and target environment are identical', function () {
        $mockSource = Mockery::mock(CloudApiClient::class);
        $mockSource->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'app-1', 'attributes' => ['name' => 'termapi', 'slug' => 'termapi']],
        ]);
        $mockSource->shouldReceive('getAll')->with('applications/app-1/environments')->andReturn([
            ['id' => 'same-env-id', 'attributes' => ['name' => 'production', 'slug' => 'production', 'vanity_domain' => 'termapi.laravel.cloud']],
        ]);

        $mockTarget = Mockery::mock(CloudApiClient::class);
        $mockTarget->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'app-1', 'attributes' => ['name' => 'termapi', 'slug' => 'termapi']],
        ]);
        $mockTarget->shouldReceive('getAll')->with('applications/app-1/environments')->andReturn([
            ['id' => 'same-env-id', 'attributes' => ['name' => 'production', 'slug' => 'production', 'vanity_domain' => 'termapi.laravel.cloud']],
        ]);

        $this->app->instance('CloudApiClient.src-token', $mockSource);
        $this->app->instance('CloudApiClient.tgt-token', $mockTarget);

        $this->artisan('vanity:transfer', [
            '--source-token' => 'src-token',
            '--target-token' => 'tgt-token',
            '--source-app' => 'termapi',
            '--target-app' => 'termapi',
            '--yes' => true,
        ])
            ->expectsOutputToContain('Safety Violation: Source and target environment are identical')
            ->assertExitCode(VanityTransferCommand::FAILURE);
    });
});
