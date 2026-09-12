<?php

namespace App\Commands;

use App\Data\BackfillTableState;
use App\Services\CloudApiClient;
use App\Services\DbBackfillService;
use App\Services\MigrationManifest;
use LaravelZero\Framework\Commands\Command;
use PDO;
use RuntimeException;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\password;
use function Laravel\Prompts\spin;

class BackfillDbCommand extends Command
{
    protected $signature = 'db:backfill
                            {--source-token= : API token for the source organization}
                            {--target-token= : API token for the target organization}
                            {--schema=* : Specific schema(s) or cluster.schema to backfill}
                            {--table=* : Specific table(s) to backfill}
                            {--batch-size= : Batch size in rows (default: 5000 or table manifest policy)}
                            {--sleep-ms=50 : Throttling sleep between batches in milliseconds (default: 50)}
                            {--resume : Resume previous backfill from checkpoint state file}
                            {--state-file=.backfill-state.json : Path to checkpoint state JSON file}
                            {--manifest= : Path to declarative migration manifest file (default: migration-plan.json if exists)}
                            {--adaptive : Enable dynamic adaptive batch sizing based on query latency (default: true)}
                            {--no-adaptive : Disable dynamic adaptive batch sizing}
                            {--include-transient : Include tables marked transient in backfill}
                            {--force : Force re-run from scratch ignoring previous checkpoint}
                            {--dry-run : Preview backfill plan and table chunks without copying data}
                            {--yes : Skip confirmation prompt}';

    protected $description = 'Backfill historical database data in non-blocking batches with adaptive throttling and checkpointing';

