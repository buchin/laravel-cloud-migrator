<?php

use App\Commands\DecommissionCommand;
use App\Services\CloudApiClient;

describe('DecommissionCommand Feature', function () {
    test('org:teardown fails with safety violation when source and target tokens are identical', function () {
        $this->artisan('org:teardown', [
            '--source-token' => 'same-token',
            '--target-token' => 'same-token',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Source and target tokens must be distinct')
            ->assertExitCode(DecommissionCommand::FAILURE);
    });

    test('org:decommission alias also fails with safety violation when tokens are identical', function () {
        $this->artisan('org:decommission', [
            '--source-token' => 'same-token',
            '--target-token' => 'same-token',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Source and target tokens must be distinct')
            ->assertExitCode(DecommissionCommand::FAILURE);
    });

    test('fails with safety violation when target organization has no applications', function () {
        $mockSource = Mockery::mock(CloudApiClient::class);
        $mockSource->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'src-app-1', 'attributes' => ['name' => 'demo-app', 'slug' => 'demo-app']],
        ]);

        $mockTarget = Mockery::mock(CloudApiClient::class);
        $mockTarget->shouldReceive('getAll')->with('applications')->andReturn([]);

        $this->app->instance('CloudApiClient.src-token', $mockSource);
        $this->app->instance('CloudApiClient.tgt-token', $mockTarget);

        $this->artisan('org:teardown', [
            '--source-token' => 'src-token',
            '--target-token' => 'tgt-token',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Target organization has no applications')
            ->assertExitCode(DecommissionCommand::FAILURE);
    });

    test('fails with safety violation if source app id exists in target organization', function () {
        $mockSource = Mockery::mock(CloudApiClient::class);
        $mockSource->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'shared-app-id', 'attributes' => ['name' => 'demo-app', 'slug' => 'demo-app']],
        ]);

        $mockTarget = Mockery::mock(CloudApiClient::class);
        $mockTarget->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'shared-app-id', 'attributes' => ['name' => 'demo-app', 'slug' => 'demo-app']],
        ]);
        $mockTarget->shouldReceive('getAll')->with('databases/clusters')->andReturn([]);
        $mockTarget->shouldReceive('getAll')->with('caches')->andReturn([]);

        $this->app->instance('CloudApiClient.src-token', $mockSource);
        $this->app->instance('CloudApiClient.tgt-token', $mockTarget);

        $this->artisan('org:teardown', [
            '--source-token' => 'src-token',
            '--target-token' => 'tgt-token',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Safety Violation: App demo-app ID shared-app-id detected in target org')
            ->assertExitCode(DecommissionCommand::FAILURE);
    });

    test('fails in non-interactive mode without --confirm flag', function () {
        $mockSource = Mockery::mock(CloudApiClient::class);
        $mockSource->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'src-app-1', 'attributes' => ['name' => 'demo-app', 'slug' => 'demo-app']],
        ]);

        $mockTarget = Mockery::mock(CloudApiClient::class);
        $mockTarget->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'tgt-app-1', 'attributes' => ['name' => 'demo-app', 'slug' => 'demo-app']],
        ]);
        $mockTarget->shouldReceive('getAll')->with('databases/clusters')->andReturn([]);
        $mockTarget->shouldReceive('getAll')->with('caches')->andReturn([]);

        $this->app->instance('CloudApiClient.src-token', $mockSource);
        $this->app->instance('CloudApiClient.tgt-token', $mockTarget);

        $this->artisan('org:teardown', [
            '--source-token' => 'src-token',
            '--target-token' => 'tgt-token',
            '--yes' => true,
            '--skip-health-check' => true,
        ])
            ->expectsOutputToContain('Non-interactive execution (--yes) requires explicit typing flag --confirm="TEARDOWN-SOURCE"')
            ->assertExitCode(DecommissionCommand::FAILURE);
    });

    test('fails when typed confirmation phrase does not match TEARDOWN-SOURCE', function () {
        $mockSource = Mockery::mock(CloudApiClient::class);
        $mockSource->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'src-app-1', 'attributes' => ['name' => 'demo-app', 'slug' => 'demo-app']],
        ]);

        $mockTarget = Mockery::mock(CloudApiClient::class);
        $mockTarget->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'tgt-app-1', 'attributes' => ['name' => 'demo-app', 'slug' => 'demo-app']],
        ]);
        $mockTarget->shouldReceive('getAll')->with('databases/clusters')->andReturn([]);
        $mockTarget->shouldReceive('getAll')->with('caches')->andReturn([]);

        $this->app->instance('CloudApiClient.src-token', $mockSource);
        $this->app->instance('CloudApiClient.tgt-token', $mockTarget);

        $this->artisan('org:teardown', [
            '--source-token' => 'src-token',
            '--target-token' => 'tgt-token',
            '--yes' => true,
            '--confirm' => 'WRONG-PHRASE',
            '--skip-health-check' => true,
        ])
            ->expectsOutputToContain('Non-interactive execution (--yes) requires explicit typing flag --confirm="TEARDOWN-SOURCE"')
            ->assertExitCode(DecommissionCommand::FAILURE);
    });

    test('dry-run outputs decommission plan without deleting anything', function () {
        $mockSource = Mockery::mock(CloudApiClient::class);
        $mockSource->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'src-app-1', 'attributes' => ['name' => 'demo-app', 'slug' => 'demo-app']],
        ]);
        $mockSource->shouldReceive('getAll')->with('databases/clusters')->andReturn([
            ['id' => 'src-db-1', 'attributes' => ['name' => 'demo-db']],
        ]);
        $mockSource->shouldReceive('getAll')->with('caches')->andReturn([
            ['id' => 'src-cache-1', 'attributes' => ['name' => 'demo-cache']],
        ]);
        $mockSource->shouldNotReceive('delete');

        $mockTarget = Mockery::mock(CloudApiClient::class);
        $mockTarget->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'tgt-app-1', 'attributes' => ['name' => 'demo-app', 'slug' => 'demo-app']],
        ]);
        $mockTarget->shouldReceive('getAll')->with('databases/clusters')->andReturn([
            ['id' => 'tgt-db-1', 'attributes' => ['name' => 'demo-db']],
        ]);
        $mockTarget->shouldReceive('getAll')->with('caches')->andReturn([
            ['id' => 'tgt-cache-1', 'attributes' => ['name' => 'demo-cache']],
        ]);

        $this->app->instance('CloudApiClient.src-token', $mockSource);
        $this->app->instance('CloudApiClient.tgt-token', $mockTarget);

        $this->artisan('org:teardown', [
            '--source-token' => 'src-token',
            '--target-token' => 'tgt-token',
            '--all' => true,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Production Source Teardown Plan')
            ->expectsOutputToContain('Dry run complete — no changes were made')
            ->assertExitCode(DecommissionCommand::SUCCESS);
    });

    test('executes controlled teardown of apps, clusters, and caches when double confirmed', function () {
        $mockSource = Mockery::mock(CloudApiClient::class);
        $mockSource->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'src-app-1', 'attributes' => ['name' => 'demo-app', 'slug' => 'demo-app']],
        ]);
        $mockSource->shouldReceive('getAll')->with('databases/clusters')->andReturn([
            ['id' => 'src-db-1', 'attributes' => ['name' => 'demo-db']],
        ]);
        $mockSource->shouldReceive('getAll')->with('databases/clusters/src-db-1/databases')->andReturn([
            ['id' => 'src-schema-1', 'attributes' => ['name' => 'main']],
        ]);
        $mockSource->shouldReceive('getAll')->with('caches')->andReturn([
            ['id' => 'src-cache-1', 'attributes' => ['name' => 'demo-cache']],
        ]);

        // Expectations: deletion on source only
        $mockSource->shouldReceive('delete')->with('applications/src-app-1')->once();
        $mockSource->shouldReceive('delete')->with('databases/clusters/src-db-1/databases/src-schema-1')->once();
        $mockSource->shouldReceive('delete')->with('databases/clusters/src-db-1')->once();
        $mockSource->shouldReceive('delete')->with('caches/src-cache-1')->once();

        $mockTarget = Mockery::mock(CloudApiClient::class);
        $mockTarget->shouldReceive('getAll')->with('applications')->andReturn([
            ['id' => 'tgt-app-1', 'attributes' => ['name' => 'demo-app', 'slug' => 'demo-app']],
        ]);
        $mockTarget->shouldReceive('getAll')->with('databases/clusters')->andReturn([]);
        $mockTarget->shouldReceive('getAll')->with('caches')->andReturn([]);
        // Target must never receive any delete call!
        $mockTarget->shouldNotReceive('delete');

        $this->app->instance('CloudApiClient.src-token', $mockSource);
        $this->app->instance('CloudApiClient.tgt-token', $mockTarget);

        $this->artisan('org:teardown', [
            '--source-token' => 'src-token',
            '--target-token' => 'tgt-token',
            '--all' => true,
            '--yes' => true,
            '--confirm' => 'TEARDOWN-SOURCE',
            '--skip-health-check' => true,
        ])
            ->expectsOutputToContain('Deleted source application: demo-app')
            ->expectsOutputToContain('Deleted source database cluster: demo-db')
            ->expectsOutputToContain('Deleted source cache cluster: demo-cache')
            ->expectsOutputToContain('Source organization successfully decommissioned')
            ->assertExitCode(DecommissionCommand::SUCCESS);
    });
});
