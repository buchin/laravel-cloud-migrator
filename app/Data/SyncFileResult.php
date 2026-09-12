<?php

namespace App\Data;

readonly class SyncFileResult
{
    public function __construct(
        public string $path,
        public string $status,
        public int $size = 0,
        public ?string $checksum = null,
        public int $attempts = 1,
        public ?string $message = null,
        public ?string $error = null,
    ) {}

    public static function transferred(
        string $path,
        int $size,
        ?string $checksum,
        int $attempts = 1,
        ?string $message = null,
    ): self {
        return new self(
            path: $path,
            status: 'transferred',
            size: $size,
            checksum: $checksum,
            attempts: $attempts,
            message: $message ?? 'Stream transferred and verified',
        );
    }

    public static function skipped(
        string $path,
        int $size,
        ?string $checksum,
        string $reason = 'Identical checksum and size',
    ): self {
        return new self(
            path: $path,
            status: 'skipped',
            size: $size,
            checksum: $checksum,
            attempts: 1,
            message: $reason,
        );
    }

    public static function planned(
        string $path,
        int $size,
        ?string $checksum,
        string $action = 'Transfer',
    ): self {
        return new self(
            path: $path,
            status: 'planned',
            size: $size,
            checksum: $checksum,
            attempts: 1,
            message: "Dry-run: {$action}",
        );
    }

    public static function failed(
        string $path,
        int $size,
        string $error,
        int $attempts = 1,
    ): self {
        return new self(
            path: $path,
            status: 'failed',
            size: $size,
            attempts: $attempts,
            error: $error,
        );
    }

    public static function deleted(string $path, int $size = 0, bool $planned = false): self
    {
        return new self(
            path: $path,
            status: $planned ? 'planned_delete' : 'deleted',
            size: $size,
            attempts: 1,
            message: $planned ? 'Dry-run: Delete missing object' : 'Deleted missing object on target',
        );
    }

    public function isSuccess(): bool
    {
        return in_array($this->status, ['transferred', 'skipped', 'planned', 'deleted', 'planned_delete'], true);
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isTransferred(): bool
    {
        return $this->status === 'transferred';
    }

    public function isSkipped(): bool
    {
        return $this->status === 'skipped';
    }

    public function isDeleted(): bool
    {
        return in_array($this->status, ['deleted', 'planned_delete'], true);
    }

    public function isPlanned(): bool
    {
        return in_array($this->status, ['planned', 'planned_delete'], true);
    }
}
