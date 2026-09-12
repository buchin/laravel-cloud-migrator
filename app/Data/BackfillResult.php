<?php

namespace App\Data;

class BackfillResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly string $table,
        public readonly string $schema,
        public readonly int $rowsCopied,
        public readonly int $totalRows,
        public readonly int $batchesExecuted,
        public readonly float $durationSeconds,
        public readonly ?int $lastProcessedId = null,
        public readonly ?string $error = null,
        public readonly array $details = [],
    ) {}

    public static function completed(
        string $schema,
        string $table,
        int $rowsCopied,
        int $totalRows,
        int $batchesExecuted,
        float $durationSeconds,
        ?int $lastProcessedId = null,
        array $details = []
    ): self {
        return new self(
            success: true,
            status: 'completed',
            table: $table,
            schema: $schema,
            rowsCopied: $rowsCopied,
            totalRows: $totalRows,
            batchesExecuted: $batchesExecuted,
            durationSeconds: $durationSeconds,
            lastProcessedId: $lastProcessedId,
            error: null,
            details: $details,
        );
    }

    public static function alreadyCompleted(
        string $schema,
        string $table,
        int $processedRows,
        int $totalRows,
        ?int $lastProcessedId = null
    ): self {
        return new self(
            success: true,
            status: 'already_completed',
            table: $table,
            schema: $schema,
            rowsCopied: 0,
            totalRows: $totalRows,
            batchesExecuted: 0,
            durationSeconds: 0.0,
            lastProcessedId: $lastProcessedId,
            error: null,
            details: ['message' => 'Table already completed in previous run'],
        );
    }

    public static function interrupted(
        string $schema,
        string $table,
        int $rowsCopied,
        int $totalRows,
        int $batchesExecuted,
        float $durationSeconds,
        ?int $lastProcessedId = null,
        array $details = []
    ): self {
        return new self(
            success: false,
            status: 'interrupted',
            table: $table,
            schema: $schema,
            rowsCopied: $rowsCopied,
            totalRows: $totalRows,
            batchesExecuted: $batchesExecuted,
            durationSeconds: $durationSeconds,
            lastProcessedId: $lastProcessedId,
            error: 'Interrupted by user or signal',
            details: $details,
        );
    }

    public static function skipped(
        string $schema,
        string $table,
        string $reason
    ): self {
        return new self(
            success: true,
            status: 'skipped',
            table: $table,
            schema: $schema,
            rowsCopied: 0,
            totalRows: 0,
            batchesExecuted: 0,
            durationSeconds: 0.0,
            lastProcessedId: null,
            error: null,
            details: ['reason' => $reason],
        );
    }

    public static function failed(
        string $schema,
        string $table,
        string $error,
        int $rowsCopied = 0,
        int $totalRows = 0,
        int $batchesExecuted = 0,
        float $durationSeconds = 0.0,
        ?int $lastProcessedId = null
    ): self {
        return new self(
            success: false,
            status: 'failed',
            table: $table,
            schema: $schema,
            rowsCopied: $rowsCopied,
            totalRows: $totalRows,
            batchesExecuted: $batchesExecuted,
            durationSeconds: $durationSeconds,
            lastProcessedId: $lastProcessedId,
            error: $error,
            details: [],
        );
    }
}
