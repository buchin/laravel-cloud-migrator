<?php

use App\Data\SyncFileResult;
use App\Data\SyncResult;
use App\Exceptions\IntegrityException;
use App\Services\StorageSyncService;
use Aws\S3\S3Client;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;

describe('StorageSyncService', function () {
    test('transfers file using direct streaming pointer and verifies integrity', function () {
        $sourceAdapter = new InMemoryFilesystemAdapter;
        $targetAdapter = new InMemoryFilesystemAdapter;

        $sourceFs = new Filesystem($sourceAdapter);
        $targetFs = new Filesystem($targetAdapter);

        $sourceFs->write('documents/report.pdf', 'PDF-CONTENT-DATA-12345');

        $service = new StorageSyncService(
            source: $sourceFs,
            target: $targetFs,
            sourceBucket: 'src-bucket',
            targetBucket: 'tgt-bucket',
        );

        $result = $service->syncFile('documents/report.pdf');

        expect($result)->toBeInstanceOf(SyncFileResult::class)
            ->and($result->isTransferred())->toBeTrue()
            ->and($result->isSuccess())->toBeTrue()
            ->and($result->path)->toBe('documents/report.pdf')
            ->and($result->size)->toBe(strlen('PDF-CONTENT-DATA-12345'))
            ->and($result->attempts)->toBe(1)
            ->and($targetFs->fileExists('documents/report.pdf'))->toBeTrue()
            ->and($targetFs->read('documents/report.pdf'))->toBe('PDF-CONTENT-DATA-12345');
    });

    test('skips file transfer if target already has identical checksum and size', function () {
        $sourceAdapter = new InMemoryFilesystemAdapter;
        $targetAdapter = new InMemoryFilesystemAdapter;

        $sourceFs = new Filesystem($sourceAdapter);
        $targetFs = new Filesystem($targetAdapter);

        $content = 'IDENTICAL-PAYLOAD-999';
        $sourceFs->write('avatar.png', $content);
        $targetFs->write('avatar.png', $content);

        $service = new StorageSyncService(
            source: $sourceFs,
            target: $targetFs,
            sourceBucket: 'src-bucket',
            targetBucket: 'tgt-bucket',
        );

        $result = $service->syncFile('avatar.png');

        expect($result->isSkipped())->toBeTrue()
            ->and($result->isSuccess())->toBeTrue()
            ->and($result->message)->toContain('Identical');
    });

    test('overwrites target if size or checksum differs', function () {
        $sourceAdapter = new InMemoryFilesystemAdapter;
        $targetAdapter = new InMemoryFilesystemAdapter;

        $sourceFs = new Filesystem($sourceAdapter);
        $targetFs = new Filesystem($targetAdapter);

        $sourceFs->write('data.csv', 'new,fresh,records');
        $targetFs->write('data.csv', 'old,stale,content,that,is,longer');

        $service = new StorageSyncService(
            source: $sourceFs,
            target: $targetFs,
            sourceBucket: 'src-bucket',
            targetBucket: 'tgt-bucket',
        );

        $result = $service->syncFile('data.csv');

        expect($result->isTransferred())->toBeTrue()
            ->and($targetFs->read('data.csv'))->toBe('new,fresh,records');
    });

    test('simulates transfer in dry-run mode without modifying target', function () {
        $sourceAdapter = new InMemoryFilesystemAdapter;
        $targetAdapter = new InMemoryFilesystemAdapter;

        $sourceFs = new Filesystem($sourceAdapter);
        $targetFs = new Filesystem($targetAdapter);

        $sourceFs->write('banner.jpg', 'IMAGE-BYTES');

        $service = new StorageSyncService(
            source: $sourceFs,
            target: $targetFs,
            sourceBucket: 'src-bucket',
            targetBucket: 'tgt-bucket',
        );

        $result = $service->syncFile('banner.jpg', dryRun: true);

        expect($result->isPlanned())->toBeTrue()
            ->and($result->isSuccess())->toBeTrue()
            ->and($result->message)->toContain('Dry-run')
            ->and($targetFs->fileExists('banner.jpg'))->toBeFalse();
    });

    test('sync processes multiple files with prefix filtering', function () {
        $sourceAdapter = new InMemoryFilesystemAdapter;
        $targetAdapter = new InMemoryFilesystemAdapter;

        $sourceFs = new Filesystem($sourceAdapter);
        $targetFs = new Filesystem($targetAdapter);

        $sourceFs->write('uploads/2026/01/a.txt', 'AAA');
        $sourceFs->write('uploads/2026/02/b.txt', 'BBB');
        $sourceFs->write('other/c.txt', 'CCC');

        $service = new StorageSyncService(
            source: $sourceFs,
            target: $targetFs,
            sourceBucket: 'src-bucket',
            targetBucket: 'tgt-bucket',
        );

        $syncResult = $service->sync([
            'prefix' => 'uploads',
        ]);

        expect($syncResult)->toBeInstanceOf(SyncResult::class)
            ->and($syncResult->totalFiles)->toBe(2)
            ->and($syncResult->transferredFiles)->toBe(2)
            ->and($syncResult->hasFailures())->toBeFalse()
            ->and($targetFs->fileExists('uploads/2026/01/a.txt'))->toBeTrue()
            ->and($targetFs->fileExists('uploads/2026/02/b.txt'))->toBeTrue()
            ->and($targetFs->fileExists('other/c.txt'))->toBeFalse();
    });

    test('delete-missing removes orphan objects on target that do not exist on source', function () {
        $sourceAdapter = new InMemoryFilesystemAdapter;
        $targetAdapter = new InMemoryFilesystemAdapter;

        $sourceFs = new Filesystem($sourceAdapter);
        $targetFs = new Filesystem($targetAdapter);

        $sourceFs->write('keep.txt', 'KEEP');
        $targetFs->write('keep.txt', 'KEEP');
        $targetFs->write('orphan.txt', 'REMOVE-ME');

        $service = new StorageSyncService(
            source: $sourceFs,
            target: $targetFs,
            sourceBucket: 'src-bucket',
            targetBucket: 'tgt-bucket',
        );

        $syncResult = $service->sync([
            'delete_missing' => true,
        ]);

        expect($syncResult->deletedFiles)->toBe(1)
            ->and($targetFs->fileExists('keep.txt'))->toBeTrue()
            ->and($targetFs->fileExists('orphan.txt'))->toBeFalse();
    });

    test('delete-missing in dry-run mode reports planned deletes without removing target objects', function () {
        $sourceAdapter = new InMemoryFilesystemAdapter;
        $targetAdapter = new InMemoryFilesystemAdapter;

        $sourceFs = new Filesystem($sourceAdapter);
        $targetFs = new Filesystem($targetAdapter);

        $sourceFs->write('file1.txt', '111');
        $targetFs->write('file1.txt', '111');
        $targetFs->write('orphan.txt', 'REMOVE-ME');

        $service = new StorageSyncService(
            source: $sourceFs,
            target: $targetFs,
            sourceBucket: 'src-bucket',
            targetBucket: 'tgt-bucket',
        );

        $syncResult = $service->sync([
            'delete_missing' => true,
            'dry_run' => true,
        ]);

        expect($syncResult->deletedFiles)->toBe(1)
            ->and($targetFs->fileExists('orphan.txt'))->toBeTrue();
    });

    test('retries transient failures and succeeds on subsequent attempt', function () {
        $sourceAdapter = new InMemoryFilesystemAdapter;
        $targetAdapter = new InMemoryFilesystemAdapter;

        $sourceFs = new Filesystem($sourceAdapter);
        $targetFs = new Filesystem($targetAdapter);

        $sourceFs->write('flaky.txt', 'FLAKY-DATA');

        // Wrap target filesystem with transient failure on first write attempt
        $attempts = 0;
        $mockTarget = Mockery::mock($targetFs)->makePartial();
        $mockTarget->shouldReceive('writeStream')
            ->andReturnUsing(function ($path, $resource, $config) use (&$attempts, $targetFs) {
                $attempts++;
                if ($attempts === 1) {
                    throw new RuntimeException('Connection reset by peer (temporary)');
                }

                return $targetFs->writeStream($path, $resource, $config);
            });

        $service = new StorageSyncService(
            source: $sourceFs,
            target: $mockTarget,
            sourceBucket: 'src-bucket',
            targetBucket: 'tgt-bucket',
            maxRetries: 3,
            retryDelayMs: 1,
        );

        $result = $service->syncFile('flaky.txt');

        expect($result->isTransferred())->toBeTrue()
            ->and($result->attempts)->toBe(2)
            ->and($targetFs->read('flaky.txt'))->toBe('FLAKY-DATA');
    });

    test('fails and records error when max retries are exhausted on permanent error', function () {
        $sourceAdapter = new InMemoryFilesystemAdapter;
        $targetAdapter = new InMemoryFilesystemAdapter;

        $sourceFs = new Filesystem($sourceAdapter);
        $targetFs = new Filesystem($targetAdapter);

        $sourceFs->write('permanent-fail.txt', 'DATA');

        $mockTarget = Mockery::mock($targetFs)->makePartial();
        $mockTarget->shouldReceive('writeStream')
            ->andThrow(new RuntimeException('Fatal unrecoverable permission denied'));

        $service = new StorageSyncService(
            source: $sourceFs,
            target: $mockTarget,
            sourceBucket: 'src-bucket',
            targetBucket: 'tgt-bucket',
            maxRetries: 3,
            retryDelayMs: 1,
        );

        $result = $service->syncFile('permanent-fail.txt');

        expect($result->isFailed())->toBeTrue()
            ->and($result->error)->toContain('Fatal unrecoverable permission denied');
    });

    test('integrity verification detects size parity mismatch and throws IntegrityException', function () {
        $sourceAdapter = new InMemoryFilesystemAdapter;
        $targetAdapter = new InMemoryFilesystemAdapter;

        $sourceFs = new Filesystem($sourceAdapter);
        $targetFs = new Filesystem($targetAdapter);

        $sourceFs->write('test.bin', '1234567890');
        $targetFs->write('test.bin', '12345'); // shorter

        $service = new StorageSyncService(
            source: $sourceFs,
            target: $targetFs,
            sourceBucket: 'src-bucket',
            targetBucket: 'tgt-bucket',
        );

        expect(fn () => $service->verifyIntegrity('test.bin', 10, null))
            ->toThrow(IntegrityException::class, 'Size parity mismatch');
    });

    test('integrity verification detects checksum mismatch and throws IntegrityException', function () {
        $sourceAdapter = new InMemoryFilesystemAdapter;
        $targetAdapter = new InMemoryFilesystemAdapter;

        $sourceFs = new Filesystem($sourceAdapter);
        $targetFs = new Filesystem($targetAdapter);

        $sourceFs->write('test.txt', 'HELLO-A');
        $targetFs->write('test.txt', 'HELLO-B');

        $service = new StorageSyncService(
            source: $sourceFs,
            target: $targetFs,
            sourceBucket: 'src-bucket',
            targetBucket: 'tgt-bucket',
        );

        expect(fn () => $service->verifyIntegrity('test.txt', strlen('HELLO-A'), 'expected-md5-sum'))
            ->toThrow(IntegrityException::class, 'Checksum mismatch');
    });

    test('checksumsMatch correctly handles quoted strings, case insensitivity, and multipart ETags', function () {
        $service = new StorageSyncService;

        // Identical MD5
        expect($service->checksumsMatch('5eb63bbbe01eeed093cb22bb8f5acdc3', '5eb63bbbe01eeed093cb22bb8f5acdc3'))->toBeTrue()
            // Quoted ETags from S3
            ->and($service->checksumsMatch('"5EB63BBBE01EEED093CB22BB8F5ACDC3"', '5eb63bbbe01eeed093cb22bb8f5acdc3'))->toBeTrue()
            // Multipart ETags matching
            ->and($service->checksumsMatch('"c032a76f2d4e8971d624a68297b83321-4"', 'c032a76f2d4e8971d624a68297b83321-4'))->toBeTrue()
            // Different non-multipart MD5s
            ->and($service->checksumsMatch('11111111111111111111111111111111', '22222222222222222222222222222222'))->toBeFalse()
            // Null checksums
            ->and($service->checksumsMatch(null, '5eb63bbbe01eeed093cb22bb8f5acdc3'))->toBeFalse();
    });

    test('partitionPaths partitions files evenly across concurrency workers', function () {
        $service = new StorageSyncService;
        $paths = ['file1', 'file2', 'file3', 'file4', 'file5', 'file6', 'file7'];

        $partitions = $service->partitionPaths($paths, 3);

        expect($partitions)->toHaveCount(3)
            ->and($partitions[0])->toBe(['file1', 'file4', 'file7'])
            ->and($partitions[1])->toBe(['file2', 'file5'])
            ->and($partitions[2])->toBe(['file3', 'file6']);
    });

    test('buildS3Client configures S3-compatible custom endpoints and credentials', function () {
        $client = StorageSyncService::buildS3Client([
            'region' => 'auto',
            'endpoint' => 'https://accountid.r2.cloudflarestorage.com',
            'key' => 'R2_KEY',
            'secret' => 'R2_SECRET',
        ]);

        expect($client)->toBeInstanceOf(S3Client::class)
            ->and($client->getRegion())->toBe('auto')
            ->and((string) $client->getEndpoint())->toContain('r2.cloudflarestorage.com');
    });

    test('formatBytes and throughput calculate metric representations accurately', function () {
        expect(SyncResult::formatBytes(0))->toBe('0 B')
            ->and(SyncResult::formatBytes(1024))->toBe('1 KB')
            ->and(SyncResult::formatBytes(1048576))->toBe('1 MB')
            ->and(SyncResult::formatBytes(1073741824))->toBe('1 GB');

        $result = new SyncResult(
            sourceBucket: 'src',
            targetBucket: 'tgt',
            transferredBytes: 10485760, // 10 MB
            durationSeconds: 2.0,       // 2 sec
        );

        expect($result->throughput())->toBe('5 MB/s');
    });
});
