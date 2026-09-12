<?php

namespace App\Services;

use App\Data\ConfigAuditItem;
use App\Data\DatabaseAuditItem;
use App\Data\HealthCheckItem;
use App\Data\OrgAuditResult;
use PDO;
use RuntimeException;
use Throwable;

class OrgAuditService
{
    private HealthService $healthService;

    private CrossAppEnvResolver $envResolver;

    /** @var callable|null */
    private $dbQueryExecutor;

    /** @var callable|null */
    private $pdoFactory;

    /** @var array<string, string|null> */
    private array $binaryPaths = [];

    private static array $knownBinaryPaths = [
        '/opt/homebrew/opt/mysql-client/bin',
        '/opt/homebrew/opt/mysql-client@8.0/bin',
        '/usr/local/opt/mysql-client/bin',
        '/usr/local/bin',
        '/usr/bin',
    ];

    public function __construct(
        private CloudApiClient $targetClient,
        private ?CloudApiClient $sourceClient = null,
        private ?MigrationManifest $manifest = null,
        ?HealthService $healthService = null,
        ?CrossAppEnvResolver $envResolver = null,
        ?callable $dbQueryExecutor = null,
        ?callable $pdoFactory = null,
    ) {
        $this->healthService = $healthService ?? new HealthService($this->targetClient);
        $this->envResolver = $envResolver ?? new CrossAppEnvResolver($this->targetClient, strict: false);
        $this->dbQueryExecutor = $dbQueryExecutor;
        $this->pdoFactory = $pdoFactory;
    }

    public function getTargetClient(): CloudApiClient
    {
        return $this->targetClient;
    }

    public function setTargetClient(CloudApiClient $client): self
    {
        $this->targetClient = $client;
        $this->healthService->setClient($client);
        $this->envResolver->setTargetClient($client);

        return $this;
    }

    public function getSourceClient(): ?CloudApiClient
    {
        return $this->sourceClient;
    }

    public function setSourceClient(?CloudApiClient $client): self
    {
        $this->sourceClient = $client;

        return $this;
    }

    public function getManifest(): ?MigrationManifest
    {
        return $this->manifest;
    }

    public function setManifest(?MigrationManifest $manifest): self
    {
        $this->manifest = $manifest;

        return $this;
    }

    public function getHealthService(): HealthService
    {
        return $this->healthService;
    }

    public function setHealthService(HealthService $healthService): self
    {
        $this->healthService = $healthService;

        return $this;
    }

    public function getEnvResolver(): CrossAppEnvResolver
    {
        return $this->envResolver;
    }

    public function setEnvResolver(CrossAppEnvResolver $envResolver): self
    {
        $this->envResolver = $envResolver;

        return $this;
    }

    public function setDbQueryExecutor(?callable $dbQueryExecutor): self
    {
        $this->dbQueryExecutor = $dbQueryExecutor;

        return $this;
    }

    public function setPdoFactory(?callable $pdoFactory): self
    {
        $this->pdoFactory = $pdoFactory;

        return $this;
    }

    /**
     * Run full aggregate audit across health, database, and config.
     *
     * @param  array{skip_health?: bool, skip_db?: bool, skip_config?: bool, timeout?: int}  $options
     */
    public function runAudit(array $options = [], ?callable $onProgress = null): OrgAuditResult
    {
        $startTime = microtime(true);

        $healthItems = [];
        $databaseItems = [];
        $configItems = [];

        // 1. Health checks
        if (empty($options['skip_health'])) {
            $timeout = (int) ($options['timeout'] ?? 10);
            $healthItems = $this->auditHealth($timeout, $onProgress ? fn ($item) => $onProgress('health', $item) : null);
        }

        // 2. Database drift & parity
        if (empty($options['skip_db'])) {
            $databaseItems = $this->auditDatabase($options, $onProgress ? fn ($item) => $onProgress('database', $item) : null);
        }

        // 3. Config & Cross-app URLs
        if (empty($options['skip_config'])) {
            $configItems = $this->auditConfig($options, $onProgress ? fn ($item) => $onProgress('config', $item) : null);
        }

        $duration = microtime(true) - $startTime;

        return new OrgAuditResult(
            healthItems: $healthItems,
            databaseItems: $databaseItems,
            configItems: $configItems,
            durationSeconds: $duration,
            metadata: [
                'has_source_client' => $this->sourceClient !== null,
                'has_manifest' => $this->manifest !== null,
                'options' => $options,
            ],
        );
    }

