<?php

namespace App\Data;

readonly class DatabaseAuditItem
{
    public function __construct(
        public string $cluster,
        public string $schema,
        public string $table,
        public ?int $sourceCount = null,
        public ?int $targetCount = null,
        public ?string $policy = 'full', // full, transient, schema_only, ignore, chunked
        public string $status = 'matched', // matched, exact, drift, warning, error, transient, schema_only, ignored, missing_in_target
        public ?string $message = null,
    ) {}

    public function isMatched(): bool
    {
        return in_array($this->status, ['matched', 'exact', 'transient', 'schema_only', 'ignored'], true);
    }

    public function isWarning(): bool
    {
        return $this->status === 'warning';
    }

    public function isDrift(): bool
    {
        return in_array($this->status, ['drift', 'error', 'missing_in_target', 'target_empty'], true);
    }

    public function toArray(): array
    {
        return [
            'cluster' => $this->cluster,
            'schema' => $this->schema,
            'table' => $this->table,
            'source_count' => $this->sourceCount,
            'target_count' => $this->targetCount,
            'policy' => $this->policy,
            'status' => $this->status,
            'message' => $this->message,
        ];
    }
}
