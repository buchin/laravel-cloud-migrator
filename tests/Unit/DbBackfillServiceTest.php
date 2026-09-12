<?php

use App\Data\BackfillTableState;
use App\Data\TablePolicy;
use App\Services\DbBackfillService;
use App\Services\MigrationManifest;
use App\Services\TableChunker;

describe('DbBackfillService Unit', function () {
    test('detectPrimaryKeyViaPdo accurately detects integer primary key on SQLite', function () {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');

        $chunker = new TableChunker;
        $pkInfo = $chunker->detectPrimaryKeyViaPdo($pdo, 'users');

        expect($pkInfo)->not->toBeNull()
            ->and($pkInfo['column'])->toBe('id')
            ->and($pkInfo['is_integer'])->toBeTrue();
    });

    test('detectPrimaryKeyViaPdo returns is_integer false for non-integer PK or null for no PK', function () {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE logs (uuid TEXT PRIMARY KEY, message TEXT)');
        $pdo->exec('CREATE TABLE no_pk (data TEXT)');

        $chunker = new TableChunker;
        $pkLogs = $chunker->detectPrimaryKeyViaPdo($pdo, 'logs');
        $pkNoPk = $chunker->detectPrimaryKeyViaPdo($pdo, 'no_pk');

        expect($pkLogs)->not->toBeNull()
            ->and($pkLogs['column'])->toBe('uuid')
            ->and($pkLogs['is_integer'])->toBeFalse()
            ->and($pkNoPk)->toBeNull();
    });

    test('getTableRangeViaPdo computes min_id, max_id, and row count', function () {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, val TEXT)');
        $pdo->exec('INSERT INTO items (id, val) VALUES (10, "a"), (25, "b"), (50, "c")');

        $chunker = new TableChunker;
        $range = $chunker->getTableRangeViaPdo($pdo, 'items', 'id');

        expect($range['min_id'])->toBe(10)
            ->and($range['max_id'])->toBe(50)
            ->and($range['total_rows'])->toBe(3);
    });

    test('calculateAdaptiveBatchSize scales batch size based on execution latency', function () {
        $service = new DbBackfillService;

        // Target latency: 0.5s
        // High latency (2.0s > 1.5s): decreases batch size
        $smaller = $service->calculateAdaptiveBatchSize(5000, 2.0, minBatchSize: 100, maxBatchSize: 20000, targetSeconds: 0.5);
        expect($smaller)->toBeLessThan(5000)
            ->and($smaller)->toBe(3500); // 5000 * 0.7

        // Moderately high latency (1.2s > 1.0s): decreases batch size moderately
        $moderate = $service->calculateAdaptiveBatchSize(5000, 1.2, minBatchSize: 100, maxBatchSize: 20000, targetSeconds: 0.5);
        expect($moderate)->toBeLessThan(5000)
            ->and($moderate)->toBe(4250); // 5000 * 0.85

        // Low latency (0.1s < 0.2s): increases batch size
        $larger = $service->calculateAdaptiveBatchSize(5000, 0.1, minBatchSize: 100, maxBatchSize: 20000, targetSeconds: 0.5);
        expect($larger)->toBeGreaterThan(5000)
            ->and($larger)->toBe(6000); // 5000 * 1.2

        // Normal latency (0.5s): keeps current batch size
        $unchanged = $service->calculateAdaptiveBatchSize(5000, 0.5, minBatchSize: 100, maxBatchSize: 20000, targetSeconds: 0.5);
        expect($unchanged)->toBe(5000);

        // Clamps to min and max boundaries
        $clampedMin = $service->calculateAdaptiveBatchSize(120, 5.0, minBatchSize: 100, maxBatchSize: 20000, targetSeconds: 0.5);
        expect($clampedMin)->toBe(100);

        $clampedMax = $service->calculateAdaptiveBatchSize(19000, 0.05, minBatchSize: 100, maxBatchSize: 20000, targetSeconds: 0.5);
        expect($clampedMax)->toBe(20000);
    });

    test('stateful checkpointing saves, loads, updates and clears state', function () {
        $tempState = sys_get_temp_dir().'/.test-backfill-state-'.uniqid().'.json';
        $service = new DbBackfillService;

        try {
            // Initial load on non-existent file
            $initial = $service->loadState($tempState);
            expect($initial['tables'])->toBeEmpty();

            // Save table state
            $state = new BackfillTableState(
                table: 'episodes',
                schema: 'dracin_api',
                status: BackfillTableState::STATUS_IN_PROGRESS,
                strategy: 'pk_range',
                pkColumn: 'id',
                minId: 1,
                maxId: 100000,
                lastProcessedId: 25000,
                processedRows: 25000,
                totalRows: 100000,
                currentBatchSize: 5000,
            );

            $service->saveTableState($tempState, $state);
            expect(file_exists($tempState))->toBeTrue();

            // Retrieve table state
            $loaded = $service->getTableState($tempState, 'dracin_api', 'episodes');
            expect($loaded)->not->toBeNull()
                ->and($loaded->table)->toBe('episodes')
                ->and($loaded->lastProcessedId)->toBe(25000)
                ->and($loaded->processedRows)->toBe(25000)
                ->and($loaded->isInProgress())->toBeTrue();

            // Clear table state
            $service->clearState($tempState, 'dracin_api', 'episodes');
            $cleared = $service->getTableState($tempState, 'dracin_api', 'episodes');
            expect($cleared)->toBeNull();
        } finally {
            if (file_exists($tempState)) {
                unlink($tempState);
            }
        }
    });

    test('backfillTable successfully transfers data with dynamic chunking and throttling', function () {
        $srcPdo = new PDO('sqlite::memory:');
        $tgtPdo = new PDO('sqlite::memory:');

        $srcPdo->exec('CREATE TABLE links (id INTEGER PRIMARY KEY, url TEXT, title TEXT)');
        $tgtPdo->exec('CREATE TABLE links (id INTEGER PRIMARY KEY, url TEXT, title TEXT)');

        // Seed 350 rows
        $stmt = $srcPdo->prepare('INSERT INTO links (id, url, title) VALUES (?, ?, ?)');
        for ($i = 1; $i <= 350; $i++) {
            $stmt->execute([$i, "https://example.com/item/{$i}", "Item #{$i}"]);
        }

        $recordedSleeps = [];
        $sleeper = function (int $ms) use (&$recordedSleeps) {
            $recordedSleeps[] = $ms;
        };

        $tempState = sys_get_temp_dir().'/.test-backfill-state-'.uniqid().'.json';
        $service = new DbBackfillService(sleeper: $sleeper);

        try {
            $result = $service->backfillTable(
                srcPdo: $srcPdo,
                tgtPdo: $tgtPdo,
                schema: 'nerd',
                table: 'links',
                initialBatchSize: 100,
                sleepMs: 15,
                stateFile: $tempState,
                adaptive: false,
            );

            expect($result->success)->toBeTrue()
                ->and($result->status)->toBe('completed')
                ->and($result->rowsCopied)->toBe(350)
                ->and($result->totalRows)->toBe(350)
                ->and($result->batchesExecuted)->toBe(4); // 100, 100, 100, 50

            // Verify target row count
            $tgtCount = (int) $tgtPdo->query('SELECT COUNT(*) FROM links')->fetchColumn();
            expect($tgtCount)->toBe(350);

            // Verify throttling calls
            expect($recordedSleeps)->toHaveCount(4)
                ->and($recordedSleeps[0])->toBe(15);

            // Verify checkpoint file is marked completed
            $savedState = $service->getTableState($tempState, 'nerd', 'links');
            expect($savedState)->not->toBeNull()
                ->and($savedState->isCompleted())->toBeTrue()
                ->and($savedState->processedRows)->toBe(350)
                ->and($savedState->lastProcessedId)->toBe(350);
        } finally {
            if (file_exists($tempState)) {
                unlink($tempState);
            }
        }
    });

    test('backfillTable gracefully handles interruption and resume without duplicate data', function () {
        $srcPdo = new PDO('sqlite::memory:');
        $tgtPdo = new PDO('sqlite::memory:');

        $srcPdo->exec('CREATE TABLE nerd_urls (id INTEGER PRIMARY KEY, path TEXT)');
        $tgtPdo->exec('CREATE TABLE nerd_urls (id INTEGER PRIMARY KEY, path TEXT)');

        // Seed 500 rows (ID 1 to 500)
        $stmt = $srcPdo->prepare('INSERT INTO nerd_urls (id, path) VALUES (?, ?)');
        for ($i = 1; $i <= 500; $i++) {
            $stmt->execute([$i, "/page/{$i}"]);
        }

        $tempState = sys_get_temp_dir().'/.test-backfill-state-'.uniqid().'.json';
        $service = new DbBackfillService(sleeper: fn () => null);

        try {
            // Run 1: Interrupt after 2 batches (200 rows)
            $batchesCount = 0;
            $shouldStop = function () use (&$batchesCount) {
                return $batchesCount >= 2;
            };

            $result1 = $service->backfillTable(
                srcPdo: $srcPdo,
                tgtPdo: $tgtPdo,
                schema: 'dojo',
                table: 'nerd_urls',
                initialBatchSize: 100,
                sleepMs: 0,
                stateFile: $tempState,
                adaptive: false,
                progress: function () use (&$batchesCount) {
                    $batchesCount++;
                },
                shouldStop: $shouldStop,
            );

            expect($result1->status)->toBe('interrupted')
                ->and($result1->rowsCopied)->toBe(200)
                ->and($result1->lastProcessedId)->toBe(200);

            $tgtCount1 = (int) $tgtPdo->query('SELECT COUNT(*) FROM nerd_urls')->fetchColumn();
            expect($tgtCount1)->toBe(200);

            $state1 = $service->getTableState($tempState, 'dojo', 'nerd_urls');
            expect($state1->isInterrupted())->toBeTrue()
                ->and($state1->lastProcessedId)->toBe(200)
                ->and($state1->processedRows)->toBe(200);

            // Run 2: Resume with --resume flag
            $result2 = $service->backfillTable(
                srcPdo: $srcPdo,
                tgtPdo: $tgtPdo,
                schema: 'dojo',
                table: 'nerd_urls',
                initialBatchSize: 100,
                sleepMs: 0,
                resume: true,
                stateFile: $tempState,
                adaptive: false,
            );

            expect($result2->success)->toBeTrue()
                ->and($result2->status)->toBe('completed')
                ->and($result2->rowsCopied)->toBe(300); // 300 remaining rows copied

            // Verify target table has exactly 500 rows (0 duplicate rows)
            $tgtCount2 = (int) $tgtPdo->query('SELECT COUNT(*) FROM nerd_urls')->fetchColumn();
            expect($tgtCount2)->toBe(500);

            // Verify final state is completed
            $state2 = $service->getTableState($tempState, 'dojo', 'nerd_urls');
            expect($state2->isCompleted())->toBeTrue()
                ->and($state2->processedRows)->toBe(500)
                ->and($state2->lastProcessedId)->toBe(500);
        } finally {
            if (file_exists($tempState)) {
                unlink($tempState);
            }
        }
    });

    test('backfillTable supports limit-offset strategy for tables without integer primary key', function () {
        $srcPdo = new PDO('sqlite::memory:');
        $tgtPdo = new PDO('sqlite::memory:');

        $srcPdo->exec('CREATE TABLE uuid_records (code TEXT PRIMARY KEY, value TEXT)');
        $tgtPdo->exec('CREATE TABLE uuid_records (code TEXT PRIMARY KEY, value TEXT)');

        $stmt = $srcPdo->prepare('INSERT INTO uuid_records (code, value) VALUES (?, ?)');
        for ($i = 1; $i <= 60; $i++) {
            $stmt->execute(["CODE-{$i}", "Value {$i}"]);
        }

        $tempState = sys_get_temp_dir().'/.test-backfill-state-'.uniqid().'.json';
        $service = new DbBackfillService(sleeper: fn () => null);

        try {
            $result = $service->backfillTable(
                srcPdo: $srcPdo,
                tgtPdo: $tgtPdo,
                schema: 'dojo',
                table: 'uuid_records',
                initialBatchSize: 25,
                sleepMs: 0,
                stateFile: $tempState,
                adaptive: false,
            );

            expect($result->success)->toBeTrue()
                ->and($result->status)->toBe('completed')
                ->and($result->rowsCopied)->toBe(60);

            $tgtCount = (int) $tgtPdo->query('SELECT COUNT(*) FROM uuid_records')->fetchColumn();
            expect($tgtCount)->toBe(60);

            $state = $service->getTableState($tempState, 'dojo', 'uuid_records');
            expect($state->strategy)->toBe('limit_offset')
                ->and($state->processedRows)->toBe(60);
        } finally {
            if (file_exists($tempState)) {
                unlink($tempState);
            }
        }
    });

    test('backfillTable respects manifest policies (transient skip and custom chunk_size)', function () {
        $srcPdo = new PDO('sqlite::memory:');
        $tgtPdo = new PDO('sqlite::memory:');

        $srcPdo->exec('CREATE TABLE sessions (id TEXT PRIMARY KEY, payload TEXT)');
        $tgtPdo->exec('CREATE TABLE sessions (id TEXT PRIMARY KEY, payload TEXT)');

        $srcPdo->exec('INSERT INTO sessions VALUES ("sess1", "abc")');

        $tempState = sys_get_temp_dir().'/.test-backfill-state-'.uniqid().'.json';
        $service = new DbBackfillService;

        try {
            // Transient policy without force should skip
            $transientPolicy = new TablePolicy(policy: TablePolicy::TRANSIENT, migrateData: false);
            $result = $service->backfillTable(
                srcPdo: $srcPdo,
                tgtPdo: $tgtPdo,
                schema: 'dojo',
                table: 'sessions',
                stateFile: $tempState,
                policy: $transientPolicy,
            );

            expect($result->status)->toBe('skipped');
            $tgtCount = (int) $tgtPdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn();
            expect($tgtCount)->toBe(0);
        } finally {
            if (file_exists($tempState)) {
                unlink($tempState);
            }
        }
    });

    test('resolveCandidateTables identifies chunked and ignore tables from manifest', function () {
        $manifestJson = json_encode([
            'databases' => [
                'dracin_api.main' => [
                    'tables' => [
                        'episodes' => ['policy' => 'chunked', 'chunk_size' => 10000],
                        'video_transcodes' => ['policy' => 'transient'],
                        'legacy_drafts' => ['policy' => 'ignore'],
                    ],
                ],
            ],
        ]);

        $manifest = MigrationManifest::fromString($manifestJson);
        $service = new DbBackfillService(manifest: $manifest);

        $schemaMap = [
            'dracin_api.main' => [
                'cluster' => 'dracin_api',
                'schema' => 'main',
                'connection' => ['hostname' => '127.0.0.1'],
            ],
        ];

        $candidates = $service->resolveCandidateTables($schemaMap, manifest: $manifest);

        // episodes (chunked) and legacy_drafts (ignore) are candidate backfill tables; video_transcodes (transient) is excluded
        expect($candidates)->toHaveCount(2);

        $tables = array_column($candidates, 'table');
        expect($tables)->toContain('episodes')
            ->and($tables)->toContain('legacy_drafts')
            ->and($tables)->not->toContain('video_transcodes');
    });
});
