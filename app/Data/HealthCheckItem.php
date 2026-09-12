<?php

namespace App\Data;

readonly class HealthCheckItem
{
    public function __construct(
        public string $appName,
        public string $appSlug,
        public string $envName,
        public string $envSlug,
        public string $url,
        public ?int $statusCode,
        public ?string $finalUrl = null,
        public int $responseTimeMs = 0,
        public string $status = 'healthy', // healthy, redirect, warning, unhealthy, unreachable
        public ?string $message = null,
    ) {}

    public function isHealthy(): bool
    {
        return $this->status === 'healthy';
    }

    public function isWarning(): bool
    {
        return in_array($this->status, ['warning', 'redirect'], true);
    }

    public function isFailed(): bool
    {
        return in_array($this->status, ['unhealthy', 'unreachable'], true);
    }

    public function toArray(): array
    {
        return [
            'app_name' => $this->appName,
            'app_slug' => $this->appSlug,
            'env_name' => $this->envName,
            'env_slug' => $this->envSlug,
            'url' => $this->url,
            'status_code' => $this->statusCode,
            'final_url' => $this->finalUrl,
            'response_time_ms' => $this->responseTimeMs,
            'status' => $this->status,
            'message' => $this->message,
        ];
    }
}
