<?php

namespace App\Exceptions;

use RuntimeException;

class IntegrityException extends RuntimeException
{
    public static function sizeMismatch(string $path, int $sourceSize, int $targetSize): self
    {
        return new self("Size parity mismatch for [{$path}]: source has {$sourceSize} bytes, target has {$targetSize} bytes.");
    }

    public static function checksumMismatch(string $path, string $sourceChecksum, string $targetChecksum): self
    {
        return new self("Checksum mismatch for [{$path}]: source is [{$sourceChecksum}], target is [{$targetChecksum}].");
    }
}
