<?php

namespace App\Commands;

use App\Services\CloudApiClient;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function Laravel\Prompts\error;
use function Laravel\Prompts\password;
use function Laravel\Prompts\spin;

class ListCommand extends Command
{
    protected $signature = 'list-apps
                            {--token= : API token for the organization}';

    protected $description = 'List all applications in a Laravel Cloud organization';

    public function handle(): int
    {
        $this->newLine();

        $token = $this->option('token') ?: password(
            label: 'Organization API token',
            placeholder: 'Paste your token here...',
            hint: 'Get this from cloud.laravel.com → Your Org → Settings → API Tokens',
            required: true,
        );

        $client = new CloudApiClient($token);

        try {
            $applications = spin(fn () => $client->getAll('applications'), 'Fetching applications...');
        } catch (RuntimeException $e) {
            error('Invalid token: '.$e->getMessage());

            return self::FAILURE;
        }

        if (empty($applications)) {
            $this->line('<fg=yellow>No applications found in this organization.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<fg=cyan;options=bold>Applications</>');
        $this->line(str_repeat('─', 60));

        foreach ($applications as $app) {
            $attrs = $app['attributes'];
            $this->line("  <fg=yellow>{$attrs['name']}</> <fg=gray>({$attrs['slug']})</> — {$attrs['region']}");
        }

        $this->newLine();
        $this->line('<fg=gray>'.count($applications).' application(s) total.</>');
        $this->newLine();

        return self::SUCCESS;
    }
}
