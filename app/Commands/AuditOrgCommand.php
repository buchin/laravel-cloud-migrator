<?php

namespace App\Commands;

use App\Data\OrgAuditResult;
use App\Services\CloudApiClient;
use App\Services\MigrationManifest;
use App\Services\OrgAuditService;
use LaravelZero\Framework\Commands\Command;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\password;
use function Laravel\Prompts\warning;

class AuditOrgCommand extends Command
{
    protected $signature = 'org:audit
                            {--target-token= : API token for the target organization}
                            {--source-token= : API token for the source organization (optional, for DB parity)}
                            {--manifest= : Path to declarative migration manifest (default: migration-plan.json)}
                            {--json= : Export audit report to a JSON file (or audit-report.json)}
                            {--fail-on-warning : Exit with failure (1) if any warnings are detected}
                            {--skip-health : Skip HTTP health checks}
                            {--skip-db : Skip database drift and parity audit}
                            {--skip-config : Skip configuration and cross-app URL audit}
                            {--timeout=10 : HTTP request timeout in seconds}
                            {--yes : Run non-interactively}';

    protected $description = 'Automated aggregate audit & environment drift detection runner';

    public function handle(): int
    {
        $this->newLine();
        $this->line('<fg=cyan;options=bold>╔══════════════════════════════════════════════════════════════════════╗</>');
        $this->line('<fg=cyan;options=bold>║          AUTOMATED AUDIT & ENVIRONMENT DRIFT DETECTION RUNNER        ║</>');
        $this->line('<fg=cyan;options=bold>╚══════════════════════════════════════════════════════════════════════╝</>');
        $this->newLine();

        // 1. Resolve Tokens
        $targetToken = $this->option('target-token')
            ?: (getenv('CLOUD_TARGET_TOKEN') ?: ($_ENV['CLOUD_TARGET_TOKEN'] ?? null));

        if (! $targetToken && ! $this->laravel->bound(OrgAuditService::class) && ! $this->option('yes')) {
            $targetToken = password(
                label: 'Target organization API token',
                placeholder: 'Paste your token here...',
                required: true,
            );
        }

        if (! $targetToken && ! $this->laravel->bound(OrgAuditService::class)) {
            error('Target organization API token is required. Specify via --target-token or CLOUD_TARGET_TOKEN.');

            return self::FAILURE;
        }

        $sourceToken = $this->option('source-token')
            ?: (getenv('CLOUD_SOURCE_TOKEN') ?: ($_ENV['CLOUD_SOURCE_TOKEN'] ?? null));

        // 2. Resolve Manifest
        $manifestPath = $this->option('manifest');
        $manifest = null;
        if ($manifestPath) {
            if (! file_exists($manifestPath)) {
                error("Specified manifest file does not exist: {$manifestPath}");

                return self::FAILURE;
            }
            try {
                $manifest = MigrationManifest::fromFile($manifestPath);
                $this->line("  <fg=gray>Manifest:</> <fg=cyan>{$manifestPath}</>");
            } catch (Throwable $e) {
                error("Failed parsing manifest file: {$e->getMessage()}");

                return self::FAILURE;
            }
        } elseif (file_exists('migration-plan.json')) {
            try {
                $manifest = MigrationManifest::fromFile('migration-plan.json');
                $this->line('  <fg=gray>Manifest:</> <fg=cyan>migration-plan.json</>');
            } catch (Throwable) {
                // Ignore fallback manifest error
            }
        }

        // 3. Resolve Audit Service
        if ($this->laravel->bound(OrgAuditService::class)) {
            $service = $this->laravel->make(OrgAuditService::class);
        } else {
            $targetClient = new CloudApiClient($targetToken);
            $sourceClient = $sourceToken ? new CloudApiClient($sourceToken) : null;
            $service = new OrgAuditService(
                targetClient: $targetClient,
                sourceClient: $sourceClient,
                manifest: $manifest,
            );
        }

        $options = [
            'skip_health' => (bool) $this->option('skip-health'),
            'skip_db' => (bool) $this->option('skip-db'),
            'skip_config' => (bool) $this->option('skip-config'),
            'timeout' => (int) $this->option('timeout'),
        ];

        // 4. Run Aggregate Audit
        try {
            $result = $service->runAudit($options);
        } catch (Throwable $e) {
            error("Audit execution encountered an unexpected error: {$e->getMessage()}");

            return self::FAILURE;
        }

        // 5. Render Visual CLI Output
        $this->renderVisualOutput($result, $options);

        // 6. JSON Export
        $hasJsonFlag = $this->input->hasParameterOption('--json');
        $jsonPathOption = $this->option('json');
        if ($hasJsonFlag || ($jsonPathOption !== null && $jsonPathOption !== '')) {
            $exportFile = ($jsonPathOption !== null && $jsonPathOption !== '') ? $jsonPathOption : 'audit-report.json';
            try {
                file_put_contents($exportFile, $result->toJson());
                $this->newLine();
                info("Exported audit report to {$exportFile}");
            } catch (Throwable $e) {
                error("Failed writing JSON report to {$exportFile}: {$e->getMessage()}");
            }
        }

        // 7. Determine Exit Code
        $this->newLine();
        $failOnWarning = (bool) $this->option('fail-on-warning');
        $isClean = $result->isClean();
        $hasWarnings = $result->hasWarnings();

        if (! $isClean) {
            error(sprintf('Audit failed: %d drift/unhealthy check(s) detected.', $result->totalDrifts()));

            return self::FAILURE;
        }

        if ($failOnWarning && $hasWarnings) {
            warning(sprintf('Audit completed with %d warning(s) and --fail-on-warning is enabled.', $result->totalWarnings()));

            return self::FAILURE;
        }

        info(sprintf('Audit passed: %d/%d check(s) clean and healthy with 0 drift detected.', $result->totalPassed(), $result->totalChecks()));

        return self::SUCCESS;
    }

