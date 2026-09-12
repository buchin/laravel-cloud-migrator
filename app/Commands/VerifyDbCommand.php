<?php

namespace App\Commands;

use App\Services\CloudApiClient;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function Laravel\Prompts\error;
use function Laravel\Prompts\password;
use function Laravel\Prompts\spin;

class VerifyDbCommand extends Command
{
    protected $signature = 'db:verify
                            {--source-token= : API token for the source organization}
                            {--target-token= : API token for the target organization}
                            {--schema=* : Only verify specific schemas (default: all)}
                            {--skip-schema=* : Skip these schemas}
                            {--only-mismatches : Only show tables with problems (hide green/gray rows)}';

    protected $description = 'Verify migrated database contents with exact COUNT(*) per table';

    /** @var array<string, string|null> */
    private array $binaryPaths = [];

    private static array $knownBinaryPaths = [
        '/opt/homebrew/opt/mysql-client/bin',
        '/opt/homebrew/opt/mysql-client@8.0/bin',
        '/usr/local/opt/mysql-client/bin',
        '/usr/local/bin',
        '/usr/bin',
    ];

    public function handle(): int
    {
        $this->newLine();

        $sourceToken = $this->option('source-token') ?: password(
            label: 'Source organization API token',
            placeholder: 'Paste your token here...',
            required: true,
        );

        $targetToken = $this->option('target-token') ?: password(
            label: 'Target organization API token',
            placeholder: 'Paste your token here...',
            required: true,
        );

        $source = new CloudApiClient($sourceToken);
        $target = new CloudApiClient($targetToken);

        $mysql = $this->findBinary('mysql');
        if (! $mysql) {
            error('mysql client not found — install MySQL client tools and retry.');

            return self::FAILURE;
        }

        try {
            $srcSchemas = spin(fn () => $this->fetchClusterSchemas($source), 'Fetching source cluster...');
        } catch (RuntimeException $e) {
            error('Source: '.$e->getMessage());

            return self::FAILURE;
        }

        try {
            $tgtSchemas = spin(fn () => $this->fetchClusterSchemas($target), 'Fetching target cluster...');
        } catch (RuntimeException $e) {
            error('Target: '.$e->getMessage());

            return self::FAILURE;
        }

        $filterSchemas = (array) $this->option('schema');
        $skipSchemas = (array) $this->option('skip-schema');

        $pairsToCheck = [];
        foreach ($tgtSchemas as $tgtKey => $tgtInfo) {
            if (isset($srcSchemas[$tgtKey])) {
                $pairsToCheck[$tgtKey] = [
                    'src' => $srcSchemas[$tgtKey],
                    'tgt' => $tgtInfo,
                ];
            } else {
                $matching = array_filter($srcSchemas, fn ($s) => $s['schema'] === $tgtInfo['schema']);
                if (count($matching) === 1) {
                    $pairsToCheck[$tgtKey] = [
                        'src' => reset($matching),
                        'tgt' => $tgtInfo,
                    ];
                }
            }
        }

        if (! empty($filterSchemas)) {
            $pairsToCheck = array_filter($pairsToCheck, function ($pair, $key) use ($filterSchemas) {
                return in_array($key, $filterSchemas, true)
                    || in_array($pair['tgt']['schema'], $filterSchemas, true);
            }, ARRAY_FILTER_USE_BOTH);
        }

        if (! empty($skipSchemas)) {
            $pairsToCheck = array_filter($pairsToCheck, function ($pair, $key) use ($skipSchemas) {
                return ! in_array($key, $skipSchemas, true)
                    && ! in_array($pair['tgt']['schema'], $skipSchemas, true);
            }, ARRAY_FILTER_USE_BOTH);
        }

        if (empty($pairsToCheck)) {
            error('No schemas to verify (check --schema / --skip-schema options).');

            return self::FAILURE;
        }

        $allGood = true;
        $onlyMismatches = (bool) $this->option('only-mismatches');

        foreach ($pairsToCheck as $pairKey => $pair) {
            $srcConn = $pair['src']['connection'];
            $srcSchema = $pair['src']['schema'];
            $tgtConn = $pair['tgt']['connection'];
            $tgtSchema = $pair['tgt']['schema'];
            $clusterName = $pair['tgt']['cluster'];

            $this->newLine();
            $this->line("<fg=cyan;options=bold>── {$clusterName}.{$tgtSchema} ──</>");

            $srcTables = $this->getTables($mysql, $srcConn, $srcSchema);
            $tgtTables = $this->getTables($mysql, $tgtConn, $tgtSchema);

            $allTables = array_unique(array_merge($srcTables, $tgtTables));
            sort($allTables);

            $schemaGood = true;

            $this->row('gray', '·', 'Table', 'Source', 'Target', 'Status');
            $this->line('  '.str_repeat('─', 68));

            foreach ($allTables as $table) {
                $inSrc = in_array($table, $srcTables);
                $inTgt = in_array($table, $tgtTables);

                if (! $inSrc) {
                    $tgtCount = number_format((int) $this->count($mysql, $tgtConn, $tgtSchema, $table));
                    if (! $onlyMismatches) {
                        $this->row('yellow', '⚠', $table, '—', $tgtCount, 'only in target');
                    }

                    continue;
                }

                if (! $inTgt) {
                    $srcCount = number_format((int) $this->count($mysql, $srcConn, $srcSchema, $table));
                    $this->row('red', '✗', $table, $srcCount, '—', 'missing in target');
                    $allGood = $schemaGood = false;

                    continue;
                }

                $src = (int) $this->count($mysql, $srcConn, $srcSchema, $table);
                $tgt = (int) $this->count($mysql, $tgtConn, $tgtSchema, $table);

                if ($this->isTransient($table)) {
                    // Transient tables (queues, caches, sessions) are expected to diverge.
                    if (! $onlyMismatches) {
                        $this->row('gray', '·', $table, number_format($src), number_format($tgt), 'transient (ok)');
                    }

                    continue;
                }

                [$icon, $color, $label] = $this->classify($src, $tgt);

                if ($color === 'red' || $color === 'yellow') {
                    $allGood = $schemaGood = false;
                }

                if (! $onlyMismatches || $color !== 'green') {
                    $this->row($color, $icon, $table, number_format($src), number_format($tgt), $label);
                }
            }

            $this->newLine();
            if ($schemaGood) {
                $this->line("  <fg=green>✓ {$clusterName}.{$tgtSchema} — all tables match.</>");
            } else {
                $this->line("  <fg=yellow>⚠ {$clusterName}.{$tgtSchema} — some tables need attention.</>");
            }
        }

        $this->newLine();
        $this->line(str_repeat('─', 70));
        $this->newLine();

        if ($allGood) {
            $this->line('<fg=green;options=bold>✓ All verified schemas match.</>');
        } else {
            $this->line('<fg=yellow>⚠  Some tables need attention — review above.</>');
        }

        $this->newLine();

        return $allGood ? self::SUCCESS : self::FAILURE;
    }

