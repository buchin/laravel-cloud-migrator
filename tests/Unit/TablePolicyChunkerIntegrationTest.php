<?php

use App\Data\TablePolicy;
use App\Services\TableChunker;

// Subclass TableChunker to mock MySQL command executions without live database
class MockTableChunker extends TableChunker
{
    /** @var array<string, array<int, string>> */
    public array $mockQueries = [];

    protected function runQuery(string $mysqlBin, array $conn, string $dbName, string $sql): array
    {
        foreach ($this->mockQueries as $pattern => $returnLines) {
            if (str_contains($sql, $pattern)) {
                return $returnLines;
            }
        }

        return [];
    }
}

test('getTablePartitions excludes schema_only, ignore, and transient tables', function () {
    $chunker = new MockTableChunker;

    // Mock information_schema.TABLES
    $chunker->mockQueries['information_schema.TABLES'] = [
        "telescope_entries\t1000000\t500000\t50000",
        "legacy_data\t500000\t100000\t20000",
        "sessions\t2000000\t100000\t80000",
        "users\t100000\t50000\t1000",
    ];

    $policies = [
        'telescope_entries' => new TablePolicy(TablePolicy::SCHEMA_ONLY),
        'legacy_data' => new TablePolicy(TablePolicy::IGNORE),
        'sessions' => new TablePolicy(TablePolicy::TRANSIENT),
    ];

    $partitions = $chunker->getTablePartitions(
        '/usr/bin/mysql',
        ['hostname' => 'localhost', 'port' => 3306, 'username' => 'root', 'password' => ''],
        'main',
        tablePolicies: $policies
    );

    // Only 'users' should be in the returned partitions
    $tables = array_unique(array_column($partitions, 'table'));
    expect($tables)->toBe(['users']);
});

test('getTablePartitions forces chunking and applies custom chunkSize for chunked policy', function () {
    $chunker = new MockTableChunker;

    // Small table: 5,000 rows (ordinarily wouldn't chunk because < 100k rows and < 1 GB)
    $chunker->mockQueries['information_schema.TABLES'] = [
        "activity_logs\t500000\t100000\t5000",
    ];

    // Mock primary key check
    $chunker->mockQueries["COLUMN_KEY = 'PRI'"] = [
        "id\tbigint",
    ];

    // Mock MIN/MAX/COUNT range check
    $chunker->mockQueries['MIN(`id`), MAX(`id`), COUNT(*)'] = [
        "1\t5000\t5000",
    ];

    $policies = [
        'activity_logs' => new TablePolicy(
            policy: TablePolicy::CHUNKED,
            chunkSize: 1000 // custom 1,000 row chunks
        ),
    ];

    $partitions = $chunker->getTablePartitions(
        '/usr/bin/mysql',
        ['hostname' => 'localhost', 'port' => 3306, 'username' => 'root', 'password' => ''],
        'main',
        tablePolicies: $policies
    );

    expect(count($partitions))->toBe(5); // 5000 / 1000 = 5 chunks
    expect($partitions[0]['chunked'])->toBeTrue();
    expect($partitions[0]['strategy'])->toBe('pk_range');
    expect($partitions[0]['where'])->toContain('`id` < 1001');
    expect($partitions[4]['where'])->toContain('`id` >= 4001');
});

test('inspectTables with tablePolicies forces should_chunk for chunked tables and excludes ignored', function () {
    $chunker = new MockTableChunker;

    $chunker->mockQueries['information_schema.TABLES'] = [
        "custom_reports\t200000\t50000\t2000",
        "ignored_tbl\t100000\t20000\t1000",
    ];

    $chunker->mockQueries["COLUMN_KEY = 'PRI'"] = [
        "id\tbigint",
    ];

    $policies = [
        'custom_reports' => new TablePolicy(TablePolicy::CHUNKED, chunkSize: 500),
        'ignored_tbl' => new TablePolicy(TablePolicy::IGNORE),
    ];

    $results = $chunker->inspectTables(
        '/usr/bin/mysql',
        ['hostname' => 'localhost', 'port' => 3306, 'username' => 'root', 'password' => ''],
        'main',
        tablePolicies: $policies
    );

    expect(isset($results['custom_reports']))->toBeTrue();
    expect($results['custom_reports']['should_chunk'])->toBeTrue();
    expect($results['custom_reports']['strategy'])->toBe('pk_range');

    // ignored_tbl should be excluded
    expect(isset($results['ignored_tbl']))->toBeFalse();
});
