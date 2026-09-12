<?php

use App\Commands\VerifyStorageCommand;
use App\Data\StorageParityItem;
use App\Data\StorageVerifyResult;
use App\Services\StorageVerifyService;

describe('VerifyStorageCommand Feature', function () {
    test('fails with clear error when manifest file does not exist', function () {
        $this->artisan('storage:verify', [
            '--manifest' => '/nonexistent/path/to/manifest.json',
            '--yes' => true,
        ])
            ->expectsOutputToContain('Specified manifest file does not exist')
            ->assertExitCode(VerifyStorageCommand::FAILURE);
    });

    test('fails when no buckets are specified in non-interactive mode', function () {
        $this->artisan('storage:verify', [
            '--source-key' => 'test-src-key',
            '--source-secret' => 'test-src-secret',
            '--target-key' => 'test-tgt-key',
            '--target-secret' => 'test-tgt-secret',
            '--yes' => true,
        ])
            ->expectsOutputToContain('No source and target buckets specified')
            ->assertExitCode(VerifyStorageCommand::FAILURE);
    });

    test('fails when credentials are missing in non-interactive mode', function () {
        // Clear AWS env variables during test
        putenv('SOURCE_AWS_ACCESS_KEY_ID=');
        putenv('SOURCE_AWS_SECRET_ACCESS_KEY=');
        putenv('TARGET_AWS_ACCESS_KEY_ID=');
        putenv('TARGET_AWS_SECRET_ACCESS_KEY=');
        putenv('AWS_ACCESS_KEY_ID=');
        putenv('AWS_SECRET_ACCESS_KEY=');

        $this->artisan('storage:verify', [
            '--source-bucket' => 'src-bucket',
            '--target-bucket' => 'tgt-bucket',
            '--yes' => true,
        ])
            ->expectsOutputToContain('Missing S3 credentials')
            ->assertExitCode(VerifyStorageCommand::FAILURE);
    });

    test('executes 100% parity verification and returns exit code 0', function () {
        $mockService = Mockery::mock(StorageVerifyService::class);
        $mockResult = new StorageVerifyResult(
            sourceBucket: 'assets-src',
            targetBucket: 'assets-tgt',
            totalObjects: 2,
            matchedCount: 2,
            missingCount: 0,
            sizeMismatchCount: 0,
            checksumMismatchCount: 0,
            errorCount: 0,
            totalSourceBytes: 4096,
            totalTargetBytes: 4096,
            durationSeconds: 0.25,
            items: [
                StorageParityItem::matched('css/app.css', 2048, 2048, 'hash-css-123', 'hash-css-123'),
                StorageParityItem::matched('js/app.js', 2048, 2048, 'hash-js-456', 'hash-js-456'),
            ],
            errors: [],
        );

        $mockService->shouldReceive('verify')
            ->once()
            ->andReturnUsing(function ($options) use ($mockResult) {
                if (isset($options['progress']) && is_callable($options['progress'])) {
                    $options['progress']($mockResult->items[0], 1, 2);
                    $options['progress']($mockResult->items[1], 2, 2);
                }

                return $mockResult;
            });

        $this->app->instance(StorageVerifyService::class, $mockService);

        $this->artisan('storage:verify', [
            '--source-bucket' => 'assets-src',
            '--target-bucket' => 'assets-tgt',
            '--source-key' => 'AKIA-SRC',
            '--source-secret' => 'SECRET-SRC',
            '--target-key' => 'AKIA-TGT',
            '--target-secret' => 'SECRET-TGT',
            '--yes' => true,
        ])
            ->expectsOutputToContain('Storage Parity Verification Plan')
            ->expectsOutputToContain('assets-src')
            ->expectsOutputToContain('assets-tgt')
            ->expectsOutputToContain('ZERO-DOWNLOAD AUDIT')
            ->expectsOutputToContain('css/app.css')
            ->expectsOutputToContain('js/app.js')
            ->expectsOutputToContain('100% PARITY (MATCHED)')
            ->expectsOutputToContain('100% parity verified across all buckets')
            ->assertExitCode(VerifyStorageCommand::SUCCESS);
    });

    test('detects discrepancies, displays detail table, and returns exit code 1', function () {
        $mockService = Mockery::mock(StorageVerifyService::class);
        $mockResult = new StorageVerifyResult(
            sourceBucket: 'media-src',
            targetBucket: 'media-tgt',
            totalObjects: 3,
            matchedCount: 0,
            missingCount: 1,
            sizeMismatchCount: 1,
            checksumMismatchCount: 1,
            errorCount: 0,
            totalSourceBytes: 1500,
            totalTargetBytes: 1000,
            durationSeconds: 0.15,
            items: [
                StorageParityItem::missingInTarget('deleted.jpg', 500, 'h1'),
                StorageParityItem::sizeMismatch('resized.png', 500, 1000, 'h2', 'h2-bad'),
                StorageParityItem::checksumMismatch('corrupted.pdf', 500, 500, 'h3-src', 'h3-tgt'),
            ],
            errors: [],
        );

        $mockService->shouldReceive('verify')
            ->once()
            ->andReturnUsing(function ($options) use ($mockResult) {
                if (isset($options['progress']) && is_callable($options['progress'])) {
                    $options['progress']($mockResult->items[0], 1, 3);
                    $options['progress']($mockResult->items[1], 2, 3);
                    $options['progress']($mockResult->items[2], 3, 3);
                }

                return $mockResult;
            });

        $this->app->instance(StorageVerifyService::class, $mockService);

        $this->artisan('storage:verify', [
            '--source-bucket' => 'media-src',
            '--target-bucket' => 'media-tgt',
            '--source-key' => 'AKIA-SRC',
            '--source-secret' => 'SECRET-SRC',
            '--target-key' => 'AKIA-TGT',
            '--target-secret' => 'SECRET-TGT',
            '--yes' => true,
        ])
            ->expectsOutputToContain('DISCREPANCY DETECTED')
            ->expectsOutputToContain('Discrepancy Detail Items')
            ->expectsOutputToContain('Missing in Target')
            ->expectsOutputToContain('Size Mismatch')
            ->expectsOutputToContain('Checksum Mismatch')
            ->expectsOutputToContain('deleted.jpg')
            ->expectsOutputToContain('resized.png')
            ->expectsOutputToContain('corrupted.pdf')
            ->expectsOutputToContain('Storage verification failed with discrepancies detected')
            ->assertExitCode(VerifyStorageCommand::FAILURE);
    });

    test('supports --only-mismatches flag to filter progress lines', function () {
        $mockService = Mockery::mock(StorageVerifyService::class);
        $mockResult = new StorageVerifyResult(
            sourceBucket: 'data-src',
            targetBucket: 'data-tgt',
            totalObjects: 2,
            matchedCount: 1,
            missingCount: 1,
            sizeMismatchCount: 0,
            checksumMismatchCount: 0,
            items: [
                StorageParityItem::matched('ok.txt', 100, 100, 'h1', 'h1'),
                StorageParityItem::missingInTarget('bad.txt', 200, 'h2'),
            ],
        );

        $mockService->shouldReceive('verify')
            ->once()
            ->andReturnUsing(function ($options) use ($mockResult) {
                if (isset($options['progress']) && is_callable($options['progress'])) {
                    $options['progress']($mockResult->items[0], 1, 2);
                    $options['progress']($mockResult->items[1], 2, 2);
                }

                return $mockResult;
            });

        $this->app->instance(StorageVerifyService::class, $mockService);

        $this->artisan('storage:verify', [
            '--source-bucket' => 'data-src',
            '--target-bucket' => 'data-tgt',
            '--source-key' => 'AKIA-SRC',
            '--source-secret' => 'SECRET-SRC',
            '--target-key' => 'AKIA-TGT',
            '--target-secret' => 'SECRET-TGT',
            '--only-mismatches' => true,
            '--yes' => true,
        ])
            ->doesntExpectOutputToContain('✓ [1/2] ok.txt')
            ->expectsOutputToContain('MISSING IN TARGET')
            ->assertExitCode(VerifyStorageCommand::FAILURE);
    });

    test('verifies multiple bucket pairs via --buckets option', function () {
        $mockService = Mockery::mock(StorageVerifyService::class);
        $resA = new StorageVerifyResult(sourceBucket: 'bucketA', targetBucket: 'bucketA-bak', totalObjects: 1, matchedCount: 1);
        $resB = new StorageVerifyResult(sourceBucket: 'bucketB', targetBucket: 'bucketB-bak', totalObjects: 1, matchedCount: 1);

        $mockService->shouldReceive('verify')
            ->twice()
            ->andReturn($resA, $resB);

        $this->app->instance(StorageVerifyService::class, $mockService);

        $this->artisan('storage:verify', [
            '--buckets' => ['bucketA:bucketA-bak,bucketB:bucketB-bak'],
            '--source-key' => 'AKIA-SRC',
            '--source-secret' => 'SECRET-SRC',
            '--target-key' => 'AKIA-TGT',
            '--target-secret' => 'SECRET-TGT',
            '--yes' => true,
        ])
            ->expectsOutputToContain('2 pair(s)')
            ->expectsOutputToContain('bucketA')
            ->expectsOutputToContain('bucketB')
            ->expectsOutputToContain('100% parity verified across all buckets')
            ->assertExitCode(VerifyStorageCommand::SUCCESS);
    });

    test('auto-discovers and verifies buckets from manifest file', function () {
        $tmpManifest = tempnam(sys_get_temp_dir(), 'manifest_verify_').'.json';
        file_put_contents($tmpManifest, json_encode([
            'version' => '1.0',
            'storage' => [
                [
                    'source_bucket' => 'manifest-src',
                    'target_bucket' => 'manifest-tgt',
                    'prefix' => 'avatars/',
                ],
            ],
        ]));

        $mockService = Mockery::mock(StorageVerifyService::class);
        $mockResult = new StorageVerifyResult(
            sourceBucket: 'manifest-src',
            targetBucket: 'manifest-tgt',
            totalObjects: 1,
            matchedCount: 1,
            prefix: 'avatars/',
        );

        $mockService->shouldReceive('verify')
            ->once()
            ->with(Mockery::on(fn ($opts) => ($opts['prefix'] ?? '') === 'avatars/'))
            ->andReturn($mockResult);

        $this->app->instance(StorageVerifyService::class, $mockService);

        try {
            $this->artisan('storage:verify', [
                '--manifest' => $tmpManifest,
                '--source-key' => 'AKIA-SRC',
                '--source-secret' => 'SECRET-SRC',
                '--target-key' => 'AKIA-TGT',
                '--target-secret' => 'SECRET-TGT',
                '--yes' => true,
            ])
                ->expectsOutputToContain('manifest-src')
                ->expectsOutputToContain('manifest-tgt')
                ->expectsOutputToContain('avatars/')
                ->assertExitCode(VerifyStorageCommand::SUCCESS);
        } finally {
            @unlink($tmpManifest);
        }
    });

    test('exports verification results to JSON file when --json option is passed', function () {
        $tmpJson = tempnam(sys_get_temp_dir(), 'export_verify_').'.json';
        @unlink($tmpJson);

        $mockService = Mockery::mock(StorageVerifyService::class);
        $mockResult = new StorageVerifyResult(
            sourceBucket: 'json-src',
            targetBucket: 'json-tgt',
            totalObjects: 1,
            matchedCount: 1,
            items: [
                StorageParityItem::matched('doc.pdf', 1024, 1024, 'hash123', 'hash123'),
            ],
        );

        $mockService->shouldReceive('verify')
            ->once()
            ->andReturn($mockResult);

        $this->app->instance(StorageVerifyService::class, $mockService);

        try {
            $this->artisan('storage:verify', [
                '--source-bucket' => 'json-src',
                '--target-bucket' => 'json-tgt',
                '--source-key' => 'AKIA-SRC',
                '--source-secret' => 'SECRET-SRC',
                '--target-key' => 'AKIA-TGT',
                '--target-secret' => 'SECRET-TGT',
                '--json' => $tmpJson,
                '--yes' => true,
            ])
                ->expectsOutputToContain("Exported verification results to {$tmpJson}")
                ->assertExitCode(VerifyStorageCommand::SUCCESS);

            expect(file_exists($tmpJson))->toBeTrue();
            $decoded = json_decode((string) file_get_contents($tmpJson), true);
            expect($decoded['is_parity_100'])->toBeTrue()
                ->and($decoded['total_buckets'])->toBe(1)
                ->and($decoded['buckets']['json-src']['total_objects'])->toBe(1)
                ->and($decoded['buckets']['json-src']['matched_count'])->toBe(1);
        } finally {
            @unlink($tmpJson);
        }
    });
});
