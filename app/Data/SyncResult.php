<?php

namespace App\Data;

readonly class SyncResult
{
    /**
     * @param  array<SyncFileResult>  $fileResults
     * @param  array<string>  $errors
     */
    public function __construct(
        public string $sourceBucket,
        public string $targetBucket,
        public int $totalFiles = 0,
        public int $transferredFiles = 0,
        public int $skippedFiles = 0,
        public int $failedFiles = 0,
        public int $deletedFiles = 0,
        public int $transferredBytes = 0,
        public int $totalBytes = 0,
        public float $durationSeconds = 0.0,
        public array $fileResults = [],
        public array $errors = [],
        public bool $dryRun = false,
    ) {}

    public function hasFailures(): bool
    {
        return $this->failedFiles > 0 || ! empty($this->errors);
    }

    public function formattedTransferredBytes(): string
    {
        return self::formatBytes($this->transferredBytes);
    }

    public function formattedTotalBytes(): string
    {
        return self::formatBytes($this->totalBytes);
    }

    public function throughput(): string
    {
        if ($this->durationSeconds <= 0 || $this->transferredBytes <= 0) {
            return '0 B/s';
        }

        $bytesPerSec = (int) round($this->transferredBytes / $this->durationSeconds);

        return self::formatBytes($bytesPerSec).'/s';
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / (1024 ** $power), 2).' '.$units[$power];
    }
}
