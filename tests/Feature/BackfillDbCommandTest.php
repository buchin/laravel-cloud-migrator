<?php

use App\Commands\BackfillDbCommand;
use App\Data\BackfillTableState;
use App\Services\CloudApiClient;
use App\Services\DbBackfillService;

describe('BackfillDbCommand Feature', function () {
    test('executes backfill successfully with explicit table and --yes flag', function () {
        $mockSource = Mockery::mock(CloudApiClient::class);
        $mockSource->shouldReceive('getAll')->with('databases/clusters')->andReturn([
            [
                'id' => 'src-cluster-1',
                'attributes' => [
                    'name' => 'dracin_api',
                    'connection' => [
                        'hostname' => '127.0.0.1',
                        'port' => 3306,
                        'username' => 'root',
                        'password' => '',
                    ],
                ],
            ],
        ]);
        $mockSource->shouldReceive('getAll')->with('databases/clusters/src-cluster-1/databases')->andReturn([
            ['id' => 'db-1', 'attributes' => ['name' => 'main']],
        ]);

        $mockTarget = Mockery::mock(CloudApiClient::class);
        $mockTarget->shouldReceive('getAll')->with('databases/clusters')->andReturn([
            [
                'id' => 'tgt-cluster-1',
                'attributes' => [
                    'name' => 'dracin_api',
                    'connection' => [
                        'hostname' => '127.0.0.1',
                        'port' => 3306,
                        'username' => 'root',
                        'password' => '',
                    ],
                ],
            ],
        ]);
        $mockTarget->shouldReceive('getAll')->with('databases/clusters/tgt-cluster-1/databases')->andReturn([
            ['id' => 'tgt-db-1', 'attributes' => ['name' => 'main']],
        ]);

        $this->app->instance('CloudApiClient.src-token', $mockSource);
        $this->app->instance('CloudApiClient.tgt-token', $mockTarget);

        // SQLite in-memory PDOs for real data execution
        $srcPdo = new PDO('sqlite::memory:');
        $tgtPdo = new PDO('sqlite::memory:');

        $srcPdo->exec('CREATE TABLE episodes (id INTEGER PRIMARY KEY, title TEXT, duration INT)');
        $tgtPdo->exec('CREATE TABLE episodes (id INTEGER PRIMARY KEY, title TEXT, duration INT)');

        $stmt = $srcPdo->prepare('INSERT INTO episodes (id, title, duration) VALUES (?, ?, ?)');
        for ($i = 1; $i <= 150; $i++) {
            $stmt->execute([$i, "Episode #{$i}", 1200 + $i]);
        }

        $this->app->instance('PDO.source.main', $srcPdo);
        $this->app->instance('PDO.target.main', $tgtPdo);

        $tempState = sys_get_temp_dir().'/.backfill-test-'.uniqid().'.json';

        try {
            $this->artisan('db:backfill', [
                '--source-token' => 'src-token',
                '--target-token' => 'tgt-token',
                '--schema' => ['dracin_api.main'],
                '--table' => ['episodes'],
                '--batch-size' => 50,
                '--sleep-ms' => 0,
                '--state-file' => $tempState,
                '--yes' => true,
            ])
                ->expectsOutputToContain('Background Data Backfill Plan')
                ->expectsOutputToContain('Executing Data Backfill Pipeline')
                ->expectsOutputToContain('Backfill completed successfully!')
                ->assertExitCode(BackfillDbCommand::SUCCESS);

            $tgtCount = (int) $tgtPdo->query('SELECT COUNT(*) FROM episodes')->fetchColumn();
            expect($tgtCount)->toBe(150);
        } finally {
            if (file_exists($tempState)) {
                unlink($tempState);
            }
        }
    });

    test('--dry-run mode previews execution plan without copying data', function () {
        $mockSource = Mockery::mock(CloudApiClient::class);
        $mockSource->shouldReceive('getAll')->with('databases/clusters')->andReturn([
            [
                'id' => 'src-cluster-1',
                'attributes' => [
                    'name' => 'dojo',
                    'connection' => ['hostname' => '127.0.0.1'],
                ],
            ],
        ]);
        $mockSource->shouldReceive('getAll')->with('databases/clusters/src-cluster-1/databases')->andReturn([
            ['id' => 'db-1', 'attributes' => ['name' => 'main']],
        ]);

        $mockTarget = Mockery::mock(CloudApiClient::class);
        $mockTarget->shouldReceive('getAll')->with('databases/clusters')->andReturn([
            [
                'id' => 'tgt-cluster-1',
                'attributes' => [
                    'name' => 'dojo',
                    'connection' => ['hostname' => '127.0.0.1'],
                ],
            ],
        ]);
        $mockTarget->shouldReceive('getAll')->with('databases/clusters/tgt-cluster-1/databases')->andReturn([
            ['id' => 'tgt-db-1', 'attributes' => ['name' => 'main']],
        ]);

        $this->app->instance('CloudApiClient.src-token', $mockSource);
        $this->app->instance('CloudApiClient.tgt-token', $mockTarget);

        $srcPdo = new PDO('sqlite::memory:');
        $tgtPdo = new PDO('sqlite::memory:');
        $srcPdo->exec('CREATE TABLE nerd_urls (id INTEGER PRIMARY KEY, url TEXT)');
        $tgtPdo->exec('CREATE TABLE nerd_urls (id INTEGER PRIMARY KEY, url TEXT)');
        $srcPdo->exec('INSERT INTO nerd_urls VALUES (1, "https://test.com")');

        $this->app->instance('PDO.source.main', $srcPdo);
        $this->app->instance('PDO.target.main', $tgtPdo);

        $tempState = sys_get_temp_dir().'/.dry-run-state-'.uniqid().'.json';

        try {
            $this->artisan('db:backfill', [
                '--source-token' => 'src-token',
                '--target-token' => 'tgt-token',
                '--schema' => ['dojo.main'],
                '--table' => ['nerd_urls'],
                '--dry-run' => true,
                '--state-file' => $tempState,
            ])
                ->expectsOutputToContain('Background Data Backfill Plan')
                ->expectsOutputToContain('DRY RUN MODE — No data will be copied')
                ->assertExitCode(BackfillDbCommand::SUCCESS);

            $tgtCount = (int) $tgtPdo->query('SELECT COUNT(*) FROM nerd_urls')->fetchColumn();
            expect($tgtCount)->toBe(0);
        } finally {
            if (file_exists($tempState)) {
                unlink($tempState);
            }
        }
    });

    test('--resume flag resumes from checkpoint state file without duplicates', function () {
        $mockSource = Mockery::mock(CloudApiClient::class);
        $mockSource->shouldReceive('getAll')->with('databases/clusters')->andReturn([
            [
                'id' => 'src-cluster-1',
                'attributes' => [
                    'name' => 'nerd',
                    'connection' => ['hostname' => '127.0.0.1'],
                ],
            ],
        ]);
        $mockSource->shouldReceive('getAll')->with('databases/clusters/src-cluster-1/databases')->andReturn([
            ['id' => 'db-1', 'attributes' => ['name' => 'main']],
        ]);

        $mockTarget = Mockery::mock(CloudApiClient::class);
        $mockTarget->shouldReceive('getAll')->with('databases/clusters')->andReturn([
            [
                'id' => 'tgt-cluster-1',
                'attributes' => [
                    'name' => 'nerd',
                    'connection' => ['hostname' => '127.0.0.1'],
                ],
            ],
        ]);
        $mockTarget->shouldReceive('getAll')->with('databases/clusters/tgt-cluster-1/databases')->andReturn([
            ['id' => 'tgt-db-1', 'attributes' => ['name' => 'main']],
        ]);

        $this->app->instance('CloudApiClient.src-token', $mockSource);
        $this->app->instance('CloudApiClient.tgt-token', $mockTarget);

        $srcPdo = new PDO('sqlite::memory:');
        $tgtPdo = new PDO('sqlite::memory:');

        $srcPdo->exec('CREATE TABLE links (id INTEGER PRIMARY KEY, link TEXT)');
        $tgtPdo->exec('CREATE TABLE links (id INTEGER PRIMARY KEY, link TEXT)');

        // Source has 200 rows (ID 1..200)
        $stmtSrc = $srcPdo->prepare('INSERT INTO links (id, link) VALUES (?, ?)');
        for ($i = 1; $i <= 200; $i++) {
            $stmtSrc->execute([$i, "link-{$i}"]);
        }

        // Target already has first 80 rows from previous interrupted run
        $stmtTgt = $tgtPdo->prepare('INSERT INTO links (id, link) VALUES (?, ?)');
        for ($i = 1; $i <= 80; $i++) {
            $stmtTgt->execute([$i, "link-{$i}"]);
        }

        $this->app->instance('PDO.source.main', $srcPdo);
        $this->app->instance('PDO.target.main', $tgtPdo);

        $tempState = sys_get_temp_dir().'/.resume-state-'.uniqid().'.json';

        // Pre-populate state file simulating interrupted run at ID 80
        $service = new DbBackfillService;
        $state = new BackfillTableState(
            table: 'links',
            schema: 'main',
            status: BackfillTableState::STATUS_INTERRUPTED,
            strategy: 'pk_range',
            pkColumn: 'id',
            minId: 1,
            maxId: 200,
            lastProcessedId: 80,
            processedRows: 80,
            totalRows: 200,
            currentBatchSize: 40,
        );
        $service->saveTableState($tempState, $state);

        try {
            $this->artisan('db:backfill', [
                '--source-token' => 'src-token',
                '--target-token' => 'tgt-token',
                '--schema' => ['nerd.main'],
                '--table' => ['links'],
                '--resume' => true,
                '--batch-size' => 40,
                '--sleep-ms' => 0,
                '--state-file' => $tempState,
                '--yes' => true,
            ])
                ->expectsOutputToContain('Resume at ID 80')
                ->expectsOutputToContain('Backfill completed successfully!')
                ->assertExitCode(BackfillDbCommand::SUCCESS);

            $tgtCount = (int) $tgtPdo->query('SELECT COUNT(*) FROM links')->fetchColumn();
            expect($tgtCount)->toBe(200);
        } finally {
            if (file_exists($tempState)) {
                unlink($tempState);
            }
        }
    });

    test('auto-discovers manifest candidate tables when --table is omitted', function () {
        $mockSource = Mockery::mock(CloudApiClient::class);
        $mockSource->shouldReceive('getAll')->with('databases/clusters')->andReturn([
            [
                'id' => 'src-cluster-1',
                'attributes' => [
                    'name' => 'dracin_api',
                    'connection' => ['hostname' => '127.0.0.1'],
                ],
            ],
        ]);
        $mockSource->shouldReceive('getAll')->with('databases/clusters/src-cluster-1/databases')->andReturn([
            ['id' => 'db-1', 'attributes' => ['name' => 'main']],
        ]);

        $mockTarget = Mockery::mock(CloudApiClient::class);
        $mockTarget->shouldReceive('getAll')->with('databases/clusters')->andReturn([
            [
                'id' => 'tgt-cluster-1',
                'attributes' => [
                    'name' => 'dracin_api',
                    'connection' => ['hostname' => '127.0.0.1'],
                ],
            ],
        ]);
        $mockTarget->shouldReceive('getAll')->with('databases/clusters/tgt-cluster-1/databases')->andReturn([
            ['id' => 'tgt-db-1', 'attributes' => ['name' => 'main']],
        ]);

        $this->app->instance('CloudApiClient.src-token', $mockSource);
        $this->app->instance('CloudApiClient.tgt-token', $mockTarget);

        $tempManifest = sys_get_temp_dir().'/manifest-'.uniqid().'.json';
        file_put_contents($tempManifest, json_encode([
            'databases' => [
                'dracin_api.main' => [
                    'tables' => [
                        'episodes' => ['policy' => 'chunked', 'chunk_size' => 100],
                        'legacy_drafts' => ['policy' => 'ignore'],
                    ],
                ],
            ],
        ]));

        $tempState = sys_get_temp_dir().'/.auto-discover-state-'.uniqid().'.json';

        try {
            $this->artisan('db:backfill', [
                '--source-token' => 'src-token',
                '--target-token' => 'tgt-token',
                '--schema' => ['dracin_api.main'],
                '--manifest' => $tempManifest,
                '--dry-run' => true,
                '--state-file' => $tempState,
            ])
                ->expectsOutputToContain('episodes')
                ->expectsOutputToContain('legacy_drafts')
                ->expectsOutputToContain('DRY RUN MODE')
                ->assertExitCode(BackfillDbCommand::SUCCESS);
        } finally {
            if (file_exists($tempManifest)) {
                unlink($tempManifest);
            }
            if (file_exists($tempState)) {
                unlink($tempState);
            }
        }
    });

    test('returns failure when API returns error', function () {
        $mockSource = Mockery::mock(CloudApiClient::class);
        $mockSource->shouldReceive('getAll')->with('databases/clusters')->andThrow(new RuntimeException('Unauthorized'));

        $this->app->instance('CloudApiClient.invalid-token', $mockSource);

        $this->artisan('db:backfill', [
            '--source-token' => 'invalid-token',
            '--target-token' => 'any-target',
            '--yes' => true,
        ])
            ->assertExitCode(BackfillDbCommand::FAILURE);
    });
});
