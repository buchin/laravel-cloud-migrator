<?php

namespace App\Commands;

use App\Services\CloudApiClient;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function Laravel\Prompts\error;
use function Laravel\Prompts\password;
use function Laravel\Prompts\spin;

class StatusCommand extends Command
{
    protected $signature = 'status
                            {--source-token= : API token for the source organization}
                            {--target-token= : API token for the target organization}';

    protected $description = 'Compare source and target organizations to verify migration status';

    /** @var array<string, string|null> binary name → resolved full path */
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

        try {
            $sourceApps = spin(fn () => $source->getAll('applications'), 'Fetching source apps...');
        } catch (RuntimeException $e) {
            error('Source token invalid: '.$e->getMessage());

            return self::FAILURE;
        }

        try {
            $targetApps = spin(fn () => $target->getAll('applications'), 'Fetching target apps...');
        } catch (RuntimeException $e) {
            error('Target token invalid: '.$e->getMessage());

            return self::FAILURE;
        }

        // Build name → app map for target
        $targetByName = [];
        foreach ($targetApps as $app) {
            $targetByName[$app['attributes']['name']] = $app;
        }

        // Build source cluster connection map: schemaId → connection + dbName
        $sourceConnMap = $this->buildSourceConnMap($source);

        // Build target cluster connection map: schemaName → connection
        $targetConnMap = $this->buildTargetConnMap($target);

        $this->newLine();
        $this->line('<fg=cyan;options=bold>Migration Status</>');
        $this->line(str_repeat('─', 70));

        $allGood = true;

        foreach ($sourceApps as $srcApp) {
            $appName = $srcApp['attributes']['name'];
            $this->newLine();

            $tgtApp = $targetByName[$appName] ?? null;

            if (! $tgtApp) {
                $this->line("<fg=red>✗</> <fg=yellow;options=bold>{$appName}</> — <fg=red>not found in target</>");
                $allGood = false;

                continue;
            }

            $tgtSlug = $tgtApp['attributes']['slug'];
            $slugNote = ($tgtSlug !== $srcApp['attributes']['slug'])
                ? " <fg=gray>(slug: {$tgtSlug})</>"
                : '';

            $this->line("<fg=green>✓</> <fg=yellow;options=bold>{$appName}</>{$slugNote}");

            // Fetch environments from both sides
            $srcEnvs = $source->getAll("applications/{$srcApp['id']}/environments", ['include' => 'database,cache']);
            $tgtEnvs = $target->getAll("applications/{$tgtApp['id']}/environments", ['include' => 'database,cache']);

            $tgtEnvByName = [];
            foreach ($tgtEnvs as $e) {
                $tgtEnvByName[$e['attributes']['name']] = $e;
            }

            foreach ($srcEnvs as $srcEnv) {
                $envName = $srcEnv['attributes']['name'];
                $tgtEnv = $tgtEnvByName[$envName] ?? null;

                if (! $tgtEnv) {
                    $this->line("  <fg=red>✗</> env <fg=cyan>{$envName}</> — not found in target");
                    $allGood = false;

                    continue;
                }

                $this->line("  <fg=cyan>Environment: {$envName}</>");

                // Env vars
                $srcVars = $srcEnv['attributes']['environment_variables'] ?? [];
                $tgtVars = $tgtEnv['attributes']['environment_variables'] ?? [];
                $srcCount = count($srcVars);
                $tgtCount = count($tgtVars);

                if ($srcCount === $tgtCount) {
                    $this->line("    <fg=green>✓</> Env vars: {$tgtCount}/{$srcCount}");
                } else {
                    $this->line("    <fg=yellow>⚠</> Env vars: {$tgtCount}/{$srcCount} — <fg=yellow>mismatch</>");
                    $allGood = false;
                }

                // Database rows
                $schemaId = $srcEnv['relationships']['database']['data']['id'] ?? null;
                if ($schemaId && isset($sourceConnMap[$schemaId])) {
                    $srcInfo = $sourceConnMap[$schemaId];
                    $dbName = $srcInfo['db_name'];
                    $srcConn = $srcInfo['connection'];
                    $tgtConn = $targetConnMap[$dbName] ?? null;

                    $srcRows = $this->getRowCount($srcConn, $dbName);
                    $tgtRows = $tgtConn ? $this->getRowCount($tgtConn, $dbName) : null;

                    $this->renderDbStatus($dbName, $srcRows, $tgtRows);

                    if ($srcRows !== null && $tgtRows !== null && $tgtRows < $srcRows * 0.95) {
                        $allGood = false;
                    }
                }

                // Deployment status
                $this->renderDeployStatus($target, $tgtEnv['id']);
            }
        }

