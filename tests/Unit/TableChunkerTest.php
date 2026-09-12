<?php

use App\Services\TableChunker;

test('shouldChunk returns false when table size and row count are below threshold', function () {
    $chunker = new TableChunker;

    // 500 MB and 50,000 rows
    expect($chunker->shouldChunk(500 * 1024 * 1024, 50_000))->toBeFalse();

    // Exactly 1 GB and exactly 100,000 rows (boundary check)
    expect($chunker->shouldChunk(TableChunker::DEFAULT_SIZE_THRESHOLD, TableChunker::DEFAULT_ROW_THRESHOLD))->toBeFalse();
});

test('shouldChunk returns true when table size exceeds 1 GB regardless of row count', function () {
    $chunker = new TableChunker;

    // 1 GB + 1 byte with only 10 rows
    $oneGbPlus = TableChunker::DEFAULT_SIZE_THRESHOLD + 1;
    expect($chunker->shouldChunk($oneGbPlus, 10))->toBeTrue();

    // 2 GB with 50,000 rows
    expect($chunker->shouldChunk(2 * 1024 * 1024 * 1024, 50_000))->toBeTrue();
});

test('shouldChunk returns true when row count exceeds 100,000 rows regardless of table size', function () {
    $chunker = new TableChunker;

    // 100,001 rows with small size (10 MB)
    expect($chunker->shouldChunk(10 * 1024 * 1024, 100_001))->toBeTrue();

    // 500,000 rows with 50 MB
    expect($chunker->shouldChunk(50 * 1024 * 1024, 500_000))->toBeTrue();
});

test('shouldChunk supports custom size and row thresholds', function () {
    $chunker = new TableChunker;

    // Custom threshold: 10 MB or 1,000 rows
    expect($chunker->shouldChunk(5 * 1024 * 1024, 500, 10 * 1024 * 1024, 1_000))->toBeFalse();
    expect($chunker->shouldChunk(15 * 1024 * 1024, 500, 10 * 1024 * 1024, 1_000))->toBeTrue();
    expect($chunker->shouldChunk(5 * 1024 * 1024, 1_500, 10 * 1024 * 1024, 1_000))->toBeTrue();
});

test('buildPkRangeChunks generates valid partition queries for integer primary key', function () {
    $chunker = new TableChunker;

    // Table with 150,000 rows, IDs 1 to 150,000, chunk size 50,000 -> 3 chunks
    $chunks = $chunker->buildPkRangeChunks(
        table: 'orders',
        pkColumn: 'id',
        minId: 1,
        maxId: 150_000,
        totalRows: 150_000,
        chunkSize: 50_000
    );

    expect($chunks)->toHaveCount(3);

    // Chunk 1: first part uses < endId
    expect($chunks[0]['table'])->toBe('orders');
    expect($chunks[0]['label'])->toBe('orders [part 1/3]');
    expect($chunks[0]['chunked'])->toBeTrue();
    expect($chunks[0]['strategy'])->toBe('pk_range');
    expect($chunks[0]['part'])->toBe(1);
    expect($chunks[0]['total_parts'])->toBe(3);
    expect($chunks[0]['where'])->toBe('`id` < 50001');

    // Chunk 2: middle part uses >= startId AND < endId
    expect($chunks[1]['label'])->toBe('orders [part 2/3]');
    expect($chunks[1]['where'])->toBe('`id` >= 50001 AND `id` < 100001');

    // Chunk 3: last part uses >= startId
    expect($chunks[2]['label'])->toBe('orders [part 3/3]');
    expect($chunks[2]['where'])->toBe('`id` >= 100001');
});

test('buildPkRangeChunks returns single unchunked item if rows <= chunkSize', function () {
    $chunker = new TableChunker;

    $chunks = $chunker->buildPkRangeChunks(
        table: 'small_orders',
        pkColumn: 'id',
        minId: 1,
        maxId: 10_000,
        totalRows: 10_000,
        chunkSize: 50_000
    );

    expect($chunks)->toHaveCount(1);
    expect($chunks[0]['chunked'])->toBeFalse();
    expect($chunks[0]['where'])->toBeNull();
    expect($chunks[0]['label'])->toBe('small_orders');
});

test('buildPkRangeChunks handles custom primary key column name', function () {
    $chunker = new TableChunker;

    $chunks = $chunker->buildPkRangeChunks(
        table: 'audit_logs',
        pkColumn: 'log_id',
        minId: 100,
        maxId: 200_100,
        totalRows: 200_000,
        chunkSize: 50_000
    );

    expect($chunks)->toHaveCount(4);
    expect($chunks[0]['where'])->toContain('`log_id` <');
    expect($chunks[3]['where'])->toContain('`log_id` >=');
});

test('buildLimitOffsetChunks generates sequential limit-offset parts', function () {
    $chunker = new TableChunker;

    // Table with 120,000 rows without integer PK, chunk size 50,000 -> 3 chunks (50k, 50k, 20k)
    $chunks = $chunker->buildLimitOffsetChunks(
        table: 'session_tokens',
        totalRows: 120_000,
        chunkSize: 50_000
    );

    expect($chunks)->toHaveCount(3);

    expect($chunks[0]['label'])->toBe('session_tokens [part 1/3]');
    expect($chunks[0]['strategy'])->toBe('limit_offset');
    expect($chunks[0]['where'])->toBe('1 LIMIT 50000 OFFSET 0');

    expect($chunks[1]['label'])->toBe('session_tokens [part 2/3]');
    expect($chunks[1]['where'])->toBe('1 LIMIT 50000 OFFSET 50000');

    expect($chunks[2]['label'])->toBe('session_tokens [part 3/3]');
    expect($chunks[2]['where'])->toBe('1 LIMIT 50000 OFFSET 100000');
});

test('buildLimitOffsetChunks includes order column when provided', function () {
    $chunker = new TableChunker;

    // Table with UUID primary key
    $chunks = $chunker->buildLimitOffsetChunks(
        table: 'accounts',
        totalRows: 150_000,
        chunkSize: 50_000,
        orderColumn: 'uuid'
    );

    expect($chunks)->toHaveCount(3);
    expect($chunks[0]['where'])->toBe('1 ORDER BY `uuid` LIMIT 50000 OFFSET 0');
    expect($chunks[1]['where'])->toBe('1 ORDER BY `uuid` LIMIT 50000 OFFSET 50000');
    expect($chunks[2]['where'])->toBe('1 ORDER BY `uuid` LIMIT 50000 OFFSET 100000');
});

test('buildLimitOffsetChunks returns single item if totalRows <= chunkSize', function () {
    $chunker = new TableChunker;

    $chunks = $chunker->buildLimitOffsetChunks(
        table: 'small_table',
        totalRows: 5_000,
        chunkSize: 50_000
    );

    expect($chunks)->toHaveCount(1);
    expect($chunks[0]['chunked'])->toBeFalse();
    expect($chunks[0]['where'])->toBeNull();
});
