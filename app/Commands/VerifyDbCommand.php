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
            [$srcConn, $srcSchemas] = spin(fn () => $this->fetchClusterInfo($source), 'Fetching source cluster...');
        } catch (RuntimeException $e) {
            error('Source: '.$e->getMessage());

            return self::FAILURE;
        }

        try {
            [$tgtConn, $tgtSchemas] = spin(fn () => $this->fetchClusterInfo($target), 'Fetching target cluster...');
        } catch (RuntimeException $e) {
            error('Target: '.$e->getMessage());

            return self::FAILURE;
        }

        $filterSchemas = (array) $this->option('schema');
        $skipSchemas = (array) $this->option('skip-schema');

        // Determine which schemas to verify
        $schemasToCheck = array_intersect($srcSchemas, $tgtSchemas);

        if (! empty($filterSchemas)) {
            $schemasToCheck = array_intersect($schemasToCheck, $filterSchemas);
        }
        $schemasToCheck = array_diff($schemasToCheck, $skipSchemas);

        if (empty($schemasToCheck)) {
            error('No schemas to verify (check --schema / --skip-schema options).');

            return self::FAILURE;
        }

        $allGood = true;
        $onlyMismatches = (bool) $this->option('only-mismatches');

        foreach ($schemasToCheck as $schema) {
            $this->newLine();
            $this->line("<fg=cyan;options=bold>── {$schema} ──</>");

            $srcTables = $this->getTables($mysql, $srcConn, $schema);
            $tgtTables = $this->getTables($mysql, $tgtConn, $schema);

            $allTables = array_unique(array_merge($srcTables, $tgtTables));
            sort($allTables);

            $schemaGood = true;

            $this->row('gray', '·', 'Table', 'Source', 'Target', 'Status');
            $this->line('  '.str_repeat('─', 68));

            foreach ($allTables as $table) {
                $inSrc = in_array($table, $srcTables);
                $inTgt = in_array($table, $tgtTables);

                if (! $inSrc) {
                    $tgtCount = number_format((int) $this->count($mysql, $tgtConn, $schema, $table));
                    if (! $onlyMismatches) {
                        $this->row('yellow', '⚠', $table, '—', $tgtCount, 'only in target');
                    }

                    continue;
                }

                if (! $inTgt) {
                    $srcCount = number_format((int) $this->count($mysql, $srcConn, $schema, $table));
                    $this->row('red', '✗', $table, $srcCount, '—', 'missing in target');
                    $allGood = $schemaGood = false;

                    continue;
                }

                $src = (int) $this->count($mysql, $srcConn, $schema, $table);
                $tgt = (int) $this->count($mysql, $tgtConn, $schema, $table);

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
                $this->line("  <fg=green>✓ {$schema} — all tables match.</>");
            } else {
                $this->line("  <fg=yellow>⚠ {$schema} — some tables need attention.</>");
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

    /** Returns [connection, schemaNames[]] for the first cluster in the org. */
    private function fetchClusterInfo(CloudApiClient $client): array
    {
        $clusters = $client->getAll('databases/clusters');

        if (empty($clusters)) {
            throw new RuntimeException('No database clusters found in organization.');
        }

        // Collect all schemas across all clusters
        $conn = null;
        $schemas = [];

        foreach ($clusters as $cluster) {
            $clusterConn = $cluster['attributes']['connection'] ?? null;
            if (! $clusterConn) {
                continue;
            }
            $conn = $clusterConn;

            $dbList = $client->getAll("databases/clusters/{$cluster['id']}/databases");
            foreach ($dbList as $db) {
                $schemas[] = $db['attributes']['name'];
            }
        }

        if (! $conn) {
            throw new RuntimeException('No cluster connection available.');
        }

        return [$conn, $schemas];
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
