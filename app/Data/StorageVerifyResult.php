<?php

namespace App\Data;

readonly class StorageVerifyResult
{
    /**
     * @param  array<StorageParityItem>  $items
     * @param  array<string>  $errors
     */
    public function __construct(
        public string $sourceBucket,
        public string $targetBucket,
        public int $totalObjects = 0,
        public int $matchedCount = 0,
        public int $missingCount = 0,
        public int $sizeMismatchCount = 0,
        public int $checksumMismatchCount = 0,
        public int $errorCount = 0,
        public int $totalSourceBytes = 0,
        public int $totalTargetBytes = 0,
        public float $durationSeconds = 0.0,
        public array $items = [],
        public array $errors = [],
        public string $prefix = '',
    ) {}

    public function isParity100(): bool
    {
        return $this->missingCount === 0
            && $this->sizeMismatchCount === 0
            && $this->checksumMismatchCount === 0
            && $this->errorCount === 0
            && empty($this->errors);
    }

    public function hasDiscrepancies(): bool
    {
        return ! $this->isParity100();
    }

    public function totalDiscrepancies(): int
    {
        return $this->missingCount + $this->sizeMismatchCount + $this->checksumMismatchCount + $this->errorCount;
    }

    public function parityPercentage(): float
    {
        if ($this->totalObjects <= 0) {
            return 100.0;
        }

        return round(($this->matchedCount / $this->totalObjects) * 100, 2);
    }

    /**
     * @return array<StorageParityItem>
     */
    public function getDiscrepancies(): array
    {
        return array_values(array_filter($this->items, fn (StorageParityItem $item) => $item->isDiscrepancy()));
    }

    /**
     * @return array<StorageParityItem>
     */
    public function getMatched(): array
    {
        return array_values(array_filter($this->items, fn (StorageParityItem $item) => $item->isMatched()));
    }

    public function formattedSourceBytes(): string
    {
        return self::formatBytes($this->totalSourceBytes);
    }

    public function formattedTargetBytes(): string
    {
        return self::formatBytes($this->totalTargetBytes);
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

    public function toArray(): array
    {
        return [
            'source_bucket' => $this->sourceBucket,
            'target_bucket' => $this->targetBucket,
            'prefix' => $this->prefix,
            'total_objects' => $this->totalObjects,
            'matched_count' => $this->matchedCount,
            'missing_count' => $this->missingCount,
            'size_mismatch_count' => $this->sizeMismatchCount,
            'checksum_mismatch_count' => $this->checksumMismatchCount,
            'error_count' => $this->errorCount,
            'total_discrepancies' => $this->totalDiscrepancies(),
            'parity_percentage' => $this->parityPercentage(),
            'is_parity_100' => $this->isParity100(),
            'total_source_bytes' => $this->totalSourceBytes,
            'total_target_bytes' => $this->totalTargetBytes,
            'duration_seconds' => round($this->durationSeconds, 4),
            'errors' => $this->errors,
            'items' => array_map(fn (StorageParityItem $item) => $item->toArray(), $this->items),
        ];
    }
}
