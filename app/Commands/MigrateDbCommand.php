<?php

namespace App\Commands;

use App\Services\CloudApiClient;
use App\Services\MigrationManifest;
use App\Services\MigrationService;
use App\Services\TableChunker;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\password;
use function Laravel\Prompts\spin;

class MigrateDbCommand extends Command
{
    protected $signature = 'db:migrate
                            {--source-token= : API token for the source organization}
                            {--target-token= : API token for the target organization}
                            {--schema=* : Specific schema(s) to migrate, format: schema or cluster.schema (e.g. --schema=dracin_api.main)}
                            {--skip-data=* : Skip data migration for specific schemas (e.g. --skip-data=nerd)}
                            {--ignore-table=* : Exclude specific tables, format: schema.table (e.g. --ignore-table=dojo.nerd_daily_report_urls)}
                            {--concurrency=4 : Parallel table dump workers for MySQL (default: 4)}
                            {--chunk-size=50000 : Chunk size in rows for dynamic auto-chunking (default: 50000)}
                            {--show-tables : Show per-table progress lines (default: schema-level summary only)}
                            {--manifest= : Path to declarative migration manifest file (default: migration-plan.json if exists)}
                            {--yes : Skip confirmation prompt and proceed automatically}';

    protected $description = 'Migrate database data between organizations (for apps already migrated)';

