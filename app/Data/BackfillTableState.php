<?php

namespace App\Data;

class BackfillTableState
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_INTERRUPTED = 'interrupted';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        public string $table,
        public string $schema,
        public string $status = self::STATUS_PENDING,
        public string $strategy = 'pk_range',
        public ?string $pkColumn = 'id',
        public ?int $minId = null,
        public ?int $maxId = null,
        public ?int $lastProcessedId = null,
        public int $currentOffset = 0,
        public int $processedRows = 0,
        public int $totalRows = 0,
        public int $currentBatchSize = 5000,
        public ?string $startedAt = null,
        public ?string $updatedAt = null,
        public ?string $completedAt = null,
        public ?string $error = null,
        public array $completedParts = [],
        public int $totalParts = 1,
        public int $lastPart = 0,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            table: (string) ($data['table'] ?? ''),
            schema: (string) ($data['schema'] ?? ''),
            status: (string) ($data['status'] ?? self::STATUS_PENDING),
            strategy: (string) ($data['strategy'] ?? 'pk_range'),
            pkColumn: isset($data['pk_column']) ? (string) $data['pk_column'] : null,
            minId: isset($data['min_id']) ? (int) $data['min_id'] : null,
            maxId: isset($data['max_id']) ? (int) $data['max_id'] : null,
            lastProcessedId: isset($data['last_processed_id']) ? (int) $data['last_processed_id'] : null,
            currentOffset: (int) ($data['current_offset'] ?? 0),
            processedRows: (int) ($data['processed_rows'] ?? 0),
            totalRows: (int) ($data['total_rows'] ?? 0),
            currentBatchSize: (int) ($data['current_batch_size'] ?? 5000),
            startedAt: isset($data['started_at']) ? (string) $data['started_at'] : null,
            updatedAt: isset($data['updated_at']) ? (string) $data['updated_at'] : null,
            completedAt: isset($data['completed_at']) ? (string) $data['completed_at'] : null,
            error: isset($data['error']) ? (string) $data['error'] : null,
            completedParts: (array) ($data['completed_parts'] ?? []),
            totalParts: (int) ($data['total_parts'] ?? 1),
            lastPart: (int) ($data['last_part'] ?? 0),
        );
    }

    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'schema' => $this->schema,
            'status' => $this->status,
            'strategy' => $this->strategy,
            'pk_column' => $this->pkColumn,
            'min_id' => $this->minId,
            'max_id' => $this->maxId,
            'last_processed_id' => $this->lastProcessedId,
            'current_offset' => $this->currentOffset,
            'processed_rows' => $this->processedRows,
            'total_rows' => $this->totalRows,
            'current_batch_size' => $this->currentBatchSize,
            'started_at' => $this->startedAt,
            'updated_at' => $this->updatedAt,
            'completed_at' => $this->completedAt,
            'error' => $this->error,
            'completed_parts' => $this->completedParts,
            'total_parts' => $this->totalParts,
            'last_part' => $this->lastPart,
        ];
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isInterrupted(): bool
    {
        return $this->status === self::STATUS_INTERRUPTED;
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