    /** Returns array<string, array{cluster: string, schema: string, connection: array, type: string}> */
    private function fetchClusterSchemas(CloudApiClient $client): array
    {
        $clusters = $client->getAll('databases/clusters');

        if (empty($clusters)) {
            throw new RuntimeException('No database clusters found in organization.');
        }

        $map = [];

        foreach ($clusters as $cluster) {
            $clusterConn = $cluster['attributes']['connection'] ?? null;
            $clusterName = $cluster['attributes']['name'] ?? $cluster['id'];
            $clusterType = $cluster['attributes']['type'] ?? 'mysql';

            if (! $clusterConn) {
                continue;
            }

            $dbList = $client->getAll("databases/clusters/{$cluster['id']}/databases");
            foreach ($dbList as $db) {
                $schemaName = $db['attributes']['name'];
                $key = "{$clusterName}.{$schemaName}";
                $map[$key] = [
                    'cluster' => $clusterName,
                    'schema' => $schemaName,
                    'connection' => $clusterConn,
                    'type' => $clusterType,
                ];
            }
        }

        if (empty($map)) {
            throw new RuntimeException('No cluster connection available.');
        }

        return $map;
    }

    private function getTables(string $mysql, array $conn, string $schema): array
    {
        $sql = 'SELECT TABLE_NAME FROM information_schema.TABLES'
            ." WHERE TABLE_SCHEMA='".addslashes($schema)."'"
            ." AND TABLE_TYPE='BASE TABLE'"
            .' ORDER BY TABLE_NAME';

        $out = $this->query($mysql, $conn, $sql);

        return array_values(array_filter(array_map('trim', $out)));
    }

