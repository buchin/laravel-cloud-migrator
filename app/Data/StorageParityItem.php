<?php

namespace App\Data;

readonly class StorageParityItem
{
    public const STATUS_MATCHED = 'matched';

    public const STATUS_MISSING_IN_TARGET = 'missing_in_target';

    public const STATUS_SIZE_MISMATCH = 'size_mismatch';

    public const STATUS_CHECKSUM_MISMATCH = 'checksum_mismatch';

    public const STATUS_ERROR = 'error';

    public function __construct(
        public string $path,
        public string $status,
        public int $sourceSize = 0,
        public ?int $targetSize = null,
        public ?string $sourceChecksum = null,
        public ?string $targetChecksum = null,
        public ?string $message = null,
        public ?string $error = null,
    ) {}

    public static function matched(
        string $path,
        int $sourceSize,
        ?int $targetSize = null,
        ?string $sourceChecksum = null,
        ?string $targetChecksum = null,
        ?string $message = null,
    ): self {
        return new self(
            path: $path,
            status: self::STATUS_MATCHED,
            sourceSize: $sourceSize,
            targetSize: $targetSize ?? $sourceSize,
            sourceChecksum: $sourceChecksum,
            targetChecksum: $targetChecksum,
            message: $message ?? 'Metadata and checksum matched',
        );
    }

    public static function missingInTarget(
        string $path,
        int $sourceSize,
        ?string $sourceChecksum = null,
        ?string $message = null,
    ): self {
        return new self(
            path: $path,
            status: self::STATUS_MISSING_IN_TARGET,
            sourceSize: $sourceSize,
            targetSize: null,
            sourceChecksum: $sourceChecksum,
            targetChecksum: null,
            message: $message ?? 'Object does not exist in target bucket',
        );
    }

    public static function sizeMismatch(
        string $path,
        int $sourceSize,
        int $targetSize,
        ?string $sourceChecksum = null,
        ?string $targetChecksum = null,
        ?string $message = null,
    ): self {
        return new self(
            path: $path,
            status: self::STATUS_SIZE_MISMATCH,
            sourceSize: $sourceSize,
            targetSize: $targetSize,
            sourceChecksum: $sourceChecksum,
            targetChecksum: $targetChecksum,
            message: $message ?? sprintf('Size mismatch: source %d bytes vs target %d bytes', $sourceSize, $targetSize),
        );
    }

    public static function checksumMismatch(
        string $path,
        int $sourceSize,
        ?int $targetSize,
        ?string $sourceChecksum,
        ?string $targetChecksum,
        ?string $message = null,
    ): self {
        return new self(
            path: $path,
            status: self::STATUS_CHECKSUM_MISMATCH,
            sourceSize: $sourceSize,
            targetSize: $targetSize ?? $sourceSize,
            sourceChecksum: $sourceChecksum,
            targetChecksum: $targetChecksum,
            message: $message ?? sprintf('Checksum mismatch: source %s vs target %s', $sourceChecksum ?? 'null', $targetChecksum ?? 'null'),
        );
    }

    public static function error(
        string $path,
        string $error,
        int $sourceSize = 0,
        ?int $targetSize = null,
    ): self {
        return new self(
            path: $path,
            status: self::STATUS_ERROR,
            sourceSize: $sourceSize,
            targetSize: $targetSize,
            error: $error,
            message: "Error verifying object: {$error}",
        );
    }

    public function isMatched(): bool
    {
        return $this->status === self::STATUS_MATCHED;
    }

    public function isMissingInTarget(): bool
    {
        return $this->status === self::STATUS_MISSING_IN_TARGET;
    }

    public function isSizeMismatch(): bool
    {
        return $this->status === self::STATUS_SIZE_MISMATCH;
    }

    public function isChecksumMismatch(): bool
    {
        return $this->status === self::STATUS_CHECKSUM_MISMATCH;
    }

    public function isError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    public function isDiscrepancy(): bool
    {
        return ! $this->isMatched();
    }

    public function isSourceMultipart(): bool
    {
        return self::detectMultipart($this->sourceChecksum);
    }

    public function isTargetMultipart(): bool
    {
        return self::detectMultipart($this->targetChecksum);
    }

    public static function detectMultipart(?string $checksum): bool
    {
        if ($checksum === null) {
            return false;
        }

        $clean = trim($checksum, " \t\n\r\0\x0B\"'");

        return (bool) preg_match('/^[a-f0-9]+-\d+$/i', $clean);
    }

    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'status' => $this->status,
            'source_size' => $this->sourceSize,
            'target_size' => $this->targetSize,
            'source_checksum' => $this->sourceChecksum,
            'target_checksum' => $this->targetChecksum,
            'message' => $this->message,
            'error' => $this->error,
            'is_multipart' => $this->isSourceMultipart() || $this->isTargetMultipart(),
        ];
    }
}