    public function handle(): int
    {
        $this->newLine();
        info('Laravel Cloud Migrator — Background Database Data Backfill');
        $this->newLine();

        $sourceToken = $this->option('source-token') ?: (getenv('CLOUD_SOURCE_TOKEN') ?: password(
            label: 'Source organization API token',
            placeholder: 'Paste your token here...',
            required: true,
        ));

        $targetToken = $this->option('target-token') ?: (getenv('CLOUD_TARGET_TOKEN') ?: password(
            label: 'Target organization API token',
            placeholder: 'Paste your token here...',
            required: true,
        ));

        $manifestPath = $this->option('manifest');
        $manifest = null;
        if ($manifestPath) {
            if (! file_exists($manifestPath)) {
                error("Specified manifest file does not exist: {$manifestPath}");

                return self::FAILURE;
            }
            $manifest = MigrationManifest::fromFile($manifestPath);
            info("Loaded migration manifest from {$manifestPath} ({$manifest->countTotalTableRules()} table rules)");
        } elseif (file_exists('migration-plan.json')) {
            $manifest = MigrationManifest::fromFile('migration-plan.json');
            info("Loaded declarative migration manifest from migration-plan.json ({$manifest->countTotalTableRules()} table rules)");
        }

        $source = $this->makeClient($sourceToken);
        $target = $this->makeClient($targetToken);
        $service = $this->makeService();
        if ($manifest) {
            $service->setManifest($manifest);
        }

        // Fetch source and target database clusters
        try {
            $sourceClusters = spin(fn () => $source->getAll('databases/clusters'), 'Fetching source database clusters...');
        } catch (RuntimeException $e) {
            error("Source API error: {$e->getMessage()}");

            return self::FAILURE;
        }

        try {
            $targetClusters = spin(fn () => $target->getAll('databases/clusters'), 'Fetching target database clusters...');
        } catch (RuntimeException $e) {
            error("Target API error: {$e->getMessage()}");

            return self::FAILURE;
        }

        // Build source schema map: "cluster.schema" or "schema" → info
        $sourceSchemaMap = [];
        foreach ($sourceClusters as $cluster) {
            $conn = $cluster['attributes']['connection'] ?? null;
            $clusterName = $cluster['attributes']['name'] ?? $cluster['id'];
            if (! $conn) {
                continue;
            }

            try {
                $schemas = $source->getAll("databases/clusters/{$cluster['id']}/databases");
                foreach ($schemas as $schema) {
                    $schemaName = $schema['attributes']['name'];
                    $sourceSchemaMap["{$clusterName}.{$schemaName}"] = [
                        'cluster' => $clusterName,
                        'schema' => $schemaName,
                        'connection' => $conn,
                    ];
                }
            } catch (RuntimeException) {
            }
        }

        // Build target schema map
        $targetSchemaMap = [];
        foreach ($targetClusters as $cluster) {
            $conn = $cluster['attributes']['connection'] ?? null;
            $clusterName = $cluster['attributes']['name'] ?? $cluster['id'];
            if (! $conn) {
                continue;
            }

            try {
                $schemas = $target->getAll("databases/clusters/{$cluster['id']}/databases");
                foreach ($schemas as $schema) {
                    $schemaName = $schema['attributes']['name'];
                    $targetSchemaMap["{$clusterName}.{$schemaName}"] = [
                        'cluster' => $clusterName,
                        'schema' => $schemaName,
                        'connection' => $conn,
                    ];
                }
            } catch (RuntimeException) {
            }
        }

        $schemaFilters = (array) $this->option('schema');
        $specifiedTables = (array) $this->option('table');
        $includeTransient = (bool) $this->option('include-transient');
        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $cliBatchSize = $this->option('batch-size') ? (int) $this->option('batch-size') : null;
        $resume = (bool) $this->option('resume');
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');
        $stateFile = (string) ($this->option('state-file') ?: '.backfill-state.json');
        $adaptive = ! $this->option('no-adaptive');

        // Filter schemas if requested
        $matchedSchemas = [];
        foreach ($sourceSchemaMap as $key => $srcInfo) {
            $schemaName = $srcInfo['schema'];
            if (! empty($schemaFilters)) {
                if (! in_array($key, $schemaFilters, true) && ! in_array($schemaName, $schemaFilters, true)) {
                    continue;
                }
            }

            // Find matching target schema
            $targetInfo = $targetSchemaMap[$key] ?? null;
            if (! $targetInfo) {
                // Fallback: match by schema name alone
                foreach ($targetSchemaMap as $tKey => $tInfo) {
                    if ($tInfo['schema'] === $schemaName) {
                        $targetInfo = $tInfo;
                        break;
                    }
                }
            }

            if ($targetInfo) {
                $matchedSchemas[$key] = [
                    'source' => $srcInfo,
                    'target' => $targetInfo,
                ];
            }
        }

        if (empty($matchedSchemas)) {
            error('No matching database schemas found between source and target organizations.');

            return self::FAILURE;
        }

        // Resolve candidate tables across matched schemas
        $sourceSchemaSubset = [];
        foreach ($matchedSchemas as $key => $pair) {
            $sourceSchemaSubset[$key] = $pair['source'];
        }

        $candidateItems = $service->resolveCandidateTables(
            schemaMap: $sourceSchemaSubset,
            specifiedTables: $specifiedTables,
            manifest: $manifest,
            includeTransient: $includeTransient,
        );

        if (empty($candidateItems)) {
            $this->line('<fg=yellow>No backfill candidate tables found for the selected schemas.</>');
            note('Tip: Specify --table=<table_name> or configure "chunked"/"ignore" policies in migration-plan.json.');

            return self::SUCCESS;
        }

        // Load existing checkpoint state
        $existingState = $service->loadState($stateFile);

        $this->line('<fg=cyan;options=bold>Background Data Backfill Plan</>');
        $this->line(str_repeat('─', 65));
        $this->newLine();

        $planRows = [];
        foreach ($candidateItems as $item) {
            $key = "{$item['schema']}.{$item['table']}";
            $policy = $item['policy'];
            $policyLabel = $policy ? $policy->policy : 'full';
            $effBatchSize = $cliBatchSize ?? ($policy?->chunkSize ?? 5000);

            $tableState = isset($existingState['tables'][$key])
                ? BackfillTableState::fromArray($existingState['tables'][$key])
                : null;

            $statusNote = 'Fresh';
            if ($tableState) {
                if ($tableState->isCompleted()) {
                    $statusNote = $force ? '<fg=yellow>Completed (re-running --force)</>' : '<fg=green>Completed (will skip)</>';
                } elseif ($resume) {
                    $lastId = $tableState->lastProcessedId !== null ? "ID {$tableState->lastProcessedId}" : "offset {$tableState->currentOffset}";
                    $statusNote = "<fg=cyan>Resume at {$lastId}</>";
                } else {
                    $statusNote = '<fg=yellow>Interrupted checkpoint exists</>';
                }
            }

            $planRows[] = [
                'Schema' => "{$item['cluster']}.{$item['schema']}",
                'Table' => $item['table'],
                'Policy' => $policyLabel,
                'Batch Size' => number_format($effBatchSize),
                'Sleep (ms)' => "{$sleepMs} ms",
                'State' => $statusNote,
            ];
        }

        $this->table(
            ['Schema', 'Table', 'Policy', 'Batch Size', 'Throttling', 'Checkpoint State'],
            $planRows
        );

        if ($dryRun) {
            $this->newLine();
            $this->line('<fg=yellow;options=bold>⚡ DRY RUN MODE — No data will be copied.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        if (! $this->option('yes') && ! confirm('Proceed with background data backfill?', default: true)) {
            info('Backfill cancelled.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<fg=cyan;options=bold>Executing Data Backfill Pipeline</>');
        $this->line(str_repeat('─', 65));

        // Signal handling for graceful interruption
        $interrupted = false;
        if (extension_loaded('pcntl') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function () use (&$interrupted) {
                $interrupted = true;
            });
            pcntl_signal(SIGTERM, function () use (&$interrupted) {
                $interrupted = true;
            });
        }

        $shouldStop = function () use (&$interrupted) {
            return $interrupted;
        };

        $results = [];
        $hasFailures = false;
        $totalRowsBackfilled = 0;
        $totalBatches = 0;
        $startTime = microtime(true);

        foreach ($candidateItems as $item) {
            if ($interrupted) {
                $this->line('  <fg=yellow>⚠ Stop requested. Halting remaining tables.</>');
                break;
            }

            $schema = $item['schema'];
            $cluster = $item['cluster'];
            $table = $item['table'];
            $policy = $item['policy'];
            $schemaKey = "{$cluster}.{$schema}";
            $targetConn = $matchedSchemas[$schemaKey]['target']['connection'];

            $this->newLine();
            $this->line("<fg=cyan;options=bold>── Backfilling {$schema}.{$table} ──</>");

            try {
                $srcPdo = $this->makePdo($item['connection'], $schema, 'source');
                $tgtPdo = $this->makePdo($targetConn, $schema, 'target');
            } catch (Throwable $e) {
                error("Could not connect to database for schema {$schema}: {$e->getMessage()}");
                $hasFailures = true;

                continue;
            }

            $batchSize = $cliBatchSize ?? ($policy?->chunkSize ?? 5000);

            $result = $service->backfillTable(
                srcPdo: $srcPdo,
                tgtPdo: $tgtPdo,
                schema: $schema,
                table: $table,
                initialBatchSize: $batchSize,
                sleepMs: $sleepMs,
                resume: $resume,
                stateFile: $stateFile,
                adaptive: $adaptive,
                progress: function (string $msg) {
                    $this->line($msg);
                },
                shouldStop: $shouldStop,
                policy: $policy,
                force: $force,
            );

            $results[] = $result;
            $totalRowsBackfilled += $result->rowsCopied;
            $totalBatches += $result->batchesExecuted;

            if ($result->status === 'failed') {
                $hasFailures = true;
                $this->line("  <fg=red>✗ Failed:</> {$result->error}");
            } elseif ($result->status === 'interrupted') {
                $this->line("  <fg=yellow>⚠ Interrupted:</> Checkpoint saved at last ID {$result->lastProcessedId}");
                break;
            }
        }

        $totalDuration = microtime(true) - $startTime;

        $this->newLine();
        $this->line(str_repeat('─', 65));
        $this->newLine();

        if ($interrupted) {
            $this->line('<fg=yellow;options=bold>⚠  Backfill paused by user or signal.</>');
            $this->line("   Checkpoint state saved to <fg=cyan>{$stateFile}</>.");
            $this->line('   Run <fg=green>./cloud-migrator db:backfill --resume</> to continue from where you left off.');
            $this->newLine();

            return self::SUCCESS;
        }

        if ($hasFailures) {
            $this->line('<fg=red;options=bold>✗ Backfill completed with some errors. Check logs above.</>');
            $this->newLine();

            return self::FAILURE;
        }

        $formattedRows = number_format($totalRowsBackfilled);
        $formattedDuration = round($totalDuration, 2);
        $this->line("<fg=green;options=bold>✓ Backfill completed successfully!</> Copied {$formattedRows} rows across {$totalBatches} batches in {$formattedDuration}s.");
        $this->line("  State recorded in <fg=cyan>{$stateFile}</>.");
        $this->newLine();

        return self::SUCCESS;
    }

    protected function makeClient(string $token): CloudApiClient
    {
        if ($this->laravel->has("CloudApiClient.{$token}")) {
            return $this->laravel->make("CloudApiClient.{$token}");
        }

        if ($this->laravel->has(CloudApiClient::class)) {
            return $this->laravel->make(CloudApiClient::class);
        }

        return new CloudApiClient($token);
    }

    protected function makeService(): DbBackfillService
    {
        if ($this->laravel->has(DbBackfillService::class)) {
            return $this->laravel->make(DbBackfillService::class);
        }

        return new DbBackfillService;
    }

    protected function makePdo(array $conn, string $database, string $side = 'source'): PDO
    {
        $key = "PDO.{$side}.{$database}";
        if ($this->laravel->has($key)) {
            return $this->laravel->make($key);
        }

        if ($this->laravel->has("PDO.{$side}")) {
            return $this->laravel->make("PDO.{$side}");
        }

        if ($this->laravel->has(PDO::class)) {
            return $this->laravel->make(PDO::class);
        }

        return $this->makeService()->connect($conn, $database);
    }
}
