<?php

namespace App\Services;

use App\Data\BackfillResult;
use App\Data\BackfillTableState;
use App\Data\TablePolicy;
use PDO;
use Throwable;

class DbBackfillService
{
    private TableChunker $tableChunker;

    private ?MigrationManifest $manifest;

    /** @var callable */
    private $sleeper;

    /** @var callable */
    private $pdoFactory;

    public function __construct(
        ?TableChunker $tableChunker = null,
        ?MigrationManifest $manifest = null,
        ?callable $sleeper = null,
        ?callable $pdoFactory = null
    ) {
        $this->tableChunker = $tableChunker ?? new TableChunker;
        $this->manifest = $manifest;
        $this->sleeper = $sleeper ?? function (int $ms) {
            if ($ms > 0) {
                usleep($ms * 1000);
            }
        };
        $this->pdoFactory = $pdoFactory ?? function (array $conn, string $database) {
            return $this->createPdo($conn, $database);
        };
    }

    public function getTableChunker(): TableChunker
    {
        return $this->tableChunker;
    }

    public function setTableChunker(TableChunker $tableChunker): void
    {
        $this->tableChunker = $tableChunker;
    }

    public function getManifest(): ?MigrationManifest
    {
        return $this->manifest;
    }

    public function setManifest(?MigrationManifest $manifest): void
    {
        $this->manifest = $manifest;
    }

    public function setSleeper(callable $sleeper): void
    {
        $this->sleeper = $sleeper;
    }

    public function setPdoFactory(callable $pdoFactory): void
    {
        $this->pdoFactory = $pdoFactory;
    }