    /**
     * Audit HTTP health across all applications and environments.
     *
     * @return array<int, HealthCheckItem>
     */
    public function auditHealth(int $timeout = 10, ?callable $onProgress = null): array
    {
        $apps = $this->targetClient->getAll('applications');
        $results = [];

        foreach ($apps as $app) {
            $appName = $app['attributes']['name'] ?? $app['id'];
            $appSlug = $app['attributes']['slug'] ?? $appName;

            try {
                $envs = $this->targetClient->getAll("applications/{$app['id']}/environments");
            } catch (Throwable $e) {
                $item = new HealthCheckItem(
                    appName: $appName,
                    appSlug: $appSlug,
                    envName: 'all',
                    envSlug: 'all',
                    url: '',
                    statusCode: null,
                    status: 'unhealthy',
                    message: "Failed fetching environments: {$e->getMessage()}",
                );
                $results[] = $item;
                if ($onProgress) {
                    $onProgress($item);
                }

                continue;
            }

            foreach ($envs as $env) {
                $item = $this->healthService->checkEnvironment($app, $env, $timeout);
                $results[] = $item;
                if ($onProgress) {
                    $onProgress($item);
                }
            }
        }

        return $results;
    }

    /**
     * Audit database schemas and tables for drift and parity.
     *
     * @return array<int, DatabaseAuditItem>
     */
    public function auditDatabase(array $options = [], ?callable $onProgress = null): array
    {
        try {
            $tgtClusters = $this->targetClient->getAll('databases/clusters');
        } catch (Throwable $e) {
            $item = new DatabaseAuditItem(
                cluster: 'target',
                schema: 'all',
                table: 'all',
                status: 'error',
                message: "Failed fetching target database clusters: {$e->getMessage()}",
            );
            if ($onProgress) {
                $onProgress($item);
            }

            return [$item];
        }

        if (empty($tgtClusters)) {
            $item = new DatabaseAuditItem(
                cluster: 'none',
                schema: 'none',
                table: 'none',
                status: 'warning',
                message: 'No database clusters found in target organization',
            );
            if ($onProgress) {
                $onProgress($item);
            }

            return [$item];
        }

        $srcClusters = [];
        if ($this->sourceClient) {
            try {
                $srcClusters = $this->sourceClient->getAll('databases/clusters');
            } catch (Throwable) {
                $srcClusters = [];
            }
        }

        $srcClusterMap = [];
        foreach ($srcClusters as $sc) {
            $scName = $sc['attributes']['name'] ?? $sc['id'];
            $scConn = $sc['attributes']['connection'] ?? null;
            if (! $scConn) {
                continue;
            }
            try {
                $dbs = $this->sourceClient->getAll("databases/clusters/{$sc['id']}/databases");
                foreach ($dbs as $db) {
                    $sName = $db['attributes']['name'];
                    $srcClusterMap["{$scName}.{$sName}"] = [
                        'cluster' => $scName,
                        'schema' => $sName,
                        'connection' => $scConn,
                    ];
                    $srcClusterMap[$sName] = $srcClusterMap["{$scName}.{$sName}"];
                }
            } catch (Throwable) {
                // Ignore source cluster fetch failure
            }
        }

        $results = [];

        foreach ($tgtClusters as $cluster) {
            $clusterName = $cluster['attributes']['name'] ?? $cluster['id'];
            $clusterConn = $cluster['attributes']['connection'] ?? null;

            if (! $clusterConn) {
                $item = new DatabaseAuditItem(
                    cluster: $clusterName,
                    schema: 'all',
                    table: 'all',
                    status: 'warning',
                    message: "Cluster '{$clusterName}' has no connection details available",
                );
                $results[] = $item;
                if ($onProgress) {
                    $onProgress($item);
                }

                continue;
            }

            try {
                $dbList = $this->targetClient->getAll("databases/clusters/{$cluster['id']}/databases");
            } catch (Throwable $e) {
                $item = new DatabaseAuditItem(
                    cluster: $clusterName,
                    schema: 'all',
                    table: 'all',
                    status: 'error',
                    message: "Failed listing databases for cluster '{$clusterName}': {$e->getMessage()}",
                );
                $results[] = $item;
                if ($onProgress) {
                    $onProgress($item);
                }

                continue;
            }

            foreach ($dbList as $db) {
                $schemaName = $db['attributes']['name'];
                $key = "{$clusterName}.{$schemaName}";

                $srcInfo = $srcClusterMap[$key] ?? ($srcClusterMap[$schemaName] ?? null);

                $items = $srcInfo !== null
                    ? $this->auditSchemaParity($clusterName, $schemaName, $srcInfo['connection'], $clusterConn)
                    : $this->auditTargetSchema($clusterName, $schemaName, $clusterConn);

                foreach ($items as $item) {
                    $results[] = $item;
                    if ($onProgress) {
                        $onProgress($item);
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Audit single schema parity between source and target databases.
     *
     * @return array<int, DatabaseAuditItem>
     */
    private function auditSchemaParity(string $clusterName, string $schemaName, array $srcConn, array $tgtConn): array
    {
        try {
            $srcTables = $this->queryTables($srcConn, $schemaName);
            $tgtTables = $this->queryTables($tgtConn, $schemaName);
        } catch (Throwable $e) {
            return [
                new DatabaseAuditItem(
                    cluster: $clusterName,
                    schema: $schemaName,
                    table: 'all',
                    status: 'error',
                    message: "Database connection failed: {$e->getMessage()}",
                ),
            ];
        }

        $allTables = array_unique(array_merge($srcTables, $tgtTables));
        sort($allTables);

        $items = [];

        foreach ($allTables as $table) {
            $inSrc = in_array($table, $srcTables, true);
            $inTgt = in_array($table, $tgtTables, true);

            $policy = $this->manifest ? $this->manifest->getTablePolicy($table, $schemaName, $clusterName) : null;
            $policyName = $policy ? $policy->policy : 'full';

            if (! $inSrc) {
                $tgtCount = $this->countRows($tgtConn, $schemaName, $table);
                $items[] = new DatabaseAuditItem(
                    cluster: $clusterName,
                    schema: $schemaName,
                    table: $table,
                    sourceCount: null,
                    targetCount: $tgtCount,
                    policy: $policyName,
                    status: 'warning',
                    message: 'Table only present in target database',
                );

                continue;
            }

            if (! $inTgt) {
                $isIgnored = $policy ? $policy->isIgnore() : $this->isPolicySkipped($schemaName, $table);
                if ($isIgnored) {
                    $items[] = new DatabaseAuditItem(
                        cluster: $clusterName,
                        schema: $schemaName,
                        table: $table,
                        sourceCount: null,
                        targetCount: null,
                        policy: 'ignore',
                        status: 'ignored',
                        message: 'Table ignored per migration policy',
                    );
                } else {
                    $srcCount = $this->countRows($srcConn, $schemaName, $table);
                    $items[] = new DatabaseAuditItem(
                        cluster: $clusterName,
                        schema: $schemaName,
                        table: $table,
                        sourceCount: $srcCount,
                        targetCount: null,
                        policy: $policyName,
                        status: 'drift',
                        message: 'Table missing in target database',
                    );
                }

                continue;
            }

            $srcCount = $this->countRows($srcConn, $schemaName, $table);
            $tgtCount = $this->countRows($tgtConn, $schemaName, $table);

            if ($policy && $policy->isTransient() || $this->isTransientTable($table)) {
                $items[] = new DatabaseAuditItem(
                    cluster: $clusterName,
                    schema: $schemaName,
                    table: $table,
                    sourceCount: $srcCount,
                    targetCount: $tgtCount,
                    policy: 'transient',
                    status: 'transient',
                    message: 'Transient table (expected divergence)',
                );

                continue;
            }

            if ($policy && $policy->isSchemaOnly()) {
                $items[] = new DatabaseAuditItem(
                    cluster: $clusterName,
                    schema: $schemaName,
                    table: $table,
                    sourceCount: $srcCount,
                    targetCount: $tgtCount,
                    policy: 'schema_only',
                    status: 'schema_only',
                    message: 'Schema-only table (data excluded)',
                );

                continue;
            }

            if ($policy && $policy->isIgnore() || $this->isPolicySkipped($schemaName, $table)) {
                $items[] = new DatabaseAuditItem(
                    cluster: $clusterName,
                    schema: $schemaName,
                    table: $table,
                    sourceCount: $srcCount,
                    targetCount: $tgtCount,
                    policy: 'ignore',
                    status: 'ignored',
                    message: 'Table skipped per migration policy',
                );

                continue;
            }

            // Standard comparison
            if ($tgtCount === $srcCount) {
                $items[] = new DatabaseAuditItem(
                    cluster: $clusterName,
                    schema: $schemaName,
                    table: $table,
                    sourceCount: $srcCount,
                    targetCount: $tgtCount,
                    policy: $policyName,
                    status: 'matched',
                    message: 'Row counts match exactly',
                );
            } elseif ($srcCount > 0 && $tgtCount === 0) {
                $items[] = new DatabaseAuditItem(
                    cluster: $clusterName,
                    schema: $schemaName,
                    table: $table,
                    sourceCount: $srcCount,
                    targetCount: $tgtCount,
                    policy: $policyName,
                    status: 'drift',
                    message: 'Target table is empty while source has data',
                );
            } elseif ($srcCount > 0 && $tgtCount >= $srcCount * 0.95) {
                $items[] = new DatabaseAuditItem(
                    cluster: $clusterName,
                    schema: $schemaName,
                    table: $table,
                    sourceCount: $srcCount,
                    targetCount: $tgtCount,
                    policy: $policyName,
                    status: 'matched',
                    message: sprintf('≥95%% match (%d / %d)', $tgtCount, $srcCount),
                );
            } else {
                $items[] = new DatabaseAuditItem(
                    cluster: $clusterName,
                    schema: $schemaName,
                    table: $table,
                    sourceCount: $srcCount,
                    targetCount: $tgtCount,
                    policy: $policyName,
                    status: 'drift',
                    message: sprintf('Row count drift (%d vs %d)', $srcCount, $tgtCount),
                );
            }
        }

        return $items;
    }

    /**
     * Audit single schema in target database when source is not available.
     *
     * @return array<int, DatabaseAuditItem>
     */
    private function auditTargetSchema(string $clusterName, string $schemaName, array $tgtConn): array
    {
        try {
            $tgtTables = $this->queryTables($tgtConn, $schemaName);
        } catch (Throwable $e) {
            return [
                new DatabaseAuditItem(
                    cluster: $clusterName,
                    schema: $schemaName,
                    table: 'all',
                    status: 'error',
                    message: "Database connection failed: {$e->getMessage()}",
                ),
            ];
        }

        $items = [];
        $checkedTables = [];

        // Check tables declared in manifest for this schema
        if ($this->manifest) {
            $manifestPolicies = $this->manifest->getTablePoliciesForSchema($schemaName, $clusterName);
            foreach ($manifestPolicies as $table => $policy) {
                $checkedTables[] = $table;
                $inTgt = in_array($table, $tgtTables, true);

                if (! $inTgt) {
                    if ($policy->isIgnore()) {
                        $items[] = new DatabaseAuditItem(
                            cluster: $clusterName,
                            schema: $schemaName,
                            table: $table,
                            policy: 'ignore',
                            status: 'ignored',
                            message: 'Excluded by manifest policy',
                        );
                    } else {
                        $items[] = new DatabaseAuditItem(
                            cluster: $clusterName,
                            schema: $schemaName,
                            table: $table,
                            policy: $policy->policy,
                            status: 'drift',
                            message: 'Manifest expected table missing in target database',
                        );
                    }

                    continue;
                }

                $tgtCount = $this->countRows($tgtConn, $schemaName, $table);

                if ($policy->isTransient()) {
                    $items[] = new DatabaseAuditItem(
                        cluster: $clusterName,
                        schema: $schemaName,
                        table: $table,
                        targetCount: $tgtCount,
                        policy: 'transient',
                        status: 'transient',
                        message: 'Transient table active',
                    );
                } elseif ($policy->isSchemaOnly()) {
                    $items[] = new DatabaseAuditItem(
                        cluster: $clusterName,
                        schema: $schemaName,
                        table: $table,
                        targetCount: $tgtCount,
                        policy: 'schema_only',
                        status: 'schema_only',
                        message: 'Schema-only table present',
                    );
                } elseif ($policy->isIgnore()) {
                    $items[] = new DatabaseAuditItem(
                        cluster: $clusterName,
                        schema: $schemaName,
                        table: $table,
                        targetCount: $tgtCount,
                        policy: 'ignore',
                        status: 'ignored',
                        message: 'Ignored table present',
                    );
                } else {
                    $items[] = new DatabaseAuditItem(
                        cluster: $clusterName,
                        schema: $schemaName,
                        table: $table,
                        targetCount: $tgtCount,
                        policy: $policy->policy,
                        status: 'matched',
                        message: "Table healthy ({$tgtCount} rows)",
                    );
                }
            }
        }

        // Check remaining target tables
        foreach ($tgtTables as $table) {
            if (in_array($table, $checkedTables, true)) {
                continue;
            }

            $tgtCount = $this->countRows($tgtConn, $schemaName, $table);
            $items[] = new DatabaseAuditItem(
                cluster: $clusterName,
                schema: $schemaName,
                table: $table,
                targetCount: $tgtCount,
                policy: 'full',
                status: 'matched',
                message: "Table present ({$tgtCount} rows)",
            );
        }

        return $items;
    }

    /**
     * Audit environment variables and cross-app URLs for consistency and drift.
     *
     * @return array<int, ConfigAuditItem>
     */
    public function auditConfig(array $options = [], ?callable $onProgress = null): array
    {
        $apps = $this->targetClient->getAll('applications');
        $results = [];

        // Register apps and environments with CrossAppEnvResolver
        $appsBySlug = [];
        foreach ($apps as $app) {
            $slug = $app['attributes']['slug'] ?? ($app['attributes']['name'] ?? $app['id']);
            try {
                $envs = $this->targetClient->getAll("applications/{$app['id']}/environments");
            } catch (Throwable) {
                $envs = [];
            }
            $this->envResolver->registerApp($slug, $app, $envs);
            $appsBySlug[$slug] = [
                'app' => $app,
                'environments' => $envs,
            ];
        }

        // 1. Audit manifest-declared env_resolvers
        if ($this->manifest) {
            $resolvers = $this->manifest->getAllEnvResolvers();
            foreach ($resolvers as $appKey => $mapping) {
                if (! is_array($mapping)) {
                    continue;
                }

                $targetAppRecord = $appsBySlug[$appKey] ?? null;
                if (! $targetAppRecord) {
                    $item = new ConfigAuditItem(
                        appName: $appKey,
                        envName: 'all',
                        variableKey: 'application',
                        expectedValue: $appKey,
                        actualValue: null,
                        status: 'drift',
                        message: "Configured app '{$appKey}' not found in target organization",
                    );
                    $results[] = $item;
                    if ($onProgress) {
                        $onProgress($item);
                    }

                    continue;
                }

                $envs = $targetAppRecord['environments'];
                foreach ($envs as $env) {
                    $envName = $env['attributes']['name'] ?? $env['id'];
                    $actualVars = $this->getEnvironmentVariables($targetAppRecord['app']['id'], $env);

                    foreach ($mapping as $varKey => $template) {
                        $expectedVal = $this->envResolver->resolve($template, [
                            'app' => $appKey,
                            'env' => $envName,
                        ]);

                        $actualVal = $actualVars[$varKey] ?? null;

                        if ($actualVal === null) {
                            $item = new ConfigAuditItem(
                                appName: $appKey,
                                envName: $envName,
                                variableKey: $varKey,
                                expectedValue: $expectedVal,
                                actualValue: null,
                                status: 'missing',
                                message: "Missing required environment variable '{$varKey}'",
                            );
                        } elseif (rtrim($actualVal, '/') === rtrim($expectedVal, '/')) {
                            $item = new ConfigAuditItem(
                                appName: $appKey,
                                envName: $envName,
                                variableKey: $varKey,
                                expectedValue: $expectedVal,
                                actualValue: $actualVal,
                                status: 'matched',
                                message: 'Configuration matches expected cross-app URL',
                            );
                        } else {
                            $item = new ConfigAuditItem(
                                appName: $appKey,
                                envName: $envName,
                                variableKey: $varKey,
                                expectedValue: $expectedVal,
                                actualValue: $actualVal,
                                status: 'drift',
                                message: "Value drift: expected '{$expectedVal}', actual '{$actualVal}'",
                            );
                        }

                        $results[] = $item;
                        if ($onProgress) {
                            $onProgress($item);
                        }
                    }
                }
            }
        }

        // 2. Scan all environment variables across all target apps for unresolved placeholders
        foreach ($appsBySlug as $appSlug => $appRecord) {
            foreach ($appRecord['environments'] as $env) {
                $envName = $env['attributes']['name'] ?? $env['id'];
                $vars = $this->getEnvironmentVariables($appRecord['app']['id'], $env);

                foreach ($vars as $key => $val) {
                    if (! is_string($val)) {
                        continue;
                    }

                    // Check for unparsed placeholder like {{apps...}}
                    if (preg_match('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', $val)) {
                        $item = new ConfigAuditItem(
                            appName: $appSlug,
                            envName: $envName,
                            variableKey: $key,
                            expectedValue: 'Resolved value',
                            actualValue: $val,
                            status: 'drift',
                            message: "Unresolved template placeholder detected in variable '{$key}'",
                        );
                        $results[] = $item;
                        if ($onProgress) {
                            $onProgress($item);
                        }
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Get environment variables from environment attributes or API.
     *
     * @return array<string, string>
     */
    private function getEnvironmentVariables(string $appId, array $env): array
    {
        $rawVars = $env['attributes']['environment_variables'] ?? null;
        if ($rawVars === null || empty($rawVars)) {
            try {
                $resp = $this->targetClient->getAll("environments/{$env['id']}/variables");
                $rawVars = $resp['data'] ?? $resp;
            } catch (Throwable) {
                $rawVars = [];
            }
        }

        $map = [];
        foreach ($rawVars as $k => $v) {
            if (is_array($v) && isset($v['key'])) {
                $map[$v['key']] = (string) ($v['value'] ?? '');
            } elseif (is_string($k)) {
                $map[$k] = (string) $v;
            }
        }

        return $map;
    }

    /**
     * Query table list from a database connection.
     *
     * @return string[]
     */
    public function queryTables(array $conn, string $schema): array
    {
        if ($this->dbQueryExecutor) {
            return ($this->dbQueryExecutor)($conn, $schema, 'tables');
        }

        if ($this->pdoFactory) {
            $pdo = ($this->pdoFactory)($conn, $schema);
            $stmt = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '".addslashes($schema)."' AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");

            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }

        try {
            $pdo = $this->createPdo($conn, $schema);
            $stmt = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '".addslashes($schema)."' AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");

            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable) {
            $mysql = $this->findBinary('mysql');
            if ($mysql && ! empty($conn['hostname'])) {
                $sql = "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='".addslashes($schema)."' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME";

                return $this->queryViaCli($mysql, $conn, $sql);
            }
            throw new RuntimeException("Cannot connect to database {$schema}");
        }
    }

    /**
     * Count rows in a table.
     */
    public function countRows(array $conn, string $schema, string $table): int
    {
        if ($this->dbQueryExecutor) {
            return (int) ($this->dbQueryExecutor)($conn, $schema, "count:{$table}");
        }

        if ($this->pdoFactory) {
            $pdo = ($this->pdoFactory)($conn, $schema);
            $stmt = $pdo->query('SELECT COUNT(*) FROM `'.addslashes($schema).'`.`'.addslashes($table).'`');

            return (int) $stmt->fetchColumn();
        }

        try {
            $pdo = $this->createPdo($conn, $schema);
            $stmt = $pdo->query('SELECT COUNT(*) FROM `'.addslashes($schema).'`.`'.addslashes($table).'`');

            return (int) $stmt->fetchColumn();
        } catch (Throwable) {
            $mysql = $this->findBinary('mysql');
            if ($mysql && ! empty($conn['hostname'])) {
                $out = $this->queryViaCli($mysql, $conn, 'SELECT COUNT(*) FROM `'.addslashes($schema).'`.`'.addslashes($table).'`');

                return (int) trim($out[0] ?? '0');
            }

            return 0;
        }
    }

    /**
     * Create standard PDO connection.
     */
    private function createPdo(array $conn, string $database): PDO
    {
        $host = $conn['hostname'] ?? '127.0.0.1';
        $port = (int) ($conn['port'] ?? 3306);
        $user = $conn['username'] ?? '';
        $pass = $conn['password'] ?? '';
        $type = strtolower($conn['type'] ?? 'mysql');

        if (str_contains($type, 'pgsql') || str_contains($type, 'postgres')) {
            $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ];
        } else {
            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ];
        }

        return new PDO($dsn, $user, $pass, $options);
    }

    private function queryViaCli(string $mysql, array $conn, string $sql): array
    {
        $cmd = escapeshellarg($mysql)
            .' --ssl-mode=DISABLED --batch --skip-column-names'
            .' -h '.escapeshellarg($conn['hostname'])
            .' -P '.(int) ($conn['port'] ?? 3306)
            .' -u '.escapeshellarg($conn['username'])
            .' --password='.escapeshellarg($conn['password'])
            .' -e '.escapeshellarg($sql)
            .' 2>/dev/null';

        exec($cmd, $out);

        return array_values(array_filter(array_map('trim', $out)));
    }

    private function findBinary(string $name): ?string
    {
        if (array_key_exists($name, $this->binaryPaths)) {
            return $this->binaryPaths[$name];
        }

        exec('which '.escapeshellarg($name).' 2>/dev/null', $out, $rc);
        if ($rc === 0 && ! empty($out[0])) {
            return $this->binaryPaths[$name] = trim($out[0]);
        }

        foreach (self::$knownBinaryPaths as $dir) {
            $path = $dir.'/'.$name;
            if (is_executable($path)) {
                return $this->binaryPaths[$name] = $path;
            }
        }

        return $this->binaryPaths[$name] = null;
    }

    private function isTransientTable(string $table): bool
    {
        $transient = ['jobs', 'cache', 'cache_locks', 'sessions', 'job_batches'];

        return in_array($table, $transient, true);
    }

    private function isPolicySkipped(string $schema, string $table): bool
    {
        $policySkipped = [
            'nerd.links',
            'dojo.nerd_urls',
            'main.episodes',
        ];

        return in_array("{$schema}.{$table}", $policySkipped, true);
    }
}
