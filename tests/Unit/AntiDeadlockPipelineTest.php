<?php

use App\Commands\MigrateDbCommand;
use App\Services\CloudApiClient;
use App\Services\MigrationService;
use App\Services\TableChunker;

test('MigrationService builds mysqldump args with permanent anti-deadlock flags', function () {
    $source = Mockery::mock(CloudApiClient::class);
    $target = Mockery::mock(CloudApiClient::class);
    $service = new MigrationService($source, $target);

    $conn = [
        'hostname' => '127.0.0.1',
        'port' => 3306,
        'username' => 'cloud_user',
        'password' => 'secret',
    ];

    $args = $service->buildMysqldumpArgs(
        conn: $conn,
        db: 'production_db',
        table: 'orders',
        where: '`id` < 50000',
        schemaOnly: false,
        optFile: '/tmp/my.cnf'
    );

    // Required anti-deadlock flags
    expect($args)->toContain('--single-transaction');
    expect($args)->toContain('--skip-add-locks');
    expect($args)->toContain('--quick');
    expect($args)->toContain('--compress');

    // Streaming and compatibility flags
    expect($args)->toContain('--max-allowed-packet=64M');
    expect($args)->toContain('--ssl-mode=DISABLED');
    expect($args)->toContain('--compression-algorithms=zlib,uncompressed');
    expect($args)->toContain('--no-create-info');
    expect($args)->toContain('--where=`id` < 50000');
    expect($args)->toContain('--defaults-extra-file=/tmp/my.cnf');
    expect($args)->toContain('production_db');
    expect($args)->toContain('orders');
});

test('MigrationService builds mysqldump args for schema-only with anti-deadlock flags', function () {
    $source = Mockery::mock(CloudApiClient::class);
    $target = Mockery::mock(CloudApiClient::class);
    $service = new MigrationService($source, $target);

    $conn = [
        'hostname' => '127.0.0.1',
        'port' => 3306,
        'username' => 'cloud_user',
        'password' => 'secret',
    ];

    $args = $service->buildMysqldumpArgs(
        conn: $conn,
        db: 'production_db',
        schemaOnly: true
    );

    expect($args)->toContain('--single-transaction');
    expect($args)->toContain('--skip-add-locks');
    expect($args)->toContain('--quick');
    expect($args)->toContain('--compress');
    expect($args)->toContain('--no-data');
    expect($args)->toContain('--add-drop-table');
    expect($args)->not->toContain('--no-create-info');
});

test('MigrationService builds mysql restore args with anti-deadlock and constraint disable settings', function () {
    $source = Mockery::mock(CloudApiClient::class);
    $target = Mockery::mock(CloudApiClient::class);
    $service = new MigrationService($source, $target);

    $conn = [
        'hostname' => '127.0.0.1',
        'port' => 3306,
        'username' => 'target_user',
        'password' => 'target_pass',
    ];

    $args = $service->buildMysqlRestoreArgs(
        conn: $conn,
        db: 'target_db',
        force: true
    );

    expect($args)->toContain('--force');
    expect($args)->toContain('--compress');
    expect($args)->toContain('--max-allowed-packet=64M');
    expect($args)->toContain('--ssl-mode=DISABLED');

    // Verify foreign_key_checks=0 and unique_checks=0 in session init
    $initCommand = null;
    foreach ($args as $arg) {
        if (str_starts_with($arg, '--init-command=')) {
            $initCommand = $arg;
            break;
        }
    }

    expect($initCommand)->not->toBeNull();
    expect($initCommand)->toContain('foreign_key_checks=0');
    expect($initCommand)->toContain('unique_checks=0');
});

test('MigrationService provides anti-deadlock SQL prefix and suffix wrappers', function () {
    $source = Mockery::mock(CloudApiClient::class);
    $target = Mockery::mock(CloudApiClient::class);
    $service = new MigrationService($source, $target);

    $prefix = $service->getAntiDeadlockSqlPrefix();
    $suffix = $service->getAntiDeadlockSqlSuffix();

    expect($prefix)->toContain('SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;');
    expect($prefix)->toContain('SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;');

    expect($suffix)->toContain('SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;');
    expect($suffix)->toContain('SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;');
});

test('MigrationService buildMysqldumpCommand and buildMysqlRestoreCommand escape all arguments', function () {
    $source = Mockery::mock(CloudApiClient::class);
    $target = Mockery::mock(CloudApiClient::class);
    $service = new MigrationService($source, $target);

    $conn = [
        'hostname' => '127.0.0.1',
        'port' => 3306,
        'username' => 'user',
        'password' => 'p@ss with space',
    ];

    $dumpCmd = $service->buildMysqldumpCommand(
        dumpBin: '/usr/bin/mysqldump',
        conn: $conn,
        db: 'mydb',
        table: 'orders',
        where: '`status` = 1'
    );

    expect($dumpCmd)->toContain("'--skip-add-locks'");
    expect($dumpCmd)->toContain("'--quick'");
    expect($dumpCmd)->toContain("'--compress'");
    expect($dumpCmd)->toContain("'--single-transaction'");
    expect($dumpCmd)->toContain("'--where=`status` = 1'");

    $restoreCmd = $service->buildMysqlRestoreCommand(
        importBin: '/usr/bin/mysql',
        conn: $conn,
        db: 'mydb'
    );

    expect($restoreCmd)->toContain("'--compress'");
    expect($restoreCmd)->toContain('foreign_key_checks=0');
    expect($restoreCmd)->toContain('unique_checks=0');
});

test('MigrateDbCommand formatBytes formats byte sizes accurately', function () {
    $cmd = new MigrateDbCommand;

    expect($cmd->formatBytes(500))->toBe('500 B');
    expect($cmd->formatBytes(1024))->toBe('1 KB');
    expect($cmd->formatBytes(1048576))->toBe('1 MB');
    expect($cmd->formatBytes(1073741824))->toBe('1 GB');
    expect($cmd->formatBytes(2500000000))->toBe('2.33 GB');
});

test('MigrateDbCommand detectLargeTables filters only tables exceeding thresholds', function () {
    $cmd = new MigrateDbCommand;

    $mockChunker = Mockery::mock(TableChunker::class);
    $mockChunker->shouldReceive('inspectTables')
        ->andReturn([
            'small_table' => [
                'table' => 'small_table',
                'data_length' => 10 * 1024 * 1024,
                'total_bytes' => 10 * 1024 * 1024,
                'row_count' => 5_000,
                'should_chunk' => false,
            ],
            'huge_table' => [
                'table' => 'huge_table',
                'data_length' => 2 * 1024 * 1024 * 1024,
                'total_bytes' => 2 * 1024 * 1024 * 1024,
                'row_count' => 500_000,
                'should_chunk' => true,
                'strategy' => 'pk_range',
                'pk_column' => 'id',
            ],
        ]);

    $largeTables = $cmd->detectLargeTables(
        conn: ['hostname' => '127.0.0.1'],
        schemaName: 'test_db',
        chunker: $mockChunker
    );

    expect($largeTables)->toHaveKey('huge_table');
    expect($largeTables)->not->toHaveKey('small_table');
});
