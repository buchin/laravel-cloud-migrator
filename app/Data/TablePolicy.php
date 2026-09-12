<?php

namespace App\Data;

use InvalidArgumentException;

class TablePolicy
{
    public const FULL = 'full';

    public const SCHEMA_ONLY = 'schema_only';

    public const CHUNKED = 'chunked';

    public const IGNORE = 'ignore';

    public const TRANSIENT = 'transient';

    public const VALID_POLICIES = [
        self::FULL,
        self::SCHEMA_ONLY,
        self::CHUNKED,
        self::IGNORE,
        self::TRANSIENT,
    ];

    public function __construct(
        public readonly string $policy = self::FULL,
        public readonly ?int $chunkSize = null,
        public readonly ?int $thresholdRows = null,
        public readonly ?int $thresholdBytes = null,
        public readonly bool $migrateData = false,
        public readonly ?string $description = null,
    ) {
        if (! in_array($this->policy, self::VALID_POLICIES, true)) {
            throw new InvalidArgumentException(
                "Invalid table policy '{$this->policy}'. Valid policies: ".implode(', ', self::VALID_POLICIES)
            );
        }
    }

    public static function fromConfig(string|array $config): self
    {
        if (is_string($config)) {
            return new self(policy: strtolower(trim($config)));
        }

        $policy = strtolower(trim($config['policy'] ?? self::FULL));
        $chunkSize = isset($config['chunk_size']) ? (int) $config['chunk_size'] : null;
        $thresholdRows = isset($config['threshold_rows']) ? (int) $config['threshold_rows'] : null;
        $thresholdBytes = isset($config['threshold_bytes']) ? (int) $config['threshold_bytes'] : null;
        $migrateData = isset($config['migrate_data'])
            ? (bool) $config['migrate_data']
            : ($policy === self::FULL || $policy === self::CHUNKED);
        $description = $config['description'] ?? null;

        return new self(
            policy: $policy,
            chunkSize: $chunkSize,
            thresholdRows: $thresholdRows,
            thresholdBytes: $thresholdBytes,
            migrateData: $migrateData,
            description: $description,
        );
    }

    public function isFull(): bool
    {
        return $this->policy === self::FULL;
    }

    public function isSchemaOnly(): bool
    {
        return $this->policy === self::SCHEMA_ONLY;
    }

    public function isChunked(): bool
    {
        return $this->policy === self::CHUNKED;
    }

    public function isIgnore(): bool
    {
        return $this->policy === self::IGNORE;
    }

    public function isTransient(): bool
    {
        return $this->policy === self::TRANSIENT;
    }

    public function shouldMigrateData(): bool
    {
        if ($this->isIgnore() || $this->isSchemaOnly()) {
            return false;
        }

        if ($this->isTransient()) {
            return $this->migrateData;
        }

        return true;
    }
}