    /**
     * Create a standard PDO connection for MySQL or Postgres.
     */
    public function createPdo(array $conn, string $database): PDO
    {
        $host = $conn['hostname'] ?? '127.0.0.1';
        $port = (int) ($conn['port'] ?? 3306);
        $user = $conn['username'] ?? '';
        $pass = $conn['password'] ?? '';
        $type = strtolower($conn['type'] ?? 'mysql');

        if (str_contains($type, 'pgsql') || str_contains($type, 'postgres')) {
            $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ];
        } else {
            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
                PDO::ATTR_TIMEOUT => 30,
            ];
        }

        return new PDO($dsn, $user, $pass, $options);
    }

    /**
     * Connect to database using factory or direct credentials.
     */
    public function connect(array $conn, string $database): PDO
    {
        return ($this->pdoFactory)($conn, $database);
    }

    /**
     * Load checkpoint state from JSON file.
     *
     * @return array{version: string, updated_at: string, tables: array<string, array>}
     */
    public function loadState(string $stateFile): array
    {
        if (! file_exists($stateFile)) {
            return [
                'version' => '1.0',
                'updated_at' => date('c'),
                'tables' => [],
            ];
        }

        $content = file_get_contents($stateFile);
        if ($content === false) {
            return [
                'version' => '1.0',
                'updated_at' => date('c'),
                'tables' => [],
            ];
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            return [
                'version' => '1.0',
                'updated_at' => date('c'),
                'tables' => [],
            ];
        }

        if (! isset($decoded['tables']) || ! is_array($decoded['tables'])) {
            $decoded['tables'] = [];
        }

        return $decoded;
    }

    /**
     * Save checkpoint state to JSON file.
     */
    public function saveState(string $stateFile, array $state): void
    {
        $state['updated_at'] = date('c');
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $dir = dirname($stateFile);
        if ($dir && ! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($stateFile, $json);
    }

    /**
     * Get checkpoint state for a specific schema and table.
     */
    public function getTableState(string $stateFile, string $schema, string $table): ?BackfillTableState
    {
        $state = $this->loadState($stateFile);
        $key = "{$schema}.{$table}";

        if (isset($state['tables'][$key])) {
            return BackfillTableState::fromArray($state['tables'][$key]);
        }

        return null;
    }

    /**
     * Save table state in checkpoint file.
     */
    public function saveTableState(string $stateFile, BackfillTableState $tableState): void
    {
        $state = $this->loadState($stateFile);
        $key = "{$tableState->schema}.{$tableState->table}";
        $state['tables'][$key] = $tableState->toArray();
        $this->saveState($stateFile, $state);
    }

    /**
     * Clear checkpoint state for one or all tables.
     */
    public function clearState(string $stateFile, ?string $schema = null, ?string $table = null): void
    {
        if (! file_exists($stateFile)) {
            return;
        }

        if ($schema === null && $table === null) {
            @unlink($stateFile);

            return;
        }

        $state = $this->loadState($stateFile);
        $key = "{$schema}.{$table}";
        unset($state['tables'][$key]);
        $this->saveState($stateFile, $state);
    }

    /**
     * Calculate adaptive batch size based on execution duration of previous batch.
     */
    public function calculateAdaptiveBatchSize(
        int $currentBatchSize,
        float $elapsedSeconds,
        int $minBatchSize = 100,
        int $maxBatchSize = 50_000,
        float $targetSeconds = 0.5
    ): int {
        // If elapsed time is significantly high (> 3x target, e.g. > 1.5s), decrease batch size by 30%
        if ($elapsedSeconds > ($targetSeconds * 3)) {
            $newSize = (int) floor($currentBatchSize * 0.7);

            return max($minBatchSize, $newSize);
        }

        // If elapsed time is moderately high (> 2x target, e.g. > 1.0s), decrease batch size by 15%
        if ($elapsedSeconds > ($targetSeconds * 2)) {
            $newSize = (int) floor($currentBatchSize * 0.85);

            return max($minBatchSize, $newSize);
        }

        // If elapsed time is very low (< 0.4x target, e.g. < 0.2s), increase batch size by 20%
        if ($elapsedSeconds < ($targetSeconds * 0.4) && $currentBatchSize < $maxBatchSize) {
            $newSize = (int) ceil($currentBatchSize * 1.2);

            return min($maxBatchSize, $newSize);
        }

        return $currentBatchSize;
    }

    /**
     * Build non-duplicate insert SQL query based on database driver.
     */
    public function buildInsertQuery(string $driver, string $table, array $columns, int $rowCount): string
    {
        $driver = strtolower($driver);
        $colList = implode(', ', array_map(fn ($col) => "`{$col}`", $columns));
        $singleRowPlaceholder = '('.implode(', ', array_fill(0, count($columns), '?')).')';
        $placeholders = implode(', ', array_fill(0, $rowCount, $singleRowPlaceholder));

        if ($driver === 'sqlite') {
            return "INSERT OR IGNORE INTO `{$table}` ({$colList}) VALUES {$placeholders}";
        }

        if (str_contains($driver, 'pgsql') || str_contains($driver, 'postgres')) {
            $colListPg = implode(', ', array_map(fn ($col) => "\"{$col}\"", $columns));

            return "INSERT INTO \"{$table}\" ({$colListPg}) VALUES {$placeholders} ON CONFLICT DO NOTHING";
        }

        // MySQL default
        return "INSERT IGNORE INTO `{$table}` ({$colList}) VALUES {$placeholders}";
    }

    /**
     * Insert rows into target table in sub-batches to respect PDO placeholder limits.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function insertRows(PDO $tgtPdo, string $table, array $rows, string $driver = 'mysql'): int
    {
        if (empty($rows)) {
            return 0;
        }

        $columns = array_keys($rows[0]);
        $columnsCount = max(1, count($columns));

        // Limit placeholder count per statement: 900 for SQLite, 50,000 for MySQL
        $maxPlaceholders = ($driver === 'sqlite') ? 900 : 50_000;
        $maxRowsPerInsert = max(1, min(500, (int) floor($maxPlaceholders / $columnsCount)));

        $subBatches = array_chunk($rows, $maxRowsPerInsert);
        $totalInserted = 0;

        $inTransaction = false;
        try {
            if (! $tgtPdo->inTransaction()) {
                $tgtPdo->beginTransaction();
                $inTransaction = true;
            }

            foreach ($subBatches as $subBatch) {
                $sql = $this->buildInsertQuery($driver, $table, $columns, count($subBatch));
                $stmt = $tgtPdo->prepare($sql);

                $params = [];
                foreach ($subBatch as $row) {
                    foreach ($columns as $col) {
                        $params[] = $row[$col] ?? null;
                    }
                }

                $stmt->execute($params);
                $totalInserted += $stmt->rowCount();
            }

            if ($inTransaction) {
                $tgtPdo->commit();
            }
        } catch (Throwable $e) {
            if ($inTransaction && $tgtPdo->inTransaction()) {
                $tgtPdo->rollBack();
            }
            throw $e;
        }

        return $totalInserted;
    }

    /**
     * Inspect table columns using PDO.
     *
     * @return string[]
     */
    public function getTableColumns(PDO $pdo, string $table, ?string $schema = null): array
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $stmt = $pdo->query("PRAGMA table_info(`{$table}`)");
            $cols = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

            return array_column($cols, 'name');
        }

        if ($driver === 'mysql') {
            $dbClause = $schema ? "TABLE_SCHEMA = '".addslashes($schema)."' AND " : '';
            $sql = 'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
                ."WHERE {$dbClause}TABLE_NAME = '".addslashes($table)."' ORDER BY ORDINAL_POSITION";
            $stmt = $pdo->query($sql);
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

            return array_column($rows, 'COLUMN_NAME');
        }

        // Generic fallback
        $stmt = $pdo->query("SELECT * FROM `{$table}` LIMIT 0");
        $colCount = $stmt->columnCount();
        $cols = [];
        for ($i = 0; $i < $colCount; $i++) {
            $meta = $stmt->getColumnMeta($i);
            if (! empty($meta['name'])) {
                $cols[] = $meta['name'];
            }
        }

        return $cols;
    }

    /**
     * Disable foreign key checks on target database before migration batch.
     */
    public function disableForeignKeys(PDO $pdo, string $driver): void
    {
        if ($driver === 'mysql') {
            $pdo->exec('SET foreign_key_checks = 0; SET unique_checks = 0;');
        } elseif ($driver === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = OFF;');
        }
    }

    /**
     * Restore foreign key checks on target database after migration batch.
     */
    public function restoreForeignKeys(PDO $pdo, string $driver): void
    {
        if ($driver === 'mysql') {
            $pdo->exec('SET foreign_key_checks = 1; SET unique_checks = 1;');
        } elseif ($driver === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON;');
        }
    }

    /**
     * Backfill a single table from source to target using dynamic PK chunking,
     * adaptive batching, throttling, and stateful checkpointing.
     */
    public function backfillTable(
        PDO $srcPdo,
        PDO $tgtPdo,
        string $schema,
        string $table,
        int $initialBatchSize = 5000,
        int $sleepMs = 50,
        bool $resume = false,
        string $stateFile = '.backfill-state.json',
        bool $adaptive = true,
        ?callable $progress = null,
        ?callable $shouldStop = null,
        ?TablePolicy $policy = null,
        bool $force = false,
    ): BackfillResult {
        $startTime = microtime(true);
        $srcDriver = $srcPdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $tgtDriver = $tgtPdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        // Check manifest policy
        if ($policy && $policy->isTransient() && ! $policy->shouldMigrateData() && ! $force) {
            if ($progress) {
                $progress("Table `{$table}` is marked transient in policy. Skipping.");
            }

            return BackfillResult::skipped($schema, $table, 'Marked transient in policy');
        }

        if ($policy && $policy->isSchemaOnly() && ! $force) {
            if ($progress) {
                $progress("Table `{$table}` is marked schema_only in policy. Skipping.");
            }

            return BackfillResult::skipped($schema, $table, 'Marked schema_only in policy');
        }

        // Determine effective batch size
        $batchSize = $initialBatchSize;
        if ($policy && $policy->chunkSize && $initialBatchSize === 5000) {
            $batchSize = $policy->chunkSize;
        }

        // Check existing checkpoint state
        $existingState = $this->getTableState($stateFile, $schema, $table);

        if ($existingState && $existingState->isCompleted() && ! $force && ! $resume) {
            if ($progress) {
                $progress("Table `{$table}` already completed in previous run ({$existingState->processedRows} rows). Skipping.");
            }

            return BackfillResult::alreadyCompleted(
                schema: $schema,
                table: $table,
                processedRows: $existingState->processedRows,
                totalRows: $existingState->totalRows,
                lastProcessedId: $existingState->lastProcessedId,
            );
        }

        $resuming = $resume && $existingState && ! $force;

        // Initialize state
        if ($resuming) {
            $state = $existingState;
            $batchSize = $state->currentBatchSize ?: $batchSize;
            if ($progress) {
                $progress("Resuming backfill for `{$table}` from last ID "
                    .($state->lastProcessedId !== null ? $state->lastProcessedId : $state->currentOffset)
                    ." ({$state->processedRows}/{$state->totalRows} rows previously copied)...");
            }
        } else {
            // Detect primary key
            $pkInfo = $this->tableChunker->detectPrimaryKeyViaPdo($srcPdo, $table, $schema);
            $hasIntPk = $pkInfo && $pkInfo['is_integer'];
            $pkCol = $pkInfo['column'] ?? null;
            $strategy = $hasIntPk ? 'pk_range' : 'limit_offset';

            if ($strategy === 'pk_range') {
                $range = $this->tableChunker->getTableRangeViaPdo($srcPdo, $table, $pkCol);
                $minId = $range['min_id'];
                $maxId = $range['max_id'];
                $totalRows = $range['total_rows'];
            } else {
                $stmt = $srcPdo->query("SELECT COUNT(*) FROM `{$table}`");
                $totalRows = (int) ($stmt ? $stmt->fetchColumn() : 0);
                $minId = null;
                $maxId = null;
            }

            if ($totalRows === 0) {
                if ($progress) {
                    $progress("Table `{$table}` is empty (0 rows). Marked completed.");
                }

                $state = new BackfillTableState(
                    table: $table,
                    schema: $schema,
                    status: BackfillTableState::STATUS_COMPLETED,
                    strategy: $strategy,
                    pkColumn: $pkCol,
                    minId: $minId,
                    maxId: $maxId,
                    lastProcessedId: null,
                    processedRows: 0,
                    totalRows: 0,
                    currentBatchSize: $batchSize,
                    startedAt: date('c'),
                    completedAt: date('c'),
                );
                $this->saveTableState($stateFile, $state);

                return BackfillResult::completed(
                    schema: $schema,
                    table: $table,
                    rowsCopied: 0,
                    totalRows: 0,
                    batchesExecuted: 0,
                    durationSeconds: microtime(true) - $startTime,
                );
            }

            $state = new BackfillTableState(
                table: $table,
                schema: $schema,
                status: BackfillTableState::STATUS_IN_PROGRESS,
                strategy: $strategy,
                pkColumn: $pkCol,
                minId: $minId,
                maxId: $maxId,
                lastProcessedId: null,
                currentOffset: 0,
                processedRows: 0,
                totalRows: $totalRows,
                currentBatchSize: $batchSize,
                startedAt: date('c'),
                updatedAt: date('c'),
            );
            $this->saveTableState($stateFile, $state);
        }

        // Disable foreign keys on target during backfill
        $this->disableForeignKeys($tgtPdo, $tgtDriver);

        $totalCopiedThisRun = 0;
        $batchesExecuted = 0;
        $currentBatchSize = $state->currentBatchSize ?: $batchSize;

        try {
            if ($state->strategy === 'pk_range') {
                $pkCol = $state->pkColumn;
                $startId = $state->lastProcessedId !== null ? ($state->lastProcessedId + 1) : $state->minId;
                $maxId = $state->maxId;

                while ($startId <= $maxId) {
                    if ($shouldStop && $shouldStop()) {
                        $state->status = BackfillTableState::STATUS_INTERRUPTED;
                        $state->updatedAt = date('c');
                        $this->saveTableState($stateFile, $state);

                        if ($progress) {
                            $progress("  ⚠ Backfill interrupted by signal. Checkpoint saved for `{$table}` at last ID {$state->lastProcessedId}.");
                        }

                        return BackfillResult::interrupted(
                            schema: $schema,
                            table: $table,
                            rowsCopied: $totalCopiedThisRun,
                            totalRows: $state->totalRows,
                            batchesExecuted: $batchesExecuted,
                            durationSeconds: microtime(true) - $startTime,
                            lastProcessedId: $state->lastProcessedId,
                        );
                    }

                    $batchStart = microtime(true);

                    // Fetch next batch by PK range using index scan
                    $sql = "SELECT * FROM `{$table}` WHERE `{$pkCol}` >= :start_id AND `{$pkCol}` <= :max_id ORDER BY `{$pkCol}` ASC LIMIT :limit";
                    $stmt = $srcPdo->prepare($sql);
                    $stmt->bindValue(':start_id', $startId, PDO::PARAM_INT);
                    $stmt->bindValue(':max_id', $maxId, PDO::PARAM_INT);
                    $stmt->bindValue(':limit', $currentBatchSize, PDO::PARAM_INT);
                    $stmt->execute();
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    if (empty($rows)) {
                        break;
                    }

                    $batchCount = count($rows);
                    $batchMaxId = max(array_column($rows, $pkCol));

                    // Insert rows into target
                    $this->insertRows($tgtPdo, $table, $rows, $tgtDriver);

                    $batchElapsed = microtime(true) - $batchStart;
                    $totalCopiedThisRun += $batchCount;
                    $batchesExecuted++;

                    // Update checkpoint state
                    $state->lastProcessedId = $batchMaxId;
                    $state->processedRows += $batchCount;
                    $state->updatedAt = date('c');
                    $this->saveTableState($stateFile, $state);

                    $pct = $state->totalRows > 0
                        ? min(100.0, round(($state->processedRows / $state->totalRows) * 100, 1))
                        : 100.0;

                    if ($progress) {
                        $ms = round($batchElapsed * 1000);
                        $progress("  [{$table}] Batch #{$batchesExecuted} — copied {$batchCount} rows (ID: {$startId}..{$batchMaxId}, latency: {$ms}ms, progress: {$pct}%)");
                    }

                    // Throttling sleep
                    if ($sleepMs > 0) {
                        ($this->sleeper)($sleepMs);
                    }

                    // Adaptive batch sizing
                    if ($adaptive) {
                        $newSize = $this->calculateAdaptiveBatchSize($currentBatchSize, $batchElapsed);
                        if ($newSize !== $currentBatchSize) {
                            if ($progress) {
                                $progress("    ⚡ Adaptive batch size adjusted: {$currentBatchSize} → {$newSize}");
                            }
                            $currentBatchSize = $newSize;
                            $state->currentBatchSize = $currentBatchSize;
                        }
                    }

                    $startId = $batchMaxId + 1;
                }
            } else {
                // Limit-offset strategy for tables without an integer PK
                $offset = $state->currentOffset;
                $totalRows = $state->totalRows;
                $orderCol = $state->pkColumn ? "`{$state->pkColumn}`" : '1';

                while ($offset < $totalRows) {
                    if ($shouldStop && $shouldStop()) {
                        $state->status = BackfillTableState::STATUS_INTERRUPTED;
                        $state->updatedAt = date('c');
                        $this->saveTableState($stateFile, $state);

                        if ($progress) {
                            $progress("  ⚠ Backfill interrupted. Checkpoint saved for `{$table}` at offset {$state->currentOffset}.");
                        }

                        return BackfillResult::interrupted(
                            schema: $schema,
                            table: $table,
                            rowsCopied: $totalCopiedThisRun,
                            totalRows: $state->totalRows,
                            batchesExecuted: $batchesExecuted,
                            durationSeconds: microtime(true) - $startTime,
                            lastProcessedId: null,
                        );
                    }

                    $batchStart = microtime(true);

                    $sql = "SELECT * FROM `{$table}` ORDER BY {$orderCol} LIMIT :limit OFFSET :offset";
                    $stmt = $srcPdo->prepare($sql);
                    $stmt->bindValue(':limit', $currentBatchSize, PDO::PARAM_INT);
                    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                    $stmt->execute();
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    if (empty($rows)) {
                        break;
                    }

                    $batchCount = count($rows);
                    $this->insertRows($tgtPdo, $table, $rows, $tgtDriver);

                    $batchElapsed = microtime(true) - $batchStart;
                    $totalCopiedThisRun += $batchCount;
                    $batchesExecuted++;
                    $offset += $batchCount;

                    // Update checkpoint state
                    $state->currentOffset = $offset;
                    $state->processedRows += $batchCount;
                    $state->updatedAt = date('c');
                    $this->saveTableState($stateFile, $state);

                    $pct = $state->totalRows > 0
                        ? min(100.0, round(($state->processedRows / $state->totalRows) * 100, 1))
                        : 100.0;

                    if ($progress) {
                        $ms = round($batchElapsed * 1000);
                        $progress("  [{$table}] Batch #{$batchesExecuted} — copied {$batchCount} rows (offset: {$offset}, latency: {$ms}ms, progress: {$pct}%)");
                    }

                    // Throttling sleep
                    if ($sleepMs > 0) {
                        ($this->sleeper)($sleepMs);
                    }

                    // Adaptive batch sizing
                    if ($adaptive) {
                        $newSize = $this->calculateAdaptiveBatchSize($currentBatchSize, $batchElapsed);
                        if ($newSize !== $currentBatchSize) {
                            if ($progress) {
                                $progress("    ⚡ Adaptive batch size adjusted: {$currentBatchSize} → {$newSize}");
                            }
                            $currentBatchSize = $newSize;
                            $state->currentBatchSize = $currentBatchSize;
                        }
                    }
                }
            }

            // Mark table completed
            $state->status = BackfillTableState::STATUS_COMPLETED;
            $state->completedAt = date('c');
            $state->updatedAt = date('c');
            $this->saveTableState($stateFile, $state);

            $duration = microtime(true) - $startTime;

            if ($progress) {
                $progress("  ✓ Completed backfill for `{$table}` ({$state->processedRows} total rows, duration: ".round($duration, 2).'s)');
            }

            return BackfillResult::completed(
                schema: $schema,
                table: $table,
                rowsCopied: $totalCopiedThisRun,
                totalRows: $state->totalRows,
                batchesExecuted: $batchesExecuted,
                durationSeconds: $duration,
                lastProcessedId: $state->lastProcessedId,
            );
        } catch (Throwable $e) {
            $state->status = BackfillTableState::STATUS_FAILED;
            $state->error = $e->getMessage();
            $state->updatedAt = date('c');
            $this->saveTableState($stateFile, $state);

            return BackfillResult::failed(
                schema: $schema,
                table: $table,
                error: $e->getMessage(),
                rowsCopied: $totalCopiedThisRun,
                totalRows: $state->totalRows,
                batchesExecuted: $batchesExecuted,
                durationSeconds: microtime(true) - $startTime,
                lastProcessedId: $state->lastProcessedId,
            );
        } finally {
            $this->restoreForeignKeys($tgtPdo, $tgtDriver);
        }
    }

    /**
     * Resolve candidate tables for backfill across schemas based on CLI arguments and manifest policies.
     *
     * @param  array<string, array{cluster: string, schema: string, connection: array}>  $schemaMap
     * @param  string[]  $specifiedTables
     * @return array<int, array{schema: string, cluster: string, table: string, policy: ?TablePolicy, connection: array}>
     */
    public function resolveCandidateTables(
        array $schemaMap,
        array $specifiedTables = [],
        ?MigrationManifest $manifest = null,
        bool $includeTransient = false,
        ?callable $tableInspector = null,
    ): array {
        $candidates = [];

        foreach ($schemaMap as $key => $info) {
            $schemaName = $info['schema'];
            $clusterName = $info['cluster'];
            $conn = $info['connection'];

            // Get tables defined in manifest for this schema
            $manifestPolicies = $manifest ? $manifest->getTablePoliciesForSchema($schemaName, $key) : [];

            // If explicit tables are provided via CLI
            if (! empty($specifiedTables)) {
                foreach ($specifiedTables as $tbl) {
                    $policy = $manifestPolicies[$tbl] ?? ($manifest ? $manifest->getTablePolicy($tbl, $schemaName, $key) : null);
                    $candidates[] = [
                        'schema' => $schemaName,
                        'cluster' => $clusterName,
                        'table' => $tbl,
                        'policy' => $policy,
                        'connection' => $conn,
                    ];
                }

                continue;
            }

            // Otherwise, discover candidate tables from manifest policies
            foreach ($manifestPolicies as $tbl => $policy) {
                if ($policy->isTransient() && ! $includeTransient) {
                    continue;
                }

                if ($policy->isSchemaOnly()) {
                    continue;
                }

                // Table marked chunked or ignore (massive historical data) are prime backfill candidates
                if ($policy->isChunked() || $policy->isIgnore() || ($policy->isTransient() && $includeTransient)) {
                    $candidates[] = [
                        'schema' => $schemaName,
                        'cluster' => $clusterName,
                        'table' => $tbl,
                        'policy' => $policy,
                        'connection' => $conn,
                    ];
                }
            }

            // If inspector callback provided (e.g. queries DB for all tables)
            if ($tableInspector) {
                $dbTables = $tableInspector($conn, $schemaName);
                foreach ($dbTables as $tbl) {
                    // Check if already in candidates
                    $alreadyAdded = false;
                    foreach ($candidates as $c) {
                        if ($c['schema'] === $schemaName && $c['table'] === $tbl) {
                            $alreadyAdded = true;
                            break;
                        }
                    }
                    if (! $alreadyAdded) {
                        $policy = $manifestPolicies[$tbl] ?? ($manifest ? $manifest->getTablePolicy($tbl, $schemaName, $key) : null);
                        if ($policy && ($policy->isTransient() && ! $includeTransient)) {
                            continue;
                        }
                        $candidates[] = [
                            'schema' => $schemaName,
                            'cluster' => $clusterName,
                            'table' => $tbl,
                            'policy' => $policy,
                            'connection' => $conn,
                        ];
                    }
                }
            }
        }

        return $candidates;
    }
}
