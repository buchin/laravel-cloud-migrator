<?php

use App\Commands\AuditOrgCommand;
use App\Data\ConfigAuditItem;
use App\Data\DatabaseAuditItem;
use App\Data\HealthCheckItem;
use App\Data\OrgAuditResult;
use App\Services\OrgAuditService;

describe('AuditOrgCommand Feature', function () {
    test('fails with clear error when manifest file does not exist', function () {
        $this->artisan('org:audit', [
            '--manifest' => '/nonexistent/path/manifest.json',
            '--target-token' => 'test-token',
            '--yes' => true,
        ])
            ->expectsOutputToContain('Specified manifest file does not exist')
            ->assertExitCode(AuditOrgCommand::FAILURE);
    });

    test('fails when target token is missing in non-interactive mode', function () {
        putenv('CLOUD_TARGET_TOKEN=');
        unset($_ENV['CLOUD_TARGET_TOKEN']);

        $this->artisan('org:audit', [
            '--yes' => true,
        ])
            ->expectsOutputToContain('Target organization API token is required')
            ->assertExitCode(AuditOrgCommand::FAILURE);
    });

    test('executes clean audit with 0 drift and returns exit code 0', function () {
        $mockService = Mockery::mock(OrgAuditService::class);

        $cleanResult = new OrgAuditResult(
            healthItems: [
                new HealthCheckItem(
                    appName: 'Dojo',
                    appSlug: 'dojo',
                    envName: 'production',
                    envSlug: 'production',
                    url: 'https://dojo.laravel.cloud',
                    statusCode: 200,
                    responseTimeMs: 30,
                    status: 'healthy',
                ),
            ],
            databaseItems: [
                new DatabaseAuditItem(
                    cluster: 'dojo',
                    schema: 'main',
                    table: 'users',
                    sourceCount: 1500,
                    targetCount: 1500,
                    policy: 'full',
                    status: 'matched',
                ),
            ],
            configItems: [
                new ConfigAuditItem(
                    appName: 'dracin',
                    envName: 'main',
                    variableKey: 'API_BASE_URL',
                    expectedValue: 'https://dracin-api.laravel.cloud/api',
                    actualValue: 'https://dracin-api.laravel.cloud/api',
                    status: 'matched',
                ),
            ],
            durationSeconds: 0.85,
        );

        $mockService->shouldReceive('runAudit')
            ->once()
            ->andReturn($cleanResult);

        $this->app->instance(OrgAuditService::class, $mockService);

        $this->artisan('org:audit', [
            '--target-token' => 'valid-token',
            '--yes' => true,
        ])
            ->expectsOutputToContain('AUTOMATED AUDIT & ENVIRONMENT DRIFT DETECTION RUNNER')
            ->expectsOutputToContain('1. HTTP Health Checks')
            ->expectsOutputToContain('2. Database Schema & Data Parity')
            ->expectsOutputToContain('3. Environment Configuration & Cross-App URLs')
            ->expectsOutputToContain('AUDIT SUMMARY')
            ->expectsOutputToContain('Audit passed: 3/3 check(s) clean and healthy with 0 drift detected.')
            ->assertExitCode(AuditOrgCommand::SUCCESS);
    });

    test('detects drift and unhealthy checks and returns exit code 1', function () {
        $mockService = Mockery::mock(OrgAuditService::class);

        $driftResult = new OrgAuditResult(
            healthItems: [
                new HealthCheckItem(
                    appName: 'Dojo',
                    appSlug: 'dojo',
                    envName: 'production',
                    envSlug: 'production',
                    url: 'https://dojo.laravel.cloud',
                    statusCode: 500,
                    status: 'unhealthy',
                ),
            ],
            databaseItems: [
                new DatabaseAuditItem(
                    cluster: 'dojo',
                    schema: 'main',
                    table: 'episodes',
                    sourceCount: 5000,
                    targetCount: 0,
                    status: 'drift',
                    message: 'Target table is empty',
                ),
            ],
            configItems: [
                new ConfigAuditItem(
                    appName: 'dracin',
                    envName: 'main',
                    variableKey: 'API_BASE_URL',
                    expectedValue: 'https://dracin-api.laravel.cloud/api',
                    actualValue: 'https://old-source.com/api',
                    status: 'drift',
                ),
            ],
            durationSeconds: 1.2,
        );

        $mockService->shouldReceive('runAudit')
            ->once()
            ->andReturn($driftResult);

        $this->app->instance(OrgAuditService::class, $mockService);

        $this->artisan('org:audit', [
            '--target-token' => 'valid-token',
            '--yes' => true,
        ])
            ->expectsOutputToContain('DRIFT DETECTED')
            ->expectsOutputToContain('Audit failed: 3 drift/unhealthy check(s) detected.')
            ->assertExitCode(AuditOrgCommand::FAILURE);
    });

    test('exports audit report to JSON file via --json option', function () {
        $mockService = Mockery::mock(OrgAuditService::class);
        $cleanResult = new OrgAuditResult(
            healthItems: [
                new HealthCheckItem('Dojo', 'dojo', 'prod', 'prod', 'https://dojo.laravel.cloud', 200, status: 'healthy'),
            ],
            databaseItems: [],
            configItems: [],
            durationSeconds: 0.45,
        );

        $mockService->shouldReceive('runAudit')->once()->andReturn($cleanResult);
        $this->app->instance(OrgAuditService::class, $mockService);

        $tempJson = sys_get_temp_dir().'/test-audit-report-'.uniqid().'.json';

        $this->artisan('org:audit', [
            '--target-token' => 'valid-token',
            '--json' => $tempJson,
            '--yes' => true,
        ])
            ->expectsOutputToContain("Exported audit report to {$tempJson}")
            ->assertExitCode(AuditOrgCommand::SUCCESS);

        expect(file_exists($tempJson))->toBeTrue();
        $content = json_decode(file_get_contents($tempJson), true);
        expect($content['status'])->toBe('healthy');
        expect($content['summary']['total_checks'])->toBe(1);
        expect($content['summary']['drifts'])->toBe(0);

        @unlink($tempJson);
    });

    test('fails on warnings when --fail-on-warning is provided', function () {
        $mockService = Mockery::mock(OrgAuditService::class);

        $warningResult = new OrgAuditResult(
            healthItems: [
                new HealthCheckItem('App1', 'app1', 'prod', 'prod', 'https://app1.test', 301, status: 'redirect'),
            ],
            databaseItems: [
                new DatabaseAuditItem('dojo', 'main', 'extra_tbl', null, 5, status: 'warning', message: 'Only in target'),
            ],
            configItems: [],
            durationSeconds: 0.5,
        );

        $mockService->shouldReceive('runAudit')
            ->twice()
            ->andReturn($warningResult);

        $this->app->instance(OrgAuditService::class, $mockService);

        // Without --fail-on-warning: passes with exit code 0
        $this->artisan('org:audit', [
            '--target-token' => 'valid-token',
            '--yes' => true,
        ])
            ->assertExitCode(AuditOrgCommand::SUCCESS);

        // With --fail-on-warning: fails with exit code 1
        $this->artisan('org:audit', [
            '--target-token' => 'valid-token',
            '--fail-on-warning' => true,
            '--yes' => true,
        ])
            ->expectsOutputToContain('Audit completed with 2 warning(s) and --fail-on-warning is enabled.')
            ->assertExitCode(AuditOrgCommand::FAILURE);
    });
});
