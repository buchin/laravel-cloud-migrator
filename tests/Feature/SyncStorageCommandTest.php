<?php

use App\Commands\SyncStorageCommand;
use App\Data\SyncFileResult;
use App\Data\SyncResult;
use App\Services\StorageSyncService;

describe('SyncStorageCommand Feature', function () {
    test('fails with clear error when manifest file does not exist', function () {
        $this->artisan('storage:sync', [
            '--manifest' => '/nonexistent/path/to/manifest.json',
            '--yes' => true,
        ])
            ->expectsOutputToContain('Specified manifest file does not exist')
            ->assertExitCode(SyncStorageCommand::FAILURE);
    });

    test('fails when no buckets are specified in non-interactive mode', function () {
        $this->artisan('storage:sync', [
            '--source-key' => 'test-src-key',
            '--source-secret' => 'test-src-secret',
            '--target-key' => 'test-tgt-key',
            '--target-secret' => 'test-tgt-secret',
            '--yes' => true,
        ])
            ->expectsOutputToContain('No source and target buckets specified')
            ->assertExitCode(SyncStorageCommand::FAILURE);
    });

    test('fails when credentials are missing in non-interactive mode', function () {
        // Clear AWS env variables during test
        putenv('SOURCE_AWS_ACCESS_KEY_ID=');
        putenv('SOURCE_AWS_SECRET_ACCESS_KEY=');
        putenv('TARGET_AWS_ACCESS_KEY_ID=');
        putenv('TARGET_AWS_SECRET_ACCESS_KEY=');
        putenv('AWS_ACCESS_KEY_ID=');
        putenv('AWS_SECRET_ACCESS_KEY=');

        $this->artisan('storage:sync', [
            '--source-bucket' => 'src-bucket',
            '--target-bucket' => 'tgt-bucket',
            '--yes' => true,
        ])
            ->expectsOutputToContain('Missing S3 credentials')
            ->assertExitCode(SyncStorageCommand::FAILURE);
    });

    test('executes live storage sync and renders progress lines and summary table', function () {
        $mockService = Mockery::mock(StorageSyncService::class);
        $mockResult = new SyncResult(
            sourceBucket: 'photos-src',
            targetBucket: 'photos-tgt',
            totalFiles: 2,
            transferredFiles: 2,
            skippedFiles: 0,
            failedFiles: 0,
            deletedFiles: 0,
            transferredBytes: 2048,
            totalBytes: 2048,
            durationSeconds: 0.5,
            fileResults: [
                SyncFileResult::transferred('img1.jpg', 1024, 'hash1'),
                SyncFileResult::transferred('img2.jpg', 1024, 'hash2'),
            ],
            errors: [],
            dryRun: false,
        );

        $mockService->shouldReceive('sync')
            ->once()
            ->andReturnUsing(function ($options) use ($mockResult) {
                if (isset($options['progress']) && is_callable($options['progress'])) {
                    $options['progress'](SyncFileResult::transferred('img1.jpg', 1024, 'hash1'), 1, 2);
                    $options['progress'](SyncFileResult::transferred('img2.jpg', 1024, 'hash2'), 2, 2);
                }

                return $mockResult;
            });

        $this->app->instance(StorageSyncService::class, $mockService);

        $this->artisan('storage:sync', [
            '--source-bucket' => 'photos-src',
            '--target-bucket' => 'photos-tgt',
            '--source-key' => 'AKIA-SRC',
            '--source-secret' => 'SECRET-SRC',
            '--target-key' => 'AKIA-TGT',
            '--target-secret' => 'SECRET-TGT',
            '--yes' => true,
        ])
            ->expectsOutputToContain('Migration Pre-Flight Plan')
            ->expectsOutputToContain('photos-src')
            ->expectsOutputToContain('photos-tgt')
            ->expectsOutputToContain('LIVE SYNC')
            ->expectsOutputToContain('img1.jpg')
            ->expectsOutputToContain('img2.jpg')
            ->expectsOutputToContain('Storage synchronization completed successfully')
            ->assertExitCode(SyncStorageCommand::SUCCESS);
    });

    test('executes dry-run mode and displays simulation details', function () {
        $mockService = Mockery::mock(StorageSyncService::class);
        $mockResult = new SyncResult(
            sourceBucket: 'media-src',
            targetBucket: 'media-tgt',
            totalFiles: 1,
            transferredFiles: 1,
            skippedFiles: 0,
            failedFiles: 0,
            deletedFiles: 0,
            transferredBytes: 500,
            totalBytes: 500,
            durationSeconds: 0.1,
            fileResults: [
                SyncFileResult::planned('doc.pdf', 500, 'h1'),
            ],
            errors: [],
            dryRun: true,
        );

        $mockService->shouldReceive('sync')
            ->once()
            ->with(Mockery::on(fn ($opts) => ($opts['dry_run'] ?? false) === true))
            ->andReturn($mockResult);

        $this->app->instance(StorageSyncService::class, $mockService);

        $this->artisan('storage:sync', [
            '--source-bucket' => 'media-src',
            '--target-bucket' => 'media-tgt',
            '--source-key' => 'AKIA-SRC',
            '--source-secret' => 'SECRET-SRC',
            '--target-key' => 'AKIA-TGT',
            '--target-secret' => 'SECRET-TGT',
            '--dry-run' => true,
            '--yes' => true,
        ])
            ->expectsOutputToContain('DRY-RUN (simulation only)')
            ->expectsOutputToContain('DRY-RUN')
            ->assertExitCode(SyncStorageCommand::SUCCESS);
    });

    test('processes multi-bucket sync using --buckets option', function () {
        $mockService = Mockery::mock(StorageSyncService::class);
        $mockResult1 = new SyncResult(sourceBucket: 'bucketA', targetBucket: 'bucketA-bak', totalFiles: 1, transferredFiles: 1);
        $mockResult2 = new SyncResult(sourceBucket: 'bucketB', targetBucket: 'bucketB-bak', totalFiles: 1, transferredFiles: 1);

        $mockService->shouldReceive('sync')
            ->twice()
            ->andReturn($mockResult1, $mockResult2);

        $this->app->instance(StorageSyncService::class, $mockService);

        $this->artisan('storage:sync', [
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
            ->assertExitCode(SyncStorageCommand::SUCCESS);
    });

    test('loads storage bucket definitions from manifest file', function () {
        $tmpManifest = tempnam(sys_get_temp_dir(), 'manifest_test_').'.json';
        file_put_contents($tmpManifest, json_encode([
            'version' => '1.0',
            'storage' => [
                [
                    'source_bucket' => 'manifest-src',
                    'target_bucket' => 'manifest-tgt',
                    'prefix' => 'app-assets/',
                ],
            ],
        ]));

        $mockService = Mockery::mock(StorageSyncService::class);
        $mockResult = new SyncResult(sourceBucket: 'manifest-src', targetBucket: 'manifest-tgt', totalFiles: 1, transferredFiles: 1);

        $mockService->shouldReceive('sync')
            ->once()
            ->with(Mockery::on(fn ($opts) => ($opts['prefix'] ?? '') === 'app-assets/'))
            ->andReturn($mockResult);

        $this->app->instance(StorageSyncService::class, $mockService);

        try {
            $this->artisan('storage:sync', [
                '--manifest' => $tmpManifest,
                '--source-key' => 'AKIA-SRC',
                '--source-secret' => 'SECRET-SRC',
                '--target-key' => 'AKIA-TGT',
                '--target-secret' => 'SECRET-TGT',
                '--yes' => true,
            ])
                ->expectsOutputToContain('manifest-src')
                ->expectsOutputToContain('manifest-tgt')
                ->assertExitCode(SyncStorageCommand::SUCCESS);
        } finally {
            @unlink($tmpManifest);
        }
    });

    test('returns failure exit code when synchronization has errors', function () {
        $mockService = Mockery::mock(StorageSyncService::class);
        $mockResult = new SyncResult(
            sourceBucket: 'err-src',
            targetBucket: 'err-tgt',
            totalFiles: 1,
            transferredFiles: 0,
            failedFiles: 1,
            errors: ['Failed to upload file.png'],
        );

        $mockService->shouldReceive('sync')
            ->once()
            ->andReturn($mockResult);

        $this->app->instance(StorageSyncService::class, $mockService);

        $this->artisan('storage:sync', [
            '--source-bucket' => 'err-src',
            '--target-bucket' => 'err-tgt',
            '--source-key' => 'AKIA-SRC',
            '--source-secret' => 'SECRET-SRC',
            '--target-key' => 'AKIA-TGT',
            '--target-secret' => 'SECRET-TGT',
            '--yes' => true,
        ])
            ->expectsOutputToContain('Storage sync completed with errors')
            ->assertExitCode(SyncStorageCommand::FAILURE);
    });
});
