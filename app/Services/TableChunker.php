<?php

namespace App\Services;

use App\Data\TablePolicy;

class TableChunker
{
    public const DEFAULT_ROW_THRESHOLD = 100_000;

    public const DEFAULT_SIZE_THRESHOLD = 1_073_741_824; // 1 GB (1024 * 1024 * 1024)

    public const DEFAULT_CHUNK_SIZE = 50_000;

    /**
     * Determine whether a table meets the threshold for dynamic auto-chunking.
     * Triggers when table size > 1 GB OR row count > 100,000 rows.
     */
    public function shouldChunk(
        int $dataLength,
        int $rowCount,
        int $sizeThreshold = self::DEFAULT_SIZE_THRESHOLD,
        int $rowThreshold = self::DEFAULT_ROW_THRESHOLD
    ): bool {
        return $dataLength > $sizeThreshold || $rowCount > $rowThreshold;
    }

    /**
     * Build primary key range chunk definitions for an integer primary key.
     *
     * @return array<int, array{table: string, where: string|null, label: string, chunked: bool, strategy: string, part: int, total_parts: int}>
     */
    public function buildPkRangeChunks(
        string $table,
        string $pkColumn,
        int $minId,
        int $maxId,
        int $totalRows,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE
    ): array {
        if ($totalRows <= $chunkSize || $maxId <= $minId) {
            return [
                [
                    'table' => $table,
                    'where' => null,
                    'label' => $table,
                    'chunked' => false,
                    'strategy' => 'none',
                    'part' => 1,
                    'total_parts' => 1,
                ],
            ];
        }

        $numChunks = (int) max(2, ceil($totalRows / $chunkSize));
        $idSpan = $maxId - $minId + 1;
        $step = (int) ceil($idSpan / $numChunks);

        $chunks = [];
        for ($c = 0; $c < $numChunks; $c++) {
            $partNum = $c + 1;
            $startId = $minId + ($c * $step);
            $endId = ($c === $numChunks - 1) ? null : ($startId + $step);

            if ($c === 0) {
                $where = "`{$pkColumn}` < {$endId}";
            } elseif ($endId === null) {
                $where = "`{$pkColumn}` >= {$startId}";
            } else {
                $where = "`{$pkColumn}` >= {$startId} AND `{$pkColumn}` < {$endId}";
            }

            $label = "{$table} [part {$partNum}/{$numChunks}]";

            $chunks[] = [
                'table' => $table,
                'where' => $where,
                'label' => $label,
                'chunked' => true,
                'strategy' => 'pk_range',
                'part' => $partNum,
                'total_parts' => $numChunks,
            ];
        }

        return $chunks;
    }

    /**
     * Build limit-offset chunk definitions for tables without an integer primary key.
     *
     * @return array<int, array{table: string, where: string|null, label: string, chunked: bool, strategy: string, part: int, total_parts: int}>
     */
    public function buildLimitOffsetChunks(
        string $table,
        int $totalRows,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        ?string $orderColumn = null
    ): array {
        if ($totalRows <= $chunkSize) {
            return [
                [
                    'table' => $table,
                    'where' => null,
                    'label' => $table,
                    'chunked' => false,
                    'strategy' => 'none',
                    'part' => 1,
                    'total_parts' => 1,
                ],
            ];
        }

        $numChunks = (int) max(2, ceil($totalRows / $chunkSize));
        $orderClause = $orderColumn ? " ORDER BY `{$orderColumn}`" : '';

        $chunks = [];
        for ($c = 0; $c < $numChunks; $c++) {
            $partNum = $c + 1;
            $offset = $c * $chunkSize;
            $limit = $chunkSize;
            $where = "1{$orderClause} LIMIT {$limit} OFFSET {$offset}";
            $label = "{$table} [part {$partNum}/{$numChunks}]";

            $chunks[] = [
                'table' => $table,
                'where' => $where,
                'label' => $label,
                'chunked' => true,
                'strategy' => 'limit_offset',
                'part' => $partNum,
                'total_parts' => $numChunks,
            ];
        }

        return $chunks;
    }

