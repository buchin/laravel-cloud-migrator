<?php

namespace App\Commands;

use App\Services\CloudApiClient;
use App\Services\MigrationService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\password;
use function Laravel\Prompts\spin;

class MigrateDbCommand extends Command
{
    protected $signature = 'migrate-db
                            {--source-token= : API token for the source organization}
                            {--target-token= : API token for the target organization}
                            {--skip-data=* : Skip data migration for specific schemas (e.g. --skip-data=nerd)}
                            {--ignore-table=* : Exclude specific tables, format: schema.table (e.g. --ignore-table=dojo.nerd_daily_report_urls)}
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

        $skipSchemas = (array) $this->option('skip-data');
        $ignoreTables = (array) $this->option('ignore-table');

        // Plan: match source schemas to target
        $pairs = [];
        $unmatched = [];

        foreach ($sourcePairs as $schemaName => $srcInfo) {
            if (in_array($schemaName, $skipSchemas, true)) {
                continue;
            }
            if (isset($targetConnMap[$schemaName])) {
                $pairs[] = [
                    'schema' => $schemaName,
                    'src_conn' => $srcInfo['connection'],
                    'tgt_conn' => $targetConnMap[$schemaName],
                    'db_type' => $srcInfo['type'],
                ];
            } else {
                $unmatched[] = $schemaName;
            }
        }

        $this->newLine();
        $this->line('<fg=cyan;options=bold>Database Migration Plan</>');
        $this->line(str_repeat('─', 50));
        $this->newLine();

        foreach ($pairs as $pair) {
            $schemaIgnoreTables = $this->resolveIgnoreTables($pair['schema'], $ignoreTables);
            $ignoreNote = $schemaIgnoreTables ? ' <fg=gray>(excluding: '.implode(', ', $schemaIgnoreTables).')</>' : '';
            $this->line("  <fg=green>✓</> <fg=cyan>{$pair['schema']}</>{$ignoreNote}");
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

        $service = new MigrationService($source, $target);
        $anyFailed = false;

        foreach ($pairs as $pair) {
            $schemaName = $pair['schema'];
            $schemaIgnoreTables = $this->resolveIgnoreTables($schemaName, $ignoreTables);

            $this->newLine();
            $this->line("<fg=cyan;options=bold>── {$schemaName} ──</>");

            // Wait for target cluster to be available (poll connection endpoint).
            $tgtConn = $pair['tgt_conn'];

            try {
                $service->runDatabaseMigration(
                    srcConn: $pair['src_conn'],
                    srcDb: $schemaName,
                    tgtConn: $tgtConn,
                    tgtDb: $schemaName,
                    dbType: $pair['db_type'],
                    progress: function (string $message) {
                        $this->line("  <fg=green>✓</> {$message}");
                    },
                    ignoreTables: $schemaIgnoreTables,
                );
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

    /** source schemaName → {connection, type} */
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
            if (! $conn) {
                continue;
            }

            try {
                $schemas = $source->getAll("databases/clusters/{$cluster['id']}/databases");
                foreach ($schemas as $schema) {
                    $map[$schema['attributes']['name']] = [
                        'connection' => $conn,
                        'type' => $type,
                    ];
                }
            } catch (RuntimeException) {
            }
        }

        return $map;
    }

    /** target schemaName → connection */
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
                    $map[$schema['attributes']['name']] = $conn;
                }
            } catch (RuntimeException) {
            }
        }

        return $map;
    }

    /** Resolve which tables to ignore for a given schema from the --ignore-table list. */
    private function resolveIgnoreTables(string $schema, array $ignoreTables): array
    {
        $result = [];
        foreach ($ignoreTables as $entry) {
            if (str_contains($entry, '.')) {
                [$s, $table] = explode('.', $entry, 2);
                if ($s === $schema) {
                    $result[] = $table;
                }
            } else {
                $result[] = $entry;
            }
        }

        return $result;
    }
}
