<?php

use App\Data\ConfigAuditItem;
use App\Data\DatabaseAuditItem;
use App\Data\HealthCheckItem;
use App\Data\OrgAuditResult;

describe('OrgAuditResult Unit', function () {
    test('calculates summary counts and statuses correctly', function () {
        $health = [
            new HealthCheckItem('App1', 'app1', 'prod', 'prod', 'https://app1.test', 200, status: 'healthy'),
            new HealthCheckItem('App2', 'app2', 'prod', 'prod', 'https://app2.test', 301, status: 'redirect'),
            new HealthCheckItem('App3', 'app3', 'prod', 'prod', 'https://app3.test', 500, status: 'unhealthy'),
        ];

        $db = [
            new DatabaseAuditItem('cluster1', 'schema1', 'users', 100, 100, status: 'matched'),
            new DatabaseAuditItem('cluster1', 'schema1', 'sessions', 50, 10, policy: 'transient', status: 'transient'),
            new DatabaseAuditItem('cluster1', 'schema1', 'orders', 200, 0, status: 'drift'),
        ];

        $config = [
            new ConfigAuditItem('App1', 'prod', 'API_URL', 'https://api.test', 'https://api.test', status: 'matched'),
            new ConfigAuditItem('App1', 'prod', 'SECRET_KEY', 'secret', null, status: 'missing'),
        ];

        $result = new OrgAuditResult(
            healthItems: $health,
            databaseItems: $db,
            configItems: $config,
            durationSeconds: 1.5,
        );

        expect($result->totalHealthChecks())->toBe(3);
        expect($result->healthyHealthChecks())->toBe(1);
        expect($result->failedHealthChecks())->toBe(1);
        expect($result->warningHealthChecks())->toBe(1);

        expect($result->totalDatabaseChecks())->toBe(3);
        expect($result->matchedDatabaseChecks())->toBe(2);
        expect($result->driftDatabaseChecks())->toBe(1);

        expect($result->totalConfigChecks())->toBe(2);
        expect($result->matchedConfigChecks())->toBe(1);
        expect($result->driftConfigChecks())->toBe(1);

        expect($result->totalChecks())->toBe(8);
        expect($result->totalPassed())->toBe(4);
        expect($result->totalDrifts())->toBe(3); // 1 health + 1 db + 1 config
        expect($result->totalWarnings())->toBe(1); // 1 health

        expect($result->isClean())->toBeFalse();
        expect($result->hasWarnings())->toBeTrue();
        expect($result->overallStatus())->toBe('drift_detected');
    });

    test('clean result returns isClean true and healthy overall status', function () {
        $health = [
            new HealthCheckItem('App1', 'app1', 'prod', 'prod', 'https://app1.test', 200, status: 'healthy'),
        ];
        $db = [
            new DatabaseAuditItem('cluster1', 'schema1', 'users', 100, 100, status: 'matched'),
        ];
        $config = [
            new ConfigAuditItem('App1', 'prod', 'API_URL', 'https://api.test', 'https://api.test', status: 'matched'),
        ];

        $result = new OrgAuditResult($health, $db, $config, 0.5);

        expect($result->isClean())->toBeTrue();
        expect($result->hasWarnings())->toBeFalse();
        expect($result->overallStatus())->toBe('healthy');
    });

    test('serializes to array and json properly', function () {
        $result = new OrgAuditResult(
            healthItems: [
                new HealthCheckItem('App1', 'app1', 'prod', 'prod', 'https://app1.test', 200, status: 'healthy'),
            ],
            databaseItems: [
                new DatabaseAuditItem('cluster1', 'schema1', 'users', 10, 10, status: 'matched'),
            ],
            configItems: [
                new ConfigAuditItem('App1', 'prod', 'KEY', 'val', 'val', status: 'matched'),
            ],
            durationSeconds: 0.75,
            metadata: ['version' => '1.0'],
        );

        $arr = $result->toArray();
        expect($arr)->toHaveKeys(['timestamp', 'duration_seconds', 'status', 'is_clean', 'summary', 'health_check', 'database_audit', 'config_audit', 'metadata']);
        expect($arr['summary']['total_checks'])->toBe(3);
        expect($arr['summary']['passed'])->toBe(3);
        expect($arr['summary']['drifts'])->toBe(0);

        $json = $result->toJson();
        expect($json)->toBeString();
        $decoded = json_decode($json, true);
        expect($decoded['status'])->toBe('healthy');
        expect($decoded['metadata']['version'])->toBe('1.0');
    });
});