    private function renderVisualOutput(OrgAuditResult $result, array $options): void
    {
        // Section 1: Health Checks
        if (empty($options['skip_health'])) {
            $this->newLine();
            $this->line('<fg=yellow;options=bold>1. HTTP Health Checks (Target Environments)</>');
            $this->line(str_repeat('─', 72));

            if (empty($result->healthItems)) {
                $this->line('  <fg=gray>No environment health checks performed.</>');
            } else {
                $rows = [];
                foreach ($result->healthItems as $item) {
                    $statusTag = match ($item->status) {
                        'healthy' => '<fg=green>✓ Healthy</>',
                        'redirect' => '<fg=cyan>↪ Redirect</>',
                        'warning' => '<fg=yellow>⚠ Warning</>',
                        default => '<fg=red>✗ Unhealthy</>',
                    };
                    $codeStr = $item->statusCode ? (string) $item->statusCode : 'N/A';
                    $latencyStr = $item->responseTimeMs > 0 ? "{$item->responseTimeMs}ms" : '—';
                    $urlDisplay = $item->url !== '' ? (strlen($item->url) > 36 ? substr($item->url, 0, 33).'...' : $item->url) : '—';

                    $rows[] = [
                        $item->appName,
                        $item->envName,
                        $urlDisplay,
                        $codeStr,
                        $latencyStr,
                        $statusTag,
                    ];
                }

                $this->table(
                    ['App', 'Environment', 'URL', 'HTTP', 'Latency', 'Status'],
                    $rows,
                );
            }
        }

        // Section 2: Database Drift & Parity
        if (empty($options['skip_db'])) {
            $this->newLine();
            $this->line('<fg=yellow;options=bold>2. Database Schema & Data Parity</>');
            $this->line(str_repeat('─', 72));

            if (empty($result->databaseItems)) {
                $this->line('  <fg=gray>No database checks performed.</>');
            } else {
                $rows = [];
                foreach ($result->databaseItems as $item) {
                    $statusTag = match ($item->status) {
                        'matched', 'exact' => '<fg=green>✓ Matched</>',
                        'transient' => '<fg=gray>· Transient</>',
                        'schema_only' => '<fg=gray>· Schema Only</>',
                        'ignored' => '<fg=gray>· Ignored</>',
                        'warning' => '<fg=yellow>⚠ Warning</>',
                        default => '<fg=red>✗ Drift</>',
                    };

                    $srcStr = $item->sourceCount !== null ? number_format($item->sourceCount) : '—';
                    $tgtStr = $item->targetCount !== null ? number_format($item->targetCount) : '—';
                    $schemaDisplay = "{$item->cluster}.{$item->schema}";

                    $rows[] = [
                        $schemaDisplay,
                        $item->table,
                        $item->policy,
                        $srcStr,
                        $tgtStr,
                        $statusTag,
                    ];
                }

                $this->table(
                    ['Cluster.Schema', 'Table', 'Policy', 'Source', 'Target', 'Status'],
                    $rows,
                );
            }
        }

        // Section 3: Configuration & Cross-App URLs
        if (empty($options['skip_config'])) {
            $this->newLine();
            $this->line('<fg=yellow;options=bold>3. Environment Configuration & Cross-App URLs</>');
            $this->line(str_repeat('─', 72));

            if (empty($result->configItems)) {
                $this->line('  <fg=gray>No configuration drift checks performed.</>');
            } else {
                $rows = [];
                foreach ($result->configItems as $item) {
                    $statusTag = match ($item->status) {
                        'matched' => '<fg=green>✓ Matched</>',
                        'warning' => '<fg=yellow>⚠ Warning</>',
                        'missing' => '<fg=red>✗ Missing</>',
                        default => '<fg=red>✗ Drift</>',
                    };

                    $expectedDisplay = $item->expectedValue !== null
                        ? (strlen($item->expectedValue) > 30 ? substr($item->expectedValue, 0, 27).'...' : $item->expectedValue)
                        : '—';
                    $actualDisplay = $item->actualValue !== null
                        ? (strlen($item->actualValue) > 30 ? substr($item->actualValue, 0, 27).'...' : $item->actualValue)
                        : '—';

                    $rows[] = [
                        $item->appName,
                        $item->envName,
                        $item->variableKey,
                        $expectedDisplay,
                        $actualDisplay,
                        $statusTag,
                    ];
                }

                $this->table(
                    ['App', 'Env', 'Variable', 'Expected', 'Actual', 'Status'],
                    $rows,
                );
            }
        }

        // Section 4: Aggregate Summary
        $this->newLine();
        $this->line('<fg=cyan;options=bold>══════════════════════════════════════════════════════════════════════</>');
        $this->line('<fg=cyan;options=bold>                           AUDIT SUMMARY                              </>');
        $this->line('<fg=cyan;options=bold>══════════════════════════════════════════════════════════════════════</>');

        $statusBanner = $result->isClean()
            ? ($result->hasWarnings() ? '<fg=yellow;options=bold>PASS WITH WARNINGS</>' : '<fg=green;options=bold>100% HEALTHY / 0 DRIFT</>')
            : '<fg=red;options=bold>DRIFT DETECTED</>';

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Checks', (string) $result->totalChecks()],
                ['Passed Checks', '<fg=green>'.(string) $result->totalPassed().'</>'],
                ['Warnings', ($result->totalWarnings() > 0 ? '<fg=yellow>' : '<fg=gray>').(string) $result->totalWarnings().'</>'],
                ['Drift / Failures', ($result->totalDrifts() > 0 ? '<fg=red>' : '<fg=green>').(string) $result->totalDrifts().'</>'],
                ['Execution Duration', sprintf('%.2f seconds', $result->durationSeconds)],
                ['Overall Status', $statusBanner],
            ]
        );
    }
}