    public function handle(): int
    {
        $this->newLine();
        info('Laravel Cloud Migrator — Database Data Migration');
        $this->newLine();

        $sourceToken = $this->option('source-token') ?: password(
            label: 'Source organization API token',
            placeholder: 'Paste your token here...',
            hint: 'Get this from cloud.laravel.com → Your Org → Settings → API Tokens',
            required: true,
        );

        $targetToken = $this->option('target-token') ?: password(
            label: 'Target organization API token',
            placeholder: 'Paste your token here...',
            hint: 'Get this from cloud.laravel.com → Your Org → Settings → API Tokens',
            required: true,
        );

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

        $source = new CloudApiClient($sourceToken);
        $target = new CloudApiClient($targetToken);

        // Build source schema map: schemaName → {connection, type}
        $sourcePairs = spin(fn () => $this->buildSourcePairs($source), 'Fetching source database clusters...');
        if (empty($sourcePairs)) {
            error('No database schemas found in source organization.');

            return self::FAILURE;
        }

        // Build target connection map: schemaName → connection
        $targetConnMap = spin(fn () => $this->buildTargetConnMap($target), 'Fetching target database clusters...');
        if (empty($targetConnMap)) {
            error('No database clusters found in target organization.');

            return self::FAILURE;
        }

        $filterSchemas = (array) $this->option('schema');
        $skipSchemas = (array) $this->option('skip-data');
        $ignoreTables = (array) $this->option('ignore-table');

        // Plan: match source schemas to target
        $pairs = [];
        $unmatched = [];

        foreach ($sourcePairs as $key => $srcInfo) {
            $schemaName = $srcInfo['schema_name'];
            if (! empty($filterSchemas)) {
                if (! in_array($key, $filterSchemas, true) && ! in_array($schemaName, $filterSchemas, true)) {
                    continue;
                }
            }
            if (in_array($key, $skipSchemas, true) || in_array($schemaName, $skipSchemas, true)) {
                continue;
            }
            if (isset($targetConnMap[$key])) {
                $pairs[] = [
                    'key' => $key,
                    'schema' => $schemaName,
                    'src_conn' => $srcInfo['connection'],
                    'tgt_conn' => $targetConnMap[$key],
                    'db_type' => $srcInfo['type'],
                ];
            } else {
                $unmatched[] = $key;
            }
        }

        $this->newLine();
        $this->line('<fg=cyan;options=bold>Database Migration Plan</>');
        $this->line(str_repeat('─', 50));
        $this->newLine();

        $chunkSize = max(1000, (int) ($this->option('chunk-size') ?: TableChunker::DEFAULT_CHUNK_SIZE));

        foreach ($pairs as $pair) {
            $tablePolicies = $manifest ? $manifest->getTablePoliciesForSchema($pair['schema'], $pair['key']) : [];
            $schemaIgnoreTables = $this->resolveIgnoreTables($pair['schema'], $ignoreTables, $pair['key'], $manifest);
            $ignoreNote = $schemaIgnoreTables ? ' <fg=gray>(excluding: '.implode(', ', $schemaIgnoreTables).')</>' : '';
            $this->line("  <fg=green>✓</> <fg=cyan>{$pair['key']}</>{$ignoreNote}");

            // Display manifest table policies if configured
            if (! empty($tablePolicies)) {
                $policyNotes = [];
                foreach ($tablePolicies as $tbl => $pol) {
                    if ($pol->isSchemaOnly()) {
                        $policyNotes[] = "<fg=cyan>{$tbl}</> (schema_only)";
                    } elseif ($pol->isChunked()) {
                        $cSize = $pol->chunkSize ?? $chunkSize;
                        $policyNotes[] = "<fg=cyan>{$tbl}</> (chunked: {$cSize})";
                    } elseif ($pol->isTransient()) {
                        $policyNotes[] = "<fg=yellow>{$tbl}</> (transient)";
                    } elseif ($pol->isIgnore()) {
                        $policyNotes[] = "<fg=gray>{$tbl}</> (ignored)";
                    }
                }
                if (! empty($policyNotes)) {
                    $this->line('    <fg=magenta>📋</> Manifest policies: '.implode(', ', $policyNotes));
                }
            }

            // Detect large tables (>1 GB or >100k rows) in MySQL schemas
            if (! str_contains($pair['db_type'], 'pgsql') && ! str_contains($pair['db_type'], 'postgres')) {
                $largeTables = $this->detectLargeTables($pair['src_conn'], $pair['schema'], $schemaIgnoreTables, tablePolicies: $tablePolicies);
                if (! empty($largeTables)) {
                    $largeCount = count($largeTables);
                    $this->line("    <fg=yellow>⚡</> Auto-chunking active: {$largeCount} table(s) exceed >1 GB or >100k rows:");
                    foreach ($largeTables as $table => $metrics) {
                        $sizeFormatted = $this->formatBytes($metrics['total_bytes']);
                        $rowsFormatted = number_format($metrics['row_count']);
                        $strategy = $metrics['strategy'] === 'pk_range'
                            ? "PK range (`{$metrics['pk_column']}`)"
                            : 'limit-offset';
                        $this->line("       • <fg=cyan>{$table}</> ({$sizeFormatted}, {$rowsFormatted} rows) → {$strategy}");
                    }
                }
            }
        }

        if (! empty($skipSchemas)) {
            foreach ($skipSchemas as $s) {
                $this->line("  <fg=gray>·</> <fg=gray>{$s}</> — skipped via --skip-data");
            }
        }

        if (! empty($unmatched)) {
            foreach ($unmatched as $s) {
                $this->line("  <fg=yellow>⚠</> <fg=yellow>{$s}</> — not found in target org (skipped)");
            }
        }

        $this->newLine();
        $this->line('<fg=yellow>Note:</> Data routes through this machine — large databases may take a while.');
        $this->newLine();

        if (empty($pairs)) {
            error('Nothing to migrate.');

            return self::FAILURE;
        }

        if (! $this->option('yes') && ! confirm('Proceed with database migration?', default: false)) {
            info('Cancelled.');

            return self::SUCCESS;
        }

        $service = new MigrationService($source, $target, manifest: $manifest);
        $anyFailed = false;
        $verbose = (bool) $this->option('show-tables');
        $concurrency = max(1, (int) ($this->option('concurrency') ?? 4));

        foreach ($pairs as $pair) {
            $schemaName = $pair['schema'];
            $tablePolicies = $manifest ? $manifest->getTablePoliciesForSchema($schemaName, $pair['key']) : [];
            $schemaIgnoreTables = $this->resolveIgnoreTables($schemaName, $ignoreTables, $pair['key'], $manifest);

            $this->newLine();
            $this->line("<fg=cyan;options=bold>── {$pair['key']} ──</>");

            $tgtConn = $pair['tgt_conn'];
            $tableCount = 0;

            try {
                $service->runDatabaseMigration(
                    srcConn: $pair['src_conn'],
                    srcDb: $schemaName,
                    tgtConn: $tgtConn,
                    tgtDb: $schemaName,
                    dbType: $pair['db_type'],
                    progress: function (string $message) use ($verbose, &$tableCount) {
                        // Per-table lines look like "  Migrated tablename (N rows)" or "  ✓ tablename [part 1/9]"
                        $isTableLine = str_starts_with(ltrim($message), 'Migrated ') || str_contains($message, '[part ');
                        if ($isTableLine) {
                            $tableCount++;
                            if ($verbose || str_contains($message, '[part ')) {
                                $this->line("  <fg=green>✓</> {$message}");
                            }
                        } else {
                            $this->line("  <fg=green>✓</> {$message}");
                        }
                    },
                    ignoreTables: $schemaIgnoreTables,
                    concurrency: $concurrency,
                    chunkSize: $chunkSize,
                    tablePolicies: $tablePolicies,
                );

                if (! $verbose && $tableCount > 0) {
                    $this->line("  <fg=green>✓</> {$tableCount} table(s) migrated.");
                }
            } catch (RuntimeException $e) {
                $this->newLine();
                $this->line("  <fg=red>✗</> Failed: {$e->getMessage()}");
                $anyFailed = true;
            }
        }

        $this->newLine();

        if ($anyFailed) {
            $this->line('<fg=yellow>⚠  Some schemas failed — review above.</>');
        } else {
            $this->line('<fg=green;options=bold>✓ All schemas migrated.</>');
        }

        $this->newLine();

        return $anyFailed ? self::FAILURE : self::SUCCESS;
    }