    /**
     * Execute a SQL query using the mysql CLI binary.
     *
     * @return array<int, string>
     */
    protected function runQuery(string $mysqlBin, array $conn, string $dbName, string $sql): array
    {
        $cmd = escapeshellarg($mysqlBin)
            .' --ssl-mode=DISABLED'
            .' --connect-timeout=10'
            .' --batch --skip-column-names'
            .' -h '.escapeshellarg($conn['hostname'])
            .' -P '.(int) $conn['port']
            .' -u '.escapeshellarg($conn['username'])
            .' --password='.escapeshellarg($conn['password'])
            .' -e '.escapeshellarg($sql)
            .' '.escapeshellarg($dbName)
            .' 2>/dev/null';

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            return [];
        }

        return $output;
    }

    /**
     * Detect primary key column and whether it is integer-based.
     *
     * @return array{column: string, is_integer: bool, type: string}|null
     */
    public function detectPrimaryKey(string $mysqlBin, array $conn, string $dbName, string $table): ?array
    {
        $sql = 'SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS '
            ."WHERE TABLE_SCHEMA = '".addslashes($dbName)."' "
            ."AND TABLE_NAME = '".addslashes($table)."' "
            ."AND COLUMN_KEY = 'PRI' ORDER BY ORDINAL_POSITION";

        $output = $this->runQuery($mysqlBin, $conn, 'information_schema', $sql);

        // Only single-column primary keys can be reliably range-chunked by integer values
        if (count($output) !== 1) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($output[0]));
        $column = $parts[0] ?? '';
        $type = strtolower($parts[1] ?? '');

        if (! $column) {
            return null;
        }

        $intTypes = ['int', 'integer', 'bigint', 'mediumint', 'smallint', 'tinyint'];
        $isInteger = in_array($type, $intTypes, true);

        return [
            'column' => $column,
            'is_integer' => $isInteger,
            'type' => $type,
        ];
    }

    /**
     * Inspect all tables in a schema and return size, row count, and chunking recommendations.
     *
     * @return array<string, array{table: string, data_length: int, index_length: int, total_bytes: int, row_count: int, should_chunk: bool, strategy: string, pk_column: ?string}>
     */
    public function inspectTables(
        string $mysqlBin,
        array $conn,
        string $dbName,
        array $ignoreTables = [],
        int $sizeThreshold = self::DEFAULT_SIZE_THRESHOLD,
        int $rowThreshold = self::DEFAULT_ROW_THRESHOLD,
        array $tablePolicies = []
    ): array {
        $ignoreClause = '';
        if ($ignoreTables) {
            $quoted = implode(',', array_map(fn ($t) => "'".addslashes($t)."'", $ignoreTables));
            $ignoreClause = " AND TABLE_NAME NOT IN ({$quoted})";
        }

        $sql = 'SELECT TABLE_NAME, DATA_LENGTH, INDEX_LENGTH, TABLE_ROWS FROM information_schema.TABLES'
            ." WHERE TABLE_SCHEMA='".addslashes($dbName)."'"
            ." AND TABLE_TYPE='BASE TABLE'"
            .$ignoreClause
            .' ORDER BY DATA_LENGTH DESC, TABLE_NAME';

        $output = $this->runQuery($mysqlBin, $conn, 'information_schema', $sql);
        $results = [];

        foreach ($output as $line) {
            $parts = preg_split('/\s+/', trim($line));
            $table = $parts[0] ?? '';
            $dataLength = (int) ($parts[1] ?? 0);
            $indexLength = (int) ($parts[2] ?? 0);
            $tableRows = (int) ($parts[3] ?? 0);

            if (! $table) {
                continue;
            }

            $policyObj = $tablePolicies[$table] ?? null;
            $forceChunk = false;
            $tableSizeThreshold = $sizeThreshold;
            $tableRowThreshold = $rowThreshold;

            if ($policyObj instanceof TablePolicy) {
                if ($policyObj->isIgnore() || $policyObj->isSchemaOnly() || ($policyObj->isTransient() && ! $policyObj->migrateData)) {
                    continue;
                }
                if ($policyObj->isChunked()) {
                    $forceChunk = true;
                }
                if ($policyObj->thresholdBytes) {
                    $tableSizeThreshold = $policyObj->thresholdBytes;
                }
                if ($policyObj->thresholdRows) {
                    $tableRowThreshold = $policyObj->thresholdRows;
                }
            } elseif (is_string($policyObj) && $policyObj === 'chunked') {
                $forceChunk = true;
            }

            $totalBytes = $dataLength + $indexLength;
            $shouldChunk = $forceChunk || $this->shouldChunk($dataLength, $tableRows, $tableSizeThreshold, $tableRowThreshold);

            $strategy = 'none';
            $pkCol = null;

            if ($shouldChunk) {
                $pkInfo = $this->detectPrimaryKey($mysqlBin, $conn, $dbName, $table);
                if ($pkInfo && $pkInfo['is_integer']) {
                    $strategy = 'pk_range';
                    $pkCol = $pkInfo['column'];
                } else {
                    $strategy = 'limit_offset';
                    $pkCol = $pkInfo['column'] ?? null;
                }
            }

            $results[$table] = [
                'table' => $table,
                'data_length' => $dataLength,
                'index_length' => $indexLength,
                'total_bytes' => $totalBytes,
                'row_count' => $tableRows,
                'should_chunk' => $shouldChunk,
                'strategy' => $strategy,
                'pk_column' => $pkCol,
            ];
        }

        return $results;
    }

    /**
     * Generate partitions for a single table based on size and row count metrics.
     *
     * @return array<int, array{table: string, where: string|null, label: string, chunked: bool, strategy: string, part: int, total_parts: int}>
     */
    public function partitionTable(
        string $mysqlBin,
        array $conn,
        string $dbName,
        string $table,
        int $dataLength,
        int $rowCount,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        int $concurrency = 4,
        int $sizeThreshold = self::DEFAULT_SIZE_THRESHOLD,
        int $rowThreshold = self::DEFAULT_ROW_THRESHOLD
    ): array {
        if (! $this->shouldChunk($dataLength, $rowCount, $sizeThreshold, $rowThreshold)) {
            return [
                [
                    'table' => $table,
                    'where' => null,
                    'label' => $table,
                    'chunked' => false,
                    'strategy' => 'none',
                    'part' => 1,
                    'total_parts' => 1,
                ],
            ];
        }

        $pkInfo = $this->detectPrimaryKey($mysqlBin, $conn, $dbName, $table);

        // Attempt integer PK range chunking
        if ($pkInfo && $pkInfo['is_integer']) {
            $pkCol = $pkInfo['column'];
            $rangeSql = "SELECT MIN(`{$pkCol}`), MAX(`{$pkCol}`), COUNT(*) FROM `{$table}`";
            $rangeOut = $this->runQuery($mysqlBin, $conn, $dbName, $rangeSql);

            if (! empty($rangeOut[0])) {
                $rParts = preg_split('/\s+/', trim($rangeOut[0]));
                $minId = isset($rParts[0]) && is_numeric($rParts[0]) ? (int) $rParts[0] : null;
                $maxId = isset($rParts[1]) && is_numeric($rParts[1]) ? (int) $rParts[1] : null;
                $count = isset($rParts[2]) && is_numeric($rParts[2]) ? (int) $rParts[2] : 0;

                if ($minId !== null && $maxId !== null && $maxId >= $minId && $count > 0) {
                    return $this->buildPkRangeChunks($table, $pkCol, $minId, $maxId, $count, $chunkSize);
                }
            }
        }

        // Fall back to limit-offset chunking
        $countSql = "SELECT COUNT(*) FROM `{$table}`";
        $countOut = $this->runQuery($mysqlBin, $conn, $dbName, $countSql);
        $totalRows = ! empty($countOut[0]) && is_numeric(trim($countOut[0]))
            ? (int) trim($countOut[0])
            : $rowCount;

        $orderCol = $pkInfo['column'] ?? null;

        return $this->buildLimitOffsetChunks($table, $totalRows, $chunkSize, $orderCol);
    }

    /**
     * Retrieve all table partition jobs for a schema (both chunked and unchunked).
     *
     * @return array<int, array{table: string, where: string|null, label: string, chunked: bool, strategy: string, part: int, total_parts: int}>
     */
    public function getTablePartitions(
        string $mysqlBin,
        array $conn,
        string $dbName,
        array $ignoreTables = [],
        int $concurrency = 4,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        int $sizeThreshold = self::DEFAULT_SIZE_THRESHOLD,
        int $rowThreshold = self::DEFAULT_ROW_THRESHOLD,
        array $tablePolicies = []
    ): array {
        $ignoreClause = '';
        if ($ignoreTables) {
            $quoted = implode(',', array_map(fn ($t) => "'".addslashes($t)."'", $ignoreTables));
            $ignoreClause = " AND TABLE_NAME NOT IN ({$quoted})";
        }

        $sql = 'SELECT TABLE_NAME, DATA_LENGTH, INDEX_LENGTH, TABLE_ROWS FROM information_schema.TABLES'
            ." WHERE TABLE_SCHEMA='".addslashes($dbName)."'"
            ." AND TABLE_TYPE='BASE TABLE'"
            .$ignoreClause
            .' ORDER BY DATA_LENGTH DESC, TABLE_NAME';

        $output = $this->runQuery($mysqlBin, $conn, 'information_schema', $sql);
        $items = [];

        foreach ($output as $line) {
            $parts = preg_split('/\s+/', trim($line));
            $table = $parts[0] ?? '';
            $dataLength = (int) ($parts[1] ?? 0);
            $indexLength = (int) ($parts[2] ?? 0);
            $tableRows = (int) ($parts[3] ?? 0);

            if (! $table) {
                continue;
            }

            // Check table policies from manifest
            $policyObj = $tablePolicies[$table] ?? null;
            $tableChunkSize = $chunkSize;
            $tableSizeThreshold = $sizeThreshold;
            $tableRowThreshold = $rowThreshold;
            $forceChunk = false;

            if ($policyObj instanceof TablePolicy) {
                if (! $policyObj->shouldMigrateData()) {
                    continue; // Skip data migration for ignore, schema_only, or non-data transient
                }
                if ($policyObj->isChunked()) {
                    $forceChunk = true;
                }
                if ($policyObj->chunkSize) {
                    $tableChunkSize = $policyObj->chunkSize;
                }
                if ($policyObj->thresholdBytes) {
                    $tableSizeThreshold = $policyObj->thresholdBytes;
                }
                if ($policyObj->thresholdRows) {
                    $tableRowThreshold = $policyObj->thresholdRows;
                }
            } elseif (is_string($policyObj)) {
                if (in_array($policyObj, ['ignore', 'schema_only', 'transient'], true)) {
                    continue;
                }
                if ($policyObj === 'chunked') {
                    $forceChunk = true;
                }
            }

            $totalBytes = $dataLength + $indexLength;
            $tablePartitions = $this->partitionTable(
                $mysqlBin,
                $conn,
                $dbName,
                $table,
                $totalBytes,
                $tableRows,
                $tableChunkSize,
                $concurrency,
                $forceChunk ? 0 : $tableSizeThreshold,
                $forceChunk ? 0 : $tableRowThreshold
            );

            foreach ($tablePartitions as $partition) {
                $items[] = $partition;
            }
        }

        return $items;
    }
}
