<?php

namespace App\Data;

readonly class VanityTransferResult
{
    /**
     * @param  array<string>  $logs
     * @param  array<string, mixed>|null  $targetEnvData
     */
    public function __construct(
        public bool $success,
        public string $vanity,
        public string $sourceApp,
        public string $sourceEnv,
        public string $targetApp,
        public string $targetEnv,
        public string $status,
        public string $message,
        public int $attempts = 1,
        public ?string $releasedAs = null,
        public bool $rolledBack = false,
        public float $durationSeconds = 0.0,
        public array $logs = [],
        public ?array $targetEnvData = null,
    ) {}

    public function fullVanityDomain(): string
    {
        return "{$this->vanity}.laravel.cloud";
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function wasRolledBack(): bool
    {
        return $this->rolledBack;
    }
}