    /** source "clusterName.schemaName" → {connection, type, schema_name} */
    private function buildSourcePairs(CloudApiClient $source): array
    {
        $map = [];

        try {
            $clusters = $source->getAll('databases/clusters');
        } catch (RuntimeException) {
            return $map;
        }

        foreach ($clusters as $cluster) {
            $conn = $cluster['attributes']['connection'] ?? null;
            $type = $cluster['attributes']['type'] ?? 'mysql';
            $clusterName = $cluster['attributes']['name'] ?? $cluster['id'];
            if (! $conn) {
                continue;
            }

            try {
                $schemas = $source->getAll("databases/clusters/{$cluster['id']}/databases");
                foreach ($schemas as $schema) {
                    $schemaName = $schema['attributes']['name'];
                    $map["{$clusterName}.{$schemaName}"] = [
                        'connection' => $conn,
                        'type' => $type,
                        'schema_name' => $schemaName,
                    ];
                }
            } catch (RuntimeException) {
            }
        }

        return $map;
    }

    /** target "clusterName.schemaName" → connection */
    private function buildTargetConnMap(CloudApiClient $target): array
    {
        $map = [];

        try {
            $clusters = $target->getAll('databases/clusters');
        } catch (RuntimeException) {
            return $map;
        }

        foreach ($clusters as $cluster) {
            $conn = $cluster['attributes']['connection'] ?? null;
            $clusterName = $cluster['attributes']['name'] ?? $cluster['id'];
            if (! $conn) {
                continue;
            }

            // Poll until available so we get a real connection object.
            $waited = 0;
            while ($waited < 120) {
                $status = $cluster['attributes']['status'] ?? 'available';
                if ($status === 'available') {
                    break;
                }
                sleep(5);
                $waited += 5;
                $cluster = $target->get("databases/clusters/{$cluster['id']}")['data'];
                $conn = $cluster['attributes']['connection'] ?? null;
            }

            if (! $conn) {
                continue;
            }

            try {
                $schemas = $target->getAll("databases/clusters/{$cluster['id']}/databases");
                foreach ($schemas as $schema) {
                    $map["{$clusterName}.{$schema['attributes']['name']}"] = $conn;
                }
            } catch (RuntimeException) {
            }
        }

        return $map;
    }

    /** Resolve which tables to ignore for a given schema from the --ignore-table list and manifest. */
    private function resolveIgnoreTables(string $schema, array $ignoreTables, ?string $key = null, ?MigrationManifest $manifest = null): array
    {
        $result = [];
        $clusterName = $key && str_contains($key, '.') ? explode('.', $key)[0] : null;

        foreach ($ignoreTables as $entry) {
            if (str_contains($entry, '.')) {
                [$s, $table] = explode('.', $entry, 2);
                if ($s === $schema || ($clusterName && $s === $clusterName)) {
                    $result[] = $table;
                }
            } else {
                $result[] = $entry;
            }
        }

        if ($manifest) {
            $manifestIgnores = $manifest->getIgnoreTables($schema, $clusterName);
            foreach ($manifestIgnores as $mTable) {
                if (! in_array($mTable, $result, true)) {
                    $result[] = $mTable;
                }
            }
        }

        return $result;
    }

    public function getTableChunker(): TableChunker
    {
        return new TableChunker;
    }

    public function detectLargeTables(
        array $conn,
        string $schemaName,
        array $ignoreTables = [],
        ?TableChunker $chunker = null,
        array $tablePolicies = []
    ): array {
        $chunker = $chunker ?? $this->getTableChunker();
        $mysql = $this->findBinary('mysql');
        if (! $mysql) {
            return [];
        }

        try {
            $inspected = $chunker->inspectTables($mysql, $conn, $schemaName, $ignoreTables, tablePolicies: $tablePolicies);

            return array_filter($inspected, fn (array $tableInfo) => $tableInfo['should_chunk']);
        } catch (\Throwable) {
            return [];
        }
    }

    public function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2).' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2).' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2).' KB';
        }

        return $bytes.' B';
    }

    private function findBinary(string $name): ?string
    {
        exec('which '.escapeshellarg($name).' 2>/dev/null', $out, $rc);
        if ($rc === 0 && ! empty($out[0])) {
            return trim($out[0]);
        }

        $knownPaths = [
            '/opt/homebrew/opt/mysql-client/bin',
            '/opt/homebrew/opt/mysql-client@8.0/bin',
            '/usr/local/opt/mysql-client/bin',
            '/usr/local/bin',
            '/usr/bin',
        ];

        foreach ($knownPaths as $dir) {
            $path = $dir.'/'.$name;
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }
}