        $this->newLine();
        $this->line(str_repeat('─', 70));

        if ($allGood) {
            $this->newLine();
            $this->line('<fg=green;options=bold>✓ All apps migrated — no issues detected.</>');
        } else {
            $this->newLine();
            $this->line('<fg=yellow>⚠  Some items need attention — review above.</>');
        }

        $this->newLine();

        return self::SUCCESS;
    }

    private function renderDbStatus(string $dbName, ?int $srcRows, ?int $tgtRows): void
    {
        if ($srcRows === null) {
            $this->line("    <fg=gray>·</> DB <fg=cyan>{$dbName}</>: row count unavailable (no mysql client)");

            return;
        }

        $srcFmt = number_format($srcRows);

        if ($tgtRows === null) {
            $this->line("    <fg=yellow>⚠</> DB <fg=cyan>{$dbName}</>: source ~{$srcFmt} rows — target not found");

            return;
        }

        $tgtFmt = number_format($tgtRows);

        if ($tgtRows === 0 && $srcRows === 0) {
            $this->line("    <fg=green>✓</> DB <fg=cyan>{$dbName}</>: empty (as expected)");
        } elseif ($tgtRows === 0 && $srcRows > 0) {
            $this->line("    <fg=red>✗</> DB <fg=cyan>{$dbName}</>: source ~{$srcFmt} rows — target empty <fg=red>(not migrated)</>");
        } elseif ($tgtRows >= $srcRows * 0.95) {
            $this->line("    <fg=green>✓</> DB <fg=cyan>{$dbName}</>: ~{$tgtFmt} / ~{$srcFmt} rows <fg=gray>(≥95% complete)</>");
        } else {
            $pct = $srcRows > 0 ? round($tgtRows / $srcRows * 100) : 0;
            $this->line("    <fg=yellow>⚠</> DB <fg=cyan>{$dbName}</>: ~{$tgtFmt} / ~{$srcFmt} rows ({$pct}%) <fg=yellow>— in progress or incomplete</>");
        }
    }

    private function renderDeployStatus(CloudApiClient $target, string $envId): void
    {
        try {
            $response = $target->get("environments/{$envId}/deployments");
            $deployments = $response['data'] ?? [];

            if (empty($deployments)) {
                $this->line('    <fg=gray>·</> Deploy: never triggered');

                return;
            }

            // The first item is the most recent deployment
            $latest = is_array($deployments[0]) ? $deployments[0] : null;
            $status = $latest['attributes']['status'] ?? 'unknown';

            $color = match ($status) {
                'finished' => 'green',
                'running', 'pending', 'queued' => 'yellow',
                'failed', 'error' => 'red',
                default => 'gray',
            };
            $icon = match ($status) {
                'finished' => '✓',
                'running', 'pending', 'queued' => '⟳',
                'failed', 'error' => '✗',
                default => '·',
            };

            $this->line("    <fg={$color}>{$icon}</> Deploy: <fg={$color}>{$status}</>");
        } catch (RuntimeException) {
            $this->line('    <fg=gray>·</> Deploy: unknown');
        }
    }

    private function buildSourceConnMap(CloudApiClient $source): array
    {
        $map = [];

        try {
            $clusters = $source->getAll('databases/clusters');
        } catch (RuntimeException) {
            return $map;
        }

        foreach ($clusters as $cluster) {
            $conn = $cluster['attributes']['connection'] ?? null;
            if (! $conn) {
                continue;
            }

            try {
                $schemas = $source->getAll("databases/clusters/{$cluster['id']}/databases");
                foreach ($schemas as $schema) {
                    $map[$schema['id']] = [
                        'connection' => $conn,
                        'db_name' => $schema['attributes']['name'],
                    ];
                }
            } catch (RuntimeException) {
            }
        }

        return $map;
    }

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

    private function getRowCount(array $conn, string $dbName): ?int
    {
        $mysql = $this->findBinary('mysql');
        if (! $mysql) {
            return null;
        }

        $sql = 'SELECT COALESCE(SUM(table_rows),0) FROM information_schema.tables WHERE table_schema=\''.addslashes($dbName).'\'';

        $cmd = escapeshellarg($mysql)
            .' --ssl-mode=DISABLED --connect-timeout=5 --batch --skip-column-names'
            .' -h '.escapeshellarg($conn['hostname'])
            .' -P '.(int) $conn['port']
            .' -u '.escapeshellarg($conn['username'])
            .' --password='.escapeshellarg($conn['password'])
            .' -e '.escapeshellarg($sql)
            .' 2>/dev/null';

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || empty($output)) {
            return null;
        }

        return (int) trim($output[0]);
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