    private function count(string $mysql, array $conn, string $schema, string $table): string
    {
        $out = $this->query($mysql, $conn, 'SELECT COUNT(*) FROM `'.addslashes($schema).'`.`'.addslashes($table).'`');

        return trim($out[0] ?? '0');
    }

    /** Render one table row without sprintf so % signs in labels are safe. */
    private function row(string $color, string $icon, string $table, string $src, string $tgt, string $label): void
    {
        $t = str_pad(mb_substr($table, 0, 38), 38);
        $s = str_pad($src, 10, ' ', STR_PAD_LEFT);
        $g = str_pad($tgt, 10, ' ', STR_PAD_LEFT);
        $this->line("  <fg={$color}>{$icon}</> {$t} {$s} {$g}  <fg={$color}>{$label}</>");
    }

    /** Tables whose contents are ephemeral and expected not to match after migration. */
    private function isTransient(string $table): bool
    {
        $transient = ['jobs', 'cache', 'cache_locks', 'sessions', 'job_batches'];

        return in_array($table, $transient, true);
    }

    /** @return array{string, string, string} [icon, color, label] */
    private function classify(int $src, int $tgt): array
    {
        if ($src === 0 && $tgt === 0) {
            return ['·', 'gray', 'empty'];
        }
        if ($tgt === $src) {
            return ['✓', 'green', 'exact'];
        }
        if ($src === 0) {
            return ['⚠', 'yellow', 'src empty, tgt has data'];
        }
        $pct = round($tgt / $src * 100);
        if ($tgt >= $src * 0.95) {
            return ['✓', 'green', "≥95% ({$pct}%)"];
        }
        if ($tgt === 0) {
            return ['✗', 'red', 'target empty'];
        }

        return ['⚠', 'yellow', "{$pct}% — incomplete"];
    }

    private function query(string $mysql, array $conn, string $sql): array
    {
        $cmd = escapeshellarg($mysql)
            .' --ssl-mode=DISABLED --batch --skip-column-names'
            .' -h '.escapeshellarg($conn['hostname'])
            .' -P '.(int) $conn['port']
            .' -u '.escapeshellarg($conn['username'])
            .' --password='.escapeshellarg($conn['password'])
            .' -e '.escapeshellarg($sql)
            .' 2>/dev/null';

        exec($cmd, $out);

        return $out;
    }

    private function findBinary(string $name): ?string
    {
        if (array_key_exists($name, $this->binaryPaths)) {
            return $this->binaryPaths[$name];
        }

        exec('which '.escapeshellarg($name).' 2>/dev/null', $out, $rc);
        if ($rc === 0 && ! empty($out[0])) {
            return $this->binaryPaths[$name] = trim($out[0]);
        }

        foreach (self::$knownBinaryPaths as $dir) {
            $path = $dir.'/'.$name;
            if (is_executable($path)) {
                return $this->binaryPaths[$name] = $path;
            }
        }

        return $this->binaryPaths[$name] = null;
    }
}
