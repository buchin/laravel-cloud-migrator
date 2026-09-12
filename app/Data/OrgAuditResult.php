<?php

namespace App\Data;

readonly class OrgAuditResult
{
    /**
     * @param  array<int, HealthCheckItem>  $healthItems
     * @param  array<int, DatabaseAuditItem>  $databaseItems
     * @param  array<int, ConfigAuditItem>  $configItems
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public array $healthItems = [],
        public array $databaseItems = [],
        public array $configItems = [],
        public float $durationSeconds = 0.0,
        public array $metadata = [],
    ) {}

    public function totalHealthChecks(): int
    {
        return count($this->healthItems);
    }

    public function healthyHealthChecks(): int
    {
        return count(array_filter($this->healthItems, fn (HealthCheckItem $item) => $item->isHealthy()));
    }

    public function failedHealthChecks(): int
    {
        return count(array_filter($this->healthItems, fn (HealthCheckItem $item) => $item->isFailed()));
    }

    public function warningHealthChecks(): int
    {
        return count(array_filter($this->healthItems, fn (HealthCheckItem $item) => $item->isWarning()));
    }

    public function totalDatabaseChecks(): int
    {
        return count($this->databaseItems);
    }

    public function matchedDatabaseChecks(): int
    {
        return count(array_filter($this->databaseItems, fn (DatabaseAuditItem $item) => $item->isMatched()));
    }

    public function driftDatabaseChecks(): int
    {
        return count(array_filter($this->databaseItems, fn (DatabaseAuditItem $item) => $item->isDrift()));
    }

    public function warningDatabaseChecks(): int
    {
        return count(array_filter($this->databaseItems, fn (DatabaseAuditItem $item) => $item->isWarning()));
    }

    public function totalConfigChecks(): int
    {
        return count($this->configItems);
    }

    public function matchedConfigChecks(): int
    {
        return count(array_filter($this->configItems, fn (ConfigAuditItem $item) => $item->isMatched()));
    }

    public function driftConfigChecks(): int
    {
        return count(array_filter($this->configItems, fn (ConfigAuditItem $item) => $item->isDrift()));
    }

    public function warningConfigChecks(): int
    {
        return count(array_filter($this->configItems, fn (ConfigAuditItem $item) => $item->isWarning()));
    }

    public function totalChecks(): int
    {
        return $this->totalHealthChecks() + $this->totalDatabaseChecks() + $this->totalConfigChecks();
    }

    public function totalPassed(): int
    {
        return $this->healthyHealthChecks() + $this->matchedDatabaseChecks() + $this->matchedConfigChecks();
    }

    public function totalDrifts(): int
    {
        return $this->failedHealthChecks() + $this->driftDatabaseChecks() + $this->driftConfigChecks();
    }

    public function totalWarnings(): int
    {
        return $this->warningHealthChecks() + $this->warningDatabaseChecks() + $this->warningConfigChecks();
    }

    public function isClean(): bool
    {
        return $this->totalDrifts() === 0;
    }

    public function hasWarnings(): bool
    {
        return $this->totalWarnings() > 0;
    }

    public function overallStatus(): string
    {
        if ($this->totalDrifts() > 0) {
            return 'drift_detected';
        }
        if ($this->hasWarnings()) {
            return 'warning';
        }

        return 'healthy';
    }

    public function toArray(): array
    {
        return [
            'timestamp' => date('c'),
            'duration_seconds' => round($this->durationSeconds, 2),
            'status' => $this->overallStatus(),
            'is_clean' => $this->isClean(),
            'summary' => [
                'total_checks' => $this->totalChecks(),
                'passed' => $this->totalPassed(),
                'drifts' => $this->totalDrifts(),
                'warnings' => $this->totalWarnings(),
            ],
            'health_check' => [
                'total_environments' => $this->totalHealthChecks(),
                'healthy' => $this->healthyHealthChecks(),
                'failed' => $this->failedHealthChecks(),
                'warnings' => $this->warningHealthChecks(),
                'items' => array_map(fn (HealthCheckItem $i) => $i->toArray(), $this->healthItems),
            ],
            'database_audit' => [
                'total_tables' => $this->totalDatabaseChecks(),
                'matched' => $this->matchedDatabaseChecks(),
                'drifts' => $this->driftDatabaseChecks(),
                'warnings' => $this->warningDatabaseChecks(),
                'items' => array_map(fn (DatabaseAuditItem $i) => $i->toArray(), $this->databaseItems),
            ],
            'config_audit' => [
                'total_checks' => $this->totalConfigChecks(),
                'matched' => $this->matchedConfigChecks(),
                'drifts' => $this->driftConfigChecks(),
                'warnings' => $this->warningConfigChecks(),
                'items' => array_map(fn (ConfigAuditItem $i) => $i->toArray(), $this->configItems),
            ],
            'metadata' => $this->metadata,
        ];
    }

    public function toJson(int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES): string
    {
        return json_encode($this->toArray(), $flags);
    }
}
