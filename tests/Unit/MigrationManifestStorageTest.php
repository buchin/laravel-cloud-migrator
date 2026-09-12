<?php

use App\Services\MigrationManifest;

describe('MigrationManifestStorage', function () {
    test('parses storage section with indexed list of buckets', function () {
        $data = [
            'version' => '1.0',
            'storage' => [
                [
                    'source_bucket' => 'media-prod',
                    'target_bucket' => 'media-target',
                    'prefix' => 'uploads/',
                    'source_region' => 'ap-southeast-1',
                    'target_region' => 'us-east-1',
                ],
                [
                    'source_bucket' => 'invoices',
                    'target_bucket' => 'invoices-target',
                ],
            ],
        ];

        $manifest = MigrationManifest::fromArray($data);
        $buckets = $manifest->getStorageBuckets();

        expect($buckets)->toHaveCount(2)
            ->and($buckets[0]['source_bucket'])->toBe('media-prod')
            ->and($buckets[0]['target_bucket'])->toBe('media-target')
            ->and($buckets[0]['prefix'])->toBe('uploads/')
            ->and($buckets[0]['source_region'])->toBe('ap-southeast-1')
            ->and($buckets[1]['source_bucket'])->toBe('invoices')
            ->and($buckets[1]['target_bucket'])->toBe('invoices-target');
    });

    test('parses storage section with associative map format', function () {
        $data = [
            'version' => '1.0',
            'storage' => [
                'avatars-prod' => 'avatars-target',
                'backups-prod' => [
                    'target_bucket' => 'backups-target',
                    'prefix' => 'daily/',
                ],
            ],
        ];

        $manifest = MigrationManifest::fromArray($data);
        $buckets = $manifest->getStorageBuckets();

        expect($buckets)->toHaveCount(2)
            ->and($buckets[0]['source_bucket'])->toBe('avatars-prod')
            ->and($buckets[0]['target_bucket'])->toBe('avatars-target')
            ->and($buckets[1]['source_bucket'])->toBe('backups-prod')
            ->and($buckets[1]['target_bucket'])->toBe('backups-target')
            ->and($buckets[1]['prefix'])->toBe('daily/');
    });

    test('returns empty array when storage section is omitted', function () {
        $manifest = MigrationManifest::fromArray([
            'version' => '1.0',
            'databases' => [],
        ]);

        expect($manifest->getStorageBuckets())->toBe([]);
    });
});
