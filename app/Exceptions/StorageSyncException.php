<?php

namespace App\Exceptions;

use RuntimeException;

class StorageSyncException extends RuntimeException
{
    public static function missingCredentials(string $side): self
    {
        return new self("Missing credentials for {$side} storage. Please specify via options or environment variables.");
    }

    public static function bucketNotFound(string $bucket, string $side): self
    {
        return new self("Bucket [{$bucket}] for {$side} storage was not found or is inaccessible.");
    }
}
