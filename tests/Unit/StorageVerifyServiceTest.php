<?php

use App\Data\StorageParityItem;
use App\Data\StorageVerifyResult;
use App\Services\StorageVerifyService;
use Aws\S3\S3Client;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;

describe('StorageVerifyService', function () {
    test('verifies 100% parity when source and target have identical files, sizes, and checksums', function () {
        $sourceAdapter = new InMemoryFilesystemAdapter;
        $targetAdapter = new InMemoryFilesystemAdapter;

        $sourceFs = new Filesystem($sourceAdapter);
        $targetFs = new Filesystem($targetAdapter);

        $sourceFs->write('documents/contract.pdf', 'PDF-DOCUMENT-CONTENT-12345');
        $targetFs->write('documents/contract.pdf', 'PDF-DOCUMENT-CONTENT-12345');

        $sourceFs->write('images/logo.png', 'PNG-IMAGE-PAYLOAD-999');
        $targetFs->write('images/logo.png', 'PNG-IMAGE-PAYLOAD-999');

        $service = new StorageVerifyService(
            source: $sourceFs,
            target: $targetFs,
            sourceBucket: 'src-bucket',
            targetBucket: 'tgt-bucket',
        );

        $result = $service->verify();

        expect($result)->toBeInstanceOf(StorageVerifyResult::class)
            ->and($result->totalObjects)->toBe(2)
            ->and($result->matchedCount)->toBe(2)
            ->and($result->missingCount)->toBe(0)
            ->and($result->sizeMismatchCount)->toBe(0)
            ->and($result->checksumMismatchCount)->toBe(0)
            ->and($result->isParity100())->toBeTrue()
            ->and($result->hasDiscrepancies())->toBeFalse()
            ->and($result->parityPercentage())->toBe(100.0)
            ->and($result->totalDiscrepancies())->toBe(0)
            ->and($result->getDiscrepancies())->toBeEmpty()
            ->and(count($result->getMatched()))->toBe(2);
    });

    test('detects missing object on target bucket', function () {
        $sourceAdapter = new InMemoryFilesystemAdapter;
        $targetAdapter = new InMemoryFilesystemAdapter;

        $sourceFs = new Filesystem($sourceAdapter);
        $targetFs = new Filesystem($targetAdapter);

        $sourceFs->write('existing.txt', 'EXISTS');
        $targetFs->write('existing.txt', 'EXISTS');

        $sourceFs->write('missing.txt', 'NOT-ON-TARGET');

        $service = new StorageVerifyService(
            source: $sourceFs,
            target: $targetFs,
            sourceBucket: 'src-bucket',
            targetBucket: 'tgt-bucket',
        );

        $result = $service->verify();

        expect($result->totalObjects)->toBe(2)
            ->and($result->matchedCount)->toBe(1)
            ->and($result->missingCount)->toBe(1)
            ->and($result->isParity100())->toBeFalse()
            ->and($result->hasDiscrepancies())->toBeTrue()
            ->and($result->parityPercentage())->toBe(50.0);

        $discrepancies = $result->getDiscrepancies();
        expect($discrepancies)->toHaveCount(1)
            ->and($discrepancies[0]->path)->toBe('missing.txt')
            ->and($discrepancies[0]->isMissingInTarget())->toBeTrue()
            ->and($discrepancies[0]->status)->toBe(StorageParityItem::STATUS_MISSING_IN_TARGET)
            ->and($discrepancies[0]->targetSize)->toBeNull()
            ->and($discrepancies[0]->targetChecksum)->toBeNull();
    });

    test('detects size mismatch when target file has different size', function () {
        $sourceAdapter = new InMemoryFilesystemAdapter;
        $targetAdapter = new InMemoryFilesystemAdapter;

        $sourceFs = new Filesystem($sourceAdapter);
        $targetFs = new Filesystem($targetAdapter);

        $sourceFs->write('archive.zip', 'SHORT-CONTENT');
        $targetFs->write('archive.zip', 'MUCH-LONGER-CORRUPTED-TARGET-CONTENT');

        $service = new StorageVerifyService(
            source: $sourceFs,
            target: $targetFs,
            sourceBucket: 'src-bucket',
            targetBucket: 'tgt-bucket',
        );

        $result = $service->verify();

        expect($result->totalObjects)->toBe(1)
            ->and($result->matchedCount)->toBe(0)
            ->and($result->sizeMismatchCount)->toBe(1)
            ->and($result->isParity100())->toBeFalse()
            ->and($result->hasDiscrepancies())->toBeTrue();

        $item = $result->items[0];
        expect($item->isSizeMismatch())->toBeTrue()
            ->and($item->status)->toBe(StorageParityItem::STATUS_SIZE_MISMATCH)
            ->and($item->sourceSize)->toBe(strlen('SHORT-CONTENT'))
            ->and($item->targetSize)->toBe(strlen('MUCH-LONGER-CORRUPTED-TARGET-CONTENT'))
            ->and($item->message)->toContain('Size mismatch');
    });

    test('detects checksum mismatch when sizes match but contents differ', function () {
        $sourceAdapter = new InMemoryFilesystemAdapter;
        $targetAdapter = new InMemoryFilesystemAdapter;

        $sourceFs = new Filesystem($sourceAdapter);
        $targetFs = new Filesystem($targetAdapter);

        // Same byte length (10 chars), but different content
        $sourceFs->write('payload.dat', '1234567890');
        $targetFs->write('payload.dat', '0987654321');

        $service = new StorageVerifyService(
            source: $sourceFs,
            target: $targetFs,
            sourceBucket: 'src-bucket',
            targetBucket: 'tgt-bucket',
        );

        $result = $service->verify();

        expect($result->totalObjects)->toBe(1)
            ->and($result->matchedCount)->toBe(0)
            ->and($result->checksumMismatchCount)->toBe(1)
            ->and($result->isParity100())->toBeFalse();

        $item = $result->items[0];
        expect($item->isChecksumMismatch())->toBeTrue()
            ->and($item->status)->toBe(StorageParityItem::STATUS_CHECKSUM_MISMATCH)
            ->and($item->sourceSize)->toBe(10)
            ->and($item->targetSize)->toBe(10)
            ->and($item->sourceChecksum)->not->toBe($item->targetChecksum)
            ->and($item->message)->toContain('Checksum mismatch');
    });

    test('normalizes multipart and quoted hex ETags accurately', function () {
        $service = new StorageVerifyService;

        // Exact match
        expect($service->checksumsMatch('d41d8cd98f00b204e9800998ecf8427e', 'd41d8cd98f00b204e9800998ecf8427e'))->toBeTrue();

        // Quoted hex normalization & case insensitivity
        expect($service->checksumsMatch('"D41D8CD98F00B204E9800998ECF8427E"', 'd41d8cd98f00b204e9800998ecf8427e'))->toBeTrue()
            ->and($service->checksumsMatch("'d41d8cd98f00b204e9800998ecf8427e'", 'D41D8CD98F00B204E9800998ECF8427E'))->toBeTrue();

        // Multipart ETag format: <hash>-<part_count>
        $multipart1 = 'c032a76f2d4e8971d624a68297b83321-4';
        $multipartQuoted = '"C032A76F2D4E8971D624A68297B83321-4"';
        expect($service->checksumsMatch($multipart1, $multipartQuoted))->toBeTrue();

        // Different multipart part count or hash
        expect($service->checksumsMatch('c032a76f2d4e8971d624a68297b83321-4', 'c032a76f2d4e8971d624a68297b83321-5'))->toBeFalse()
            ->and($service->checksumsMatch('c032a76f2d4e8971d624a68297b83321-4', 'ffffffffffffffffffffffffffffffff-4'))->toBeFalse();

        // Null comparisons
        expect($service->checksumsMatch(null, 'd41d8cd98f00b204e9800998ecf8427e'))->toBeFalse()
            ->and($service->checksumsMatch('d41d8cd98f00b204e9800998ecf8427e', null))->toBeFalse()
            ->and($service->checksumsMatch(null, null))->toBeFalse();

        // Multipart detection helper
        expect(StorageParityItem::detectMultipart('c032a76f2d4e8971d624a68297b83321-4'))->toBeTrue()
            ->and(StorageParityItem::detectMultipart('"C032A76F2D4E8971D624A68297B83321-12"'))->toBeTrue()
            ->and(StorageParityItem::detectMultipart('d41d8cd98f00b204e9800998ecf8427e'))->toBeFalse()
            ->and(StorageParityItem::detectMultipart(null))->toBeFalse();
    });

    test('supports prefix filtering during audit', function () {
        $sourceAdapter = new InMemoryFilesystemAdapter;
        $targetAdapter = new InMemoryFilesystemAdapter;

        $sourceFs = new Filesystem($sourceAdapter);
        $targetFs = new Filesystem($targetAdapter);

        $sourceFs->write('uploads/2026/01/photo.jpg', 'PHOTO');
        $targetFs->write('uploads/2026/01/photo.jpg', 'PHOTO');

        $sourceFs->write('uploads/2026/02/video.mp4', 'VIDEO');
        $targetFs->write('uploads/2026/02/video.mp4', 'VIDEO');

        $sourceFs->write('private/secret.key', 'KEY');
        // private/secret.key is missing on target, but should be ignored with prefix 'uploads'

        $service = new StorageVerifyService(
            source: $sourceFs,
            target: $targetFs,
            sourceBucket: 'src-bucket',
            targetBucket: 'tgt-bucket',
        );

        $result = $service->verify([
            'prefix' => 'uploads',
        ]);

        expect($result->totalObjects)->toBe(2)
            ->and($result->matchedCount)->toBe(2)
            ->and($result->missingCount)->toBe(0)
            ->and($result->isParity100())->toBeTrue()
            ->and($result->prefix)->toBe('uploads');
    });

    test('uses extraMetadata ETag if available from file attributes without stream read', function () {
        $sourceAdapter = new InMemoryFilesystemAdapter;
        $targetAdapter = new InMemoryFilesystemAdapter;

        $sourceFs = new Filesystem($sourceAdapter);
        $targetFs = new Filesystem($targetAdapter);

        $sourceFs->write('file.bin', 'DATA');
        $targetFs->write('file.bin', 'DATA');

        $service = new StorageVerifyService(
            source: $sourceFs,
            target: $targetFs,
            sourceBucket: 'src-bucket',
            targetBucket: 'tgt-bucket',
        );

        $sourceAttr = new FileAttributes(
            path: 'file.bin',
            fileSize: 4,
            extraMetadata: ['ETag' => '"c032a76f2d4e8971d624a68297b83321-8"']
        );

        $targetAttr = new FileAttributes(
            path: 'file.bin',
            fileSize: 4,
            extraMetadata: ['ETag' => 'c032a76f2d4e8971d624a68297b83321-8']
        );

        $item = $service->verifyFile('file.bin', $sourceAttr, $targetAttr);

        expect($item->isMatched())->toBeTrue()
            ->and($item->sourceChecksum)->toBe('c032a76f2d4e8971d624a68297b83321-8')
            ->and($item->targetChecksum)->toBe('c032a76f2d4e8971d624a68297b83321-8')
            ->and($item->isSourceMultipart())->toBeTrue()
            ->and($item->isTargetMultipart())->toBeTrue();
    });

    test('supports custom checksum resolver for testing or specialized object providers', function () {
        $sourceAdapter = new InMemoryFilesystemAdapter;
        $targetAdapter = new InMemoryFilesystemAdapter;

        $sourceFs = new Filesystem($sourceAdapter);
        $targetFs = new Filesystem($targetAdapter);

        $sourceFs->write('custom.xml', 'CONTENT');
        $targetFs->write('custom.xml', 'CONTENT');

        $service = new StorageVerifyService(
            source: $sourceFs,
            target: $targetFs,
            sourceBucket: 'src-bucket',
            targetBucket: 'tgt-bucket',
            checksumResolver: function ($storage, $path) {
                return 'custom-hash-'.$path;
            },
        );

        $item = $service->verifyFile('custom.xml');

        expect($item->isMatched())->toBeTrue()
            ->and($item->sourceChecksum)->toBe('custom-hash-custom.xml')
            ->and($item->targetChecksum)->toBe('custom-hash-custom.xml');
    });

    test('handles empty source bucket returning 100% parity', function () {
        $sourceAdapter = new InMemoryFilesystemAdapter;
        $targetAdapter = new InMemoryFilesystemAdapter;

        $sourceFs = new Filesystem($sourceAdapter);
        $targetFs = new Filesystem($targetAdapter);

        $service = new StorageVerifyService(
            source: $sourceFs,
            target: $targetFs,
            sourceBucket: 'empty-src',
            targetBucket: 'empty-tgt',
        );

        $result = $service->verify();

        expect($result->totalObjects)->toBe(0)
            ->and($result->matchedCount)->toBe(0)
            ->and($result->isParity100())->toBeTrue()
            ->and($result->parityPercentage())->toBe(100.0)
            ->and($result->items)->toBeEmpty();
    });

    test('DTO helper methods format metrics and serialize to array', function () {
        $item1 = StorageParityItem::matched('a.txt', 100, 100, 'hash1', 'hash1');
        $item2 = StorageParityItem::missingInTarget('b.txt', 200, 'hash2');
        $item3 = StorageParityItem::sizeMismatch('c.txt', 300, 400, 'hash3', 'hash3');
        $item4 = StorageParityItem::checksumMismatch('d.txt', 500, 500, 'h4a', 'h4b');
        $item5 = StorageParityItem::error('e.txt', 'Permission denied', 600);

        expect($item1->isMatched())->toBeTrue()
            ->and($item2->isMissingInTarget())->toBeTrue()
            ->and($item3->isSizeMismatch())->toBeTrue()
            ->and($item4->isChecksumMismatch())->toBeTrue()
            ->and($item5->isError())->toBeTrue()
            ->and($item1->toArray()['status'])->toBe('matched')
            ->and($item2->toArray()['status'])->toBe('missing_in_target');

        $result = new StorageVerifyResult(
            sourceBucket: 'src',
            targetBucket: 'tgt',
            totalObjects: 5,
            matchedCount: 1,
            missingCount: 1,
            sizeMismatchCount: 1,
            checksumMismatchCount: 1,
            errorCount: 1,
            totalSourceBytes: 1700,
            totalTargetBytes: 1000,
            durationSeconds: 1.25,
            items: [$item1, $item2, $item3, $item4, $item5],
            errors: ['Error on e.txt'],
            prefix: 'docs/',
        );

        expect($result->isParity100())->toBeFalse()
            ->and($result->hasDiscrepancies())->toBeTrue()
            ->and($result->totalDiscrepancies())->toBe(4)
            ->and($result->parityPercentage())->toBe(20.0)
            ->and($result->formattedSourceBytes())->toContain('KB')
            ->and($result->formattedTargetBytes())->toContain('B')
            ->and($result->toArray()['is_parity_100'])->toBeFalse()
            ->and($result->toArray()['total_discrepancies'])->toBe(4)
            ->and(count($result->getDiscrepancies()))->toBe(4);
    });

    test('buildS3Client configures S3 client with region and credentials', function () {
        $client = StorageVerifyService::buildS3Client([
            'region' => 'ap-southeast-1',
            'endpoint' => 'https://r2.cloudflarestorage.com',
            'key' => 'TEST_KEY',
            'secret' => 'TEST_SECRET',
        ]);

        expect($client)->toBeInstanceOf(S3Client::class)
            ->and($client->getRegion())->toBe('ap-southeast-1')
            ->and((string) $client->getEndpoint())->toContain('r2.cloudflarestorage.com');
    });
});
