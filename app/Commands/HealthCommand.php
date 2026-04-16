<?php

namespace App\Commands;

use App\Services\CloudApiClient;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function Laravel\Prompts\error;
use function Laravel\Prompts\password;
use function Laravel\Prompts\spin;

class HealthCommand extends Command
{
    protected $signature = 'health
                            {--target-token= : API token for the target organization}
                            {--timeout=10 : HTTP request timeout in seconds}
                            {--expect-2xx : Exit with failure if any environment returns a non-2xx response}';

    protected $description = 'Check HTTP health of all environments in the target organization';

    public function handle(): int
    {
        $this->newLine();

        $targetToken = $this->option('target-token') ?: password(
            label: 'Target organization API token',
            placeholder: 'Paste your token here...',
            required: true,
        );

        $target = new CloudApiClient($targetToken);

        try {
            $apps = spin(fn () => $target->getAll('applications'), 'Fetching applications...');
        } catch (RuntimeException $e) {
            error('Token invalid: '.$e->getMessage());

            return self::FAILURE;
        }

        if (empty($apps)) {
            $this->newLine();
            $this->line('<fg=yellow>No applications found in organization.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        $timeout = max(1, (int) $this->option('timeout'));
        $allHealthy = true;

        $this->newLine();
        $this->line('<fg=cyan;options=bold>HTTP Health Check</>');
        $this->line(str_repeat('─', 70));

        foreach ($apps as $app) {
            $appName = $app['attributes']['name'];
            $appSlug = $app['attributes']['slug'];
            $this->newLine();
            $this->line("<fg=yellow;options=bold>{$appName}</> <fg=gray>({$appSlug})</>");

            try {
                $envs = $target->getAll("applications/{$app['id']}/environments");
            } catch (RuntimeException) {
                $this->line('  <fg=gray>·</> Could not fetch environments');

                continue;
            }

            foreach ($envs as $env) {
                $envName = $env['attributes']['name'];
                $vanity = $env['attributes']['vanity_domain'] ?? null;

                if (! $vanity) {
                    $this->line("  <fg=gray>·</> <fg=cyan>{$envName}</>: no URL available");

                    continue;
                }

                $url = 'https://'.$vanity;
                [$code, $finalUrl, $ms] = $this->probe($url, $timeout);

                $this->renderResult($envName, $url, $code, $finalUrl, $ms);

                if ($code === null || $code < 200 || $code >= 500) {
                    $allHealthy = false;
                }
            }
        }

        $this->newLine();
        $this->line(str_repeat('─', 70));
        $this->newLine();

        if ($allHealthy) {
            $this->line('<fg=green;options=bold>✓ All environments healthy.</>');
        } else {
            $this->line('<fg=yellow>⚠  Some environments need attention — review above.</>');
        }

        $this->newLine();

        if ($this->option('expect-2xx') && ! $allHealthy) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** Probe a URL, following redirects. Returns [status_code|null, final_url, ms]. */
    private function probe(string $url, int $timeout): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'laravel-cloud-migrator/health',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADER => false,
            CURLOPT_NOBODY => false,
        ]);

        $start = microtime(true);
        curl_exec($ch);
        $ms = (int) round((microtime(true) - $start) * 1000);

        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: null;
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0) {
            return [null, $url, $ms];
        }

        return [$code, $finalUrl, $ms];
    }

    private function renderResult(string $envName, string $url, ?int $code, string $finalUrl, int $ms): void
    {
        $redirected = rtrim($finalUrl, '/') !== rtrim($url, '/');
        $redirectNote = $redirected ? " <fg=gray>→ {$finalUrl}</>" : '';

        if ($code === null) {
            $this->line("  <fg=red>✗</> <fg=cyan>{$envName}</>: <fg=red>unreachable</> <fg=gray>({$ms}ms)</>{$redirectNote}");

            return;
        }

        [$icon, $color] = $this->classify($code);
        $this->line("  <fg={$color}>{$icon}</> <fg=cyan>{$envName}</>: <fg={$color}>{$code}</> <fg=gray>({$ms}ms)</>{$redirectNote}");
    }

    /** @return array{string, string} [icon, color] */
    private function classify(int $code): array
    {
        return match (true) {
            $code >= 200 && $code < 300 => ['✓', 'green'],
            $code >= 300 && $code < 400 => ['↪', 'cyan'],
            $code === 401, $code === 403 => ['🔒', 'yellow'],
            $code >= 400 && $code < 500 => ['⚠', 'yellow'],
            default => ['✗', 'red'],
        };
    }
}
