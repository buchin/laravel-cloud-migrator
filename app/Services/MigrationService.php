<?php

namespace App\Services;

use App\Data\ApplicationData;
use App\Data\EnvironmentData;
use App\Data\MigrationPlan;
use App\Data\TablePolicy;

class MigrationService
{
    private array $warnings = [];

    private ?string $lastCreatedAppId = null;

    private ?string $lastCreatedClusterId = null;

    private array $envIdMap = [];

    /** @var array<string, string> targetEnvId → envName */
    private array $envNameMap = [];

    /** @var array<string, array> sourceEnvId → db migration info */
    private array $dbDataMap = [];

    /** @var array<string, string> sourceClusterId → targetClusterId (shared across apps) */
    private array $clusterRegistry = [];

    /** @var array<string, string> sourceCacheId → targetCacheId (shared across apps) */
    private array $cacheRegistry = [];

    /** @var array<string, string|null> binary name → resolved full path */
    private array $binaryPaths = [];

    private static array $knownPaths = [
        '/opt/homebrew/opt/mysql-client/bin',
        '/opt/homebrew/opt/mysql-client@8.0/bin',
        '/usr/local/opt/mysql-client/bin',
        '/usr/local/bin',
        '/usr/bin',
    ];

    private function findBinary(string $name): ?string
    {
        if (array_key_exists($name, $this->binaryPaths)) {
            return $this->binaryPaths[$name];
        }

        // Try which first (works if already in PATH)
        exec('which '.escapeshellarg($name).' 2>/dev/null', $out, $rc);
        if ($rc === 0 && ! empty($out[0])) {
            return $this->binaryPaths[$name] = trim($out[0]);
        }

        // Fall back to probing known locations
        foreach (self::$knownPaths as $dir) {
            $path = $dir.'/'.$name;
            if (is_executable($path)) {
                return $this->binaryPaths[$name] = $path;
            }
        }

        return $this->binaryPaths[$name] = null;
    }

    private TableChunker $tableChunker;

    private CrossAppEnvResolver $envResolver;

    private ?MigrationManifest $manifest = null;

    public function __construct(
        private readonly CloudApiClient $source,
        private readonly CloudApiClient $target,
        ?TableChunker $tableChunker = null,
        ?CrossAppEnvResolver $envResolver = null,
        ?MigrationManifest $manifest = null,
    ) {
        $this->tableChunker = $tableChunker ?? new TableChunker;
        $this->envResolver = $envResolver ?? new CrossAppEnvResolver($this->target);
        $this->manifest = $manifest;
    }

    public function getTableChunker(): TableChunker
    {
        return $this->tableChunker;
    }

    public function setTableChunker(TableChunker $tableChunker): void
    {
        $this->tableChunker = $tableChunker;
    }

    public function getEnvResolver(): CrossAppEnvResolver
    {
        return $this->envResolver;
    }

    public function setEnvResolver(CrossAppEnvResolver $envResolver): void
    {
        $this->envResolver = $envResolver;
    }

    public function getManifest(): ?MigrationManifest
    {
        return $this->manifest;
    }

    public function setManifest(?MigrationManifest $manifest): void
    {
        $this->manifest = $manifest;
    }

    public function getAntiDeadlockSqlPrefix(): string
    {
        return "SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;\n"
            ."SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;\n";
    }

    public function getAntiDeadlockSqlSuffix(): string
    {
        return "\nSET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;\n"
            ."SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;\n";
    }

    public function buildMysqldumpArgs(
        array $conn,
        string $db,
        ?string $table = null,
        ?string $where = null,
        bool $schemaOnly = false,
        ?string $optFile = null
    ): array {
        $args = [];
        if ($optFile) {
            $args[] = '--defaults-extra-file='.$optFile;
        }

        // Permanent anti-deadlock & streaming flags
        $args[] = '--single-transaction';
        $args[] = '--quick';
        $args[] = '--compress';
        $args[] = '--skip-add-locks';
        $args[] = '--no-tablespaces';
        $args[] = '--set-gtid-purged=OFF';
        $args[] = '--max-allowed-packet=64M';
        $args[] = '--ssl-mode=DISABLED';
        $args[] = '--compression-algorithms=zlib,uncompressed';

        if ($schemaOnly) {
            $args[] = '--no-data';
            $args[] = '--add-drop-table';
        } else {
            $args[] = '--no-create-info';
        }

        $args[] = '-h';
        $args[] = $conn['hostname'];
        $args[] = '-P';
        $args[] = (string) (int) $conn['port'];
        $args[] = '-u';
        $args[] = $conn['username'];
        $args[] = '--password='.$conn['password'];

        if ($where !== null && $where !== '') {
            $args[] = '--where='.$where;
        }

        $args[] = $db;

        if ($table !== null && $table !== '') {
            $args[] = $table;
        }

        return $args;
    }

    public function buildMysqlRestoreArgs(
        array $conn,
        string $db,
        bool $force = true
    ): array {
        $args = [];
        if ($force) {
            $args[] = '--force';
        }

        $args[] = '--max-allowed-packet=64M';
        $args[] = '--ssl-mode=DISABLED';
        $args[] = '--compress';
        $args[] = '--compression-algorithms=zlib,uncompressed';
        $args[] = '--init-command=SET SESSION foreign_key_checks=0, unique_checks=0, wait_timeout=28800, net_read_timeout=3600, net_write_timeout=3600';

        $args[] = '-h';
        $args[] = $conn['hostname'];
        $args[] = '-P';
        $args[] = (string) (int) $conn['port'];
        $args[] = '-u';
        $args[] = $conn['username'];
        $args[] = '--password='.$conn['password'];
        $args[] = $db;

        return $args;
    }

    public function buildMysqldumpCommand(
        string $dumpBin,
        array $conn,
        string $db,
        ?string $table = null,
        ?string $where = null,
        bool $schemaOnly = false,
        ?string $optFile = null
    ): string {
        $args = $this->buildMysqldumpArgs($conn, $db, $table, $where, $schemaOnly, $optFile);
        $escaped = array_map(escapeshellarg(...), $args);

        return escapeshellarg($dumpBin).' '.implode(' ', $escaped);
    }

    public function buildMysqlRestoreCommand(
        string $importBin,
        array $conn,
        string $db,
        bool $force = true
    ): string {
        $args = $this->buildMysqlRestoreArgs($conn, $db, $force);
        $escaped = array_map(escapeshellarg(...), $args);

        return escapeshellarg($importBin).' '.implode(' ', $escaped);
    }

    public function getLastCreatedAppId(): ?string
    {
        return $this->lastCreatedAppId;
    }

    public function setLastCreatedAppId(string $id): void
    {
        $this->lastCreatedAppId = $id;
    }

    public function setEnvIdMap(array $map): void
    {
        $this->envIdMap = $map;
    }

    public function getLastCreatedClusterId(): ?string
    {
        return $this->lastCreatedClusterId;
    }

    public function getEnvIdMap(): array
    {
        return $this->envIdMap;
    }

    /**
     * Pass shared cluster/cache registries (by reference) so that multiple app
     * migrations on the same MigrationService instance reuse already-created
     * target clusters and caches instead of creating duplicates.
     */
    public function useSharedRegistries(array &$clusterRegistry, array &$cacheRegistry): void
    {
        $this->clusterRegistry = &$clusterRegistry;
        $this->cacheRegistry = &$cacheRegistry;
    }

    public function buildPlan(string $applicationId, ?callable $progress = null): MigrationPlan
    {
        $this->warnings = [];

        $appData = $this->source->get("applications/{$applicationId}");
        $application = ApplicationData::fromApi($appData['data']);

        $envItems = $this->source->getAll("applications/{$applicationId}/environments", [
            'include' => 'database,cache',
        ]);

        $environments = [];
        $variables = [];
        $databases = [];
        $caches = [];
        $instances = [];
        $domains = [];
        $dbRowCounts = [];
        $hasDeployments = [];

        // Build a map of schemaId → {cluster, schema} by querying all clusters once
        $schemaMap = $this->buildSchemaMap();

        $total = count($envItems);
        $i = 0;

        foreach ($envItems as $envData) {
            $env = EnvironmentData::fromApi($envData);
            $environments[] = $env;
            $i++;

            if ($progress) {
                $progress("Fetching environment {$i}/{$total}: {$env->name}...");
            }

            // Environment variables are included directly in the environment response
            $variables[$env->id] = $envData['attributes']['environment_variables'] ?? [];

            // Database cluster info
            if ($env->databaseSchemaId) {
                if (isset($schemaMap[$env->databaseSchemaId])) {
                    $databases[$env->id] = $schemaMap[$env->databaseSchemaId];
                } else {
                    $this->warnings[] = "Could not find database schema {$env->databaseSchemaId}.";
                }
            }

            // Cache info
            if ($env->cacheId) {
                $caches[$env->id] = $this->fetchCache($env->cacheId);
            }

            // Instances
            $instances[$env->id] = $this->fetchInstances($env->id);

            // Domains
            $domains[$env->id] = $this->fetchDomains($env->id);

            // Deployment status
            $hasDeployments[$env->id] = $this->hasBeenDeployed($env->id);

            // DB row count (approximate, for recommendations)
            if (isset($databases[$env->id])) {
                $dbInfo = $databases[$env->id];
                $conn = $dbInfo['cluster']['attributes']['connection'] ?? null;
                $dbName = $dbInfo['schema']['attributes']['name'] ?? null;
                $dbRowCounts[$env->id] = ($conn && $dbName)
                    ? $this->detectDbRowCount($conn, $dbName)
                    : null;
            }
        }

        // Org-level object storage buckets (not per-environment)
        $buckets = $this->fetchBuckets();

        return new MigrationPlan(
            application: $application,
            environments: $environments,
            variables: $variables,
            databases: $databases,
            caches: $caches,
            instances: $instances,
            domains: $domains,
            buckets: $buckets,
            warnings: $this->warnings,
            dbRowCounts: $dbRowCounts,
            hasDeployments: $hasDeployments,
        );
    }

    private function buildSchemaMap(): array
    {
        $map = [];

        try {
            $clusters = $this->source->getAll('databases/clusters');
        } catch (\RuntimeException) {
            return $map;
        }

        foreach ($clusters as $cluster) {
            try {
                $schemas = $this->source->getAll("databases/clusters/{$cluster['id']}/databases");
                foreach ($schemas as $schema) {
                    $map[$schema['id']] = ['cluster' => $cluster, 'schema' => $schema];
                }
            } catch (\RuntimeException) {
            }
        }

        return $map;
    }

    private function fetchCache(string $cacheId): ?array
    {
        try {
            $response = $this->source->get("caches/{$cacheId}");

            return $response['data'] ?? null;
        } catch (\RuntimeException) {
            $this->warnings[] = "Could not fetch cache {$cacheId}.";

            return null;
        }
    }

    private function fetchInstances(string $environmentId): array
    {
        $items = $this->source->getAll("environments/{$environmentId}/instances");
        $result = [];

        foreach ($items as $instance) {
            $processes = [];
            try {
                $processes = $this->source->getAll("instances/{$instance['id']}/background-processes");
            } catch (\RuntimeException) {
                $this->warnings[] = "Could not fetch background processes for instance {$instance['id']}.";
            }

            $result[] = [
                'instance' => $instance,
                'background_processes' => $processes,
            ];
        }

        return $result;
    }

    public function applicationExistsInTarget(string $slug, string $name): bool
    {
        $apps = $this->target->getAll('applications');
        foreach ($apps as $app) {
            if (($app['attributes']['slug'] ?? '') === $slug) {
                return true;
            }
            if (($app['attributes']['name'] ?? '') === $name) {
                return true;
            }
        }

        return false;
    }

    private function prefillRegistriesFromTarget(MigrationPlan $plan): void
    {
        // Collect the cluster and cache names this plan needs.
        $neededClusterNames = [];
        $neededCacheNames = [];

        foreach ($plan->environments as $env) {
            if (isset($plan->databases[$env->id])) {
                $name = $plan->databases[$env->id]['cluster']['attributes']['name'] ?? null;
                $sourceId = $plan->databases[$env->id]['cluster']['id'] ?? null;
                if ($name && $sourceId) {
                    $neededClusterNames[$name] = $sourceId;
                }
            }
            if (isset($plan->caches[$env->id])) {
                $name = $plan->caches[$env->id]['attributes']['name'] ?? null;
                $sourceId = $plan->caches[$env->id]['id'] ?? null;
                if ($name && $sourceId) {
                    $neededCacheNames[$name] = $sourceId;
                }
            }
        }

        // Pre-populate cluster registry from existing target clusters.
        if ($neededClusterNames) {
            foreach ($this->target->getAll('databases/clusters') as $c) {
                $name = $c['attributes']['name'] ?? null;
                if ($name && isset($neededClusterNames[$name])) {
                    $sourceId = $neededClusterNames[$name];
                    if (! isset($this->clusterRegistry[$sourceId])) {
                        $this->clusterRegistry[$sourceId] = $c['id'];
                    }
                }
            }
        }

        // Pre-populate cache registry from existing target caches.
        if ($neededCacheNames) {
            foreach ($this->target->getAll('caches') as $c) {
                $name = $c['attributes']['name'] ?? null;
                if ($name && isset($neededCacheNames[$name])) {
                    $sourceId = $neededCacheNames[$name];
                    if (! isset($this->cacheRegistry[$sourceId])) {
                        $this->cacheRegistry[$sourceId] = $c['id'];
                    }
                }
            }
        }
    }

    public function execute(MigrationPlan $plan, callable $progress): string
    {
        $this->lastCreatedAppId = null;
        $this->lastCreatedClusterId = null;
        $this->envIdMap = [];
        $this->envNameMap = [];
        $this->dbDataMap = [];

        $this->prefillRegistriesFromTarget($plan);

        $progress('Creating application...');
        $app = $plan->application;

        $newAppPayload = [
            'name' => $app->name,
            'repository' => $app->repository,
            'region' => $app->region,
        ];

        if ($app->sourceControlProviderType) {
            $newAppPayload['source_control_provider_type'] = $app->sourceControlProviderType;
        }

        $newApp = $this->target->post('applications', $newAppPayload);
        $newAppId = $newApp['data']['id'];
        $this->lastCreatedAppId = $newAppId;

        // Index any auto-created environments (e.g. "main") so we reuse them instead of creating duplicates.
        $existingEnvs = [];
        foreach ($this->target->getAll("applications/{$newAppId}/environments") as $e) {
            $existingEnvs[$e['attributes']['name']] = $e['id'];
        }

        $planEnvNames = array_map(fn ($e) => $e->name, $plan->environments);

        foreach ($plan->environments as $env) {
            $progress("Creating environment: {$env->name}...");

            if (isset($existingEnvs[$env->name])) {
                // Exact name match — reuse as-is.
                $newEnvId = $existingEnvs[$env->name];
                unset($existingEnvs[$env->name]);
            } else {
                // Find an auto-created env that is NOT needed by any upcoming plan environment
                $reusableKey = null;
                foreach ($existingEnvs as $candName => $candId) {
                    if (! in_array($candName, $planEnvNames, true)) {
                        $reusableKey = $candName;
                        break;
                    }
                }

                if ($reusableKey !== null) {
                    $newEnvId = $existingEnvs[$reusableKey];
                    unset($existingEnvs[$reusableKey]);
                    $this->target->patch("environments/{$newEnvId}", ['name' => $env->name]);
                } else {
                    $newEnv = $this->target->post("applications/{$newAppId}/environments", [
                        'name' => $env->name,
                        'branch' => $env->branch ?? 'main',
                    ]);
                    $newEnvId = $newEnv['data']['id'];
                }
            }

            $this->envIdMap[$env->id] = $newEnvId;
            $this->envNameMap[$newEnvId] = $env->name;

            // Patch environment settings
            $patch = array_filter([
                'php_version' => $env->phpVersion ? "{$env->phpVersion}:1" : null,
                'node_version' => $env->nodeVersion,
                'build_command' => $env->buildCommand,
                'deploy_command' => $env->deployCommand,
                'uses_octane' => $env->usesOctane,
            ], fn ($v) => $v !== null && $v !== false);

            if ($patch) {
                $this->target->patch("environments/{$newEnvId}", $patch);
            }

            // Environment variables
            $vars = $plan->variables[$env->id] ?? [];
            if ($vars) {
                $count = count($vars);
                $progress("  Migrating {$count} environment variable(s)...");

                $appSlug = $plan->application->slug;
                $appName = $plan->application->name;
                $manifestResolvers = $this->manifest?->getEnvResolvers($appSlug)
                    ?? $this->manifest?->getEnvResolvers($appName)
                    ?? [];

                $context = [
                    'app' => $appSlug,
                    'appName' => $appName,
                    'env' => $env->name,
                    'envSlug' => $env->slug,
                ];

                $processedKeys = [];

                $formatted = array_map(function ($v) use ($manifestResolvers, $context, &$processedKeys) {
                    $key = $v['key'];
                    $val = $v['value'];
                    $processedKeys[] = $key;

                    if (isset($manifestResolvers[$key])) {
                        $val = $manifestResolvers[$key];
                    }

                    $val = $this->envResolver->resolve($val, $context);

                    if ($key === 'API_BASE_URL' && str_contains($val, 'dracin-api.laravel.cloud')) {
                        $targetVanity = $this->resolveTargetVanityDomain('dracin-api.laravel.cloud');
                        if ($targetVanity) {
                            $val = str_replace('dracin-api.laravel.cloud', $targetVanity, $val);
                        }
                    }

                    return array_filter([
                        'key' => $key,
                        'value' => $val,
                        'is_secret' => $v['is_secret'] ?? null,
                    ], fn ($val) => $val !== null);
                }, $vars);

                foreach ($manifestResolvers as $mKey => $mTemplate) {
                    if (! in_array($mKey, $processedKeys, true)) {
                        $val = $this->envResolver->resolve($mTemplate, $context);
                        $formatted[] = [
                            'key' => $mKey,
                            'value' => $val,
                        ];
                    }
                }

                $this->target->post("environments/{$newEnvId}/variables", [
                    'method' => 'set',
                    'variables' => $formatted,
                ]);
            }

            $newDatabaseSchemaId = null;
            $newCacheId = null;

            // Database
            if (isset($plan->databases[$env->id])) {
                $dbInfo = $plan->databases[$env->id];
                $clusterName = $dbInfo['cluster']['attributes']['name'] ?? 'cluster';
                $alreadyHaveCluster = isset($this->clusterRegistry[$dbInfo['cluster']['id']]);
                $progress($alreadyHaveCluster ? "  Reusing database cluster: {$clusterName}..." : '  Creating database cluster...');
                $newDatabaseSchemaId = $this->migrateDatabase($dbInfo, $newEnvId);

                $sourceConn = $dbInfo['cluster']['attributes']['connection'] ?? null;
                $sourceDbName = $dbInfo['schema']['attributes']['name'] ?? null;
                $dbType = $dbInfo['cluster']['attributes']['type'] ?? '';

                $targetClusterId = $this->clusterRegistry[$dbInfo['cluster']['id']] ?? null;
                if ($sourceConn && $sourceDbName && $targetClusterId) {
                    $this->dbDataMap[$env->id] = [
                        'source_conn' => $sourceConn,
                        'source_db' => $sourceDbName,
                        'target_cluster_id' => $targetClusterId,
                        'target_db' => $sourceDbName,
                        'db_type' => $dbType,
                    ];
                }
            }

            // Cache
            if (isset($plan->caches[$env->id])) {
                $cacheData = $plan->caches[$env->id];
                $cacheName = $cacheData['attributes']['name'] ?? 'cache';
                $alreadyHaveCache = isset($this->cacheRegistry[$cacheData['id']]);
                $progress($alreadyHaveCache ? "  Reusing cache: {$cacheName}..." : '  Creating cache...');
                $newCacheId = $this->migrateCache($cacheData, $progress);
            }

            // Link database and cache
            if ($newDatabaseSchemaId || $newCacheId) {
                $this->target->patch("environments/{$newEnvId}", array_filter([
                    'database_schema_id' => $newDatabaseSchemaId,
                    'cache_id' => $newCacheId,
                ], fn ($v) => $v !== null));
            }

            // Instances — each new environment auto-creates one instance;
            // PATCH it with the source settings rather than POSTing a new one.
            $instanceSets = $plan->instances[$env->id] ?? [];
            $autoInstances = $this->target->getAll("environments/{$newEnvId}/instances");

            foreach ($instanceSets as $index => $instanceSet) {
                $instance = $instanceSet['instance'];
                $attrs = $instance['attributes'];

                $progress("  Configuring instance: {$attrs['name']}...");

                $autoInstanceId = $autoInstances[$index]['id'] ?? null;

                if ($autoInstanceId) {
                    $this->target->patch("instances/{$autoInstanceId}", array_filter([
                        'name' => $attrs['name'],
                        'size' => $attrs['size'],
                        'scaling_type' => $attrs['scaling_type'],
                        'min_replicas' => $attrs['min_replicas'],
                        'max_replicas' => $attrs['max_replicas'],
                        'uses_scheduler' => $attrs['uses_scheduler'] ?? null,
                    ], fn ($v) => $v !== null));
                }

                foreach ($instanceSet['background_processes'] as $process) {
                    $pAttrs = $process['attributes'];
                    $this->target->post("instances/{$autoInstanceId}/background-processes", array_filter([
                        'type' => $pAttrs['type'],
                        'processes' => $pAttrs['processes'],
                        'command' => $pAttrs['command'] ?? null,
                        'config' => $pAttrs['config'] ?? null,
                    ], fn ($v) => $v !== null));
                }
            }
        }

        return $newApp['data']['attributes']['slug'];
    }

    private function fetchDomains(string $environmentId): array
    {
        try {
            return $this->source->getAll("environments/{$environmentId}/domains");
        } catch (\RuntimeException) {
            $this->warnings[] = "Could not fetch domains for environment {$environmentId}.";

            return [];
        }
    }

    private function fetchBuckets(): array
    {
        try {
            return $this->source->getAll('buckets');
        } catch (\RuntimeException) {
            return [];
        }
    }

    private function hasBeenDeployed(string $environmentId): bool
    {
        try {
            $response = $this->source->get("environments/{$environmentId}/deployments");

            return ! empty($response['data']);
        } catch (\RuntimeException) {
            return false;
        }
    }

    private function detectDbRowCount(array $conn, string $dbName): ?int
    {
        $mysql = $this->findBinary('mysql');
        if (! $mysql) {
            return null;
        }

        $sql = 'SELECT COALESCE(SUM(table_rows),0) FROM information_schema.tables WHERE table_schema=\''
            .addslashes($dbName).'\'';

        $cmd = escapeshellarg($mysql)
            .' --ssl-mode=DISABLED'
            .' --connect-timeout=5'
            .' --batch --skip-column-names'
            .' -h '.escapeshellarg($conn['hostname'])
            .' -P '.(int) $conn['port']
            .' -u '.escapeshellarg($conn['username'])
            .' --password='.escapeshellarg($conn['password'])
            .' -e '.escapeshellarg($sql)
            .' 2>/dev/null';

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || empty($output)) {
            return null;
        }

        return (int) trim($output[0]);
    }

    public function moveDomains(MigrationPlan $plan, callable $progress): void
    {
        // ── Step 1: transfer custom domains ─────────────────────────────────
        foreach ($plan->environments as $env) {
            $domainItems = $plan->domains[$env->id] ?? [];

            $targetEnvId = $this->envIdMap[$env->id] ?? null;
            if (! $targetEnvId) {
                continue;
            }

            if (empty($domainItems)) {
                continue;
            }

            // Pre-fetch domains already in target env for idempotency checks.
            $alreadyInTarget = array_column(
                array_column($this->target->getAll("environments/{$targetEnvId}/domains"), 'attributes'),
                'name'
            );

            foreach ($domainItems as $domain) {
                $domainId = $domain['id'];
                $domainName = $domain['attributes']['name'] ?? $domainId;

                if (in_array($domainName, $alreadyInTarget)) {
                    $progress("Already migrated: {$domainName} ({$env->name})");

                    continue;
                }

                try {
                    $this->source->delete("domains/{$domainId}");
                } catch (\RuntimeException) {
                    // Already removed — proceed to add to target.
                }

                $moved = false;
                for ($attempt = 1; $attempt <= 3; $attempt++) {
                    try {
                        $this->target->post("environments/{$targetEnvId}/domains", [
                            'name' => $domainName,
                        ]);
                        $progress("Moved domain: {$domainName} ({$env->name})");
                        $moved = true;
                        break;
                    } catch (\RuntimeException $e) {
                        if ($attempt < 3) {
                            sleep(2);
                        } else {
                            $progress("Failed to move {$domainName}: {$e->getMessage()}");
                        }
                    }
                }
            }
        }

        // Vanity domain transfer is handled separately via the transfer-vanity command.
    }

    public function resolveTargetVanityDomain(string $sourceHost): ?string
    {
        if (isset($this->envResolver)) {
            $resolved = $this->envResolver->resolveVanityDomain($sourceHost);
            if ($resolved) {
                return $resolved;
            }
        }

        if (preg_match('/^([a-z0-9-]+)\.laravel\.cloud$/', $sourceHost, $m)) {
            $appName = $m[1];
            try {
                $targetApps = $this->target->getAll('applications');
                foreach ($targetApps as $app) {
                    if (($app['attributes']['slug'] ?? '') === $appName || ($app['attributes']['name'] ?? '') === $appName) {
                        $envs = $this->target->getAll("applications/{$app['id']}/environments");
                        foreach ($envs as $env) {
                            $vd = $env['attributes']['vanity_domain'] ?? null;
                            if ($vd) {
                                return $vd;
                            }
                        }
                    }
                }
            } catch (\RuntimeException) {
                return null;
            }
        }

        return null;
    }

    /**
     * Transfer the source app's Laravel Cloud vanity domain to the target by:
     *  1. Renaming source slug → {original}-archived-{random6}  (frees the slug)
     *  2. Renaming target slug → {original}                      (claims it)
     *  3. Renaming target env names to match source               (clean vanity format)
     */
    private function transferVanityDomain(MigrationPlan $plan, callable $progress): void
    {
        $originalSlug = $plan->application->slug;
        $targetAppId = $this->lastCreatedAppId;

        if (! $targetAppId) {
            return;
        }

        // Look up source app ID by slug — also match if it was already renamed in a
        // previous attempt (slug starts with "{original}-archived-").
        $sourceAppId = null;
        $sourceAlreadyRenamed = false;
        $existingArchivedSlug = null;
        try {
            foreach ($this->source->getAll('applications') as $app) {
                $slug = $app['attributes']['slug'] ?? '';
                if ($slug === $originalSlug) {
                    $sourceAppId = $app['id'];
                    break;
                }
                if (str_starts_with($slug, $originalSlug.'-archived-')) {
                    $sourceAppId = $app['id'];
                    $sourceAlreadyRenamed = true;
                    $existingArchivedSlug = $slug;
                }
            }
        } catch (\RuntimeException) {
        }

        // Capture source vanities before renaming.
        $sourceVanities = [];
        if ($sourceAppId) {
            try {
                foreach ($this->source->getAll("applications/{$sourceAppId}/environments") as $env) {
                    if ($v = $env['attributes']['vanity_domain'] ?? null) {
                        $sourceVanities[] = $v;
                    }
                }
            } catch (\RuntimeException) {
            }
        }

        foreach ($sourceVanities as $v) {
            $progress("Source vanity: {$v}");
        }

        // Step A: rename source slug to free it (skip if already renamed from a prior attempt).
        $archivedSlug = $existingArchivedSlug ?? $originalSlug.'-archived-'.substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 6);

        if ($sourceAppId && ! $sourceAlreadyRenamed) {
            try {
                $this->source->patch("applications/{$sourceAppId}", ['slug' => $archivedSlug]);
                $progress("Source renamed: {$originalSlug} → {$archivedSlug}");
            } catch (\RuntimeException $e) {
                $progress("Could not rename source slug: {$e->getMessage()}");

                return;
            }
        } elseif ($sourceAlreadyRenamed) {
            $progress("Source already renamed: {$archivedSlug} (resuming)");
        }

        // Step B: claim target slug — retry with backoff (slug release can take several minutes).
        $maxAttempts = 30;
        $claimed = false;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $this->target->patch("applications/{$targetAppId}", ['slug' => $originalSlug]);
                $claimed = true;
                $progress("Target slug claimed: {$originalSlug}");
                break;
            } catch (\RuntimeException $e) {
                if ($attempt === $maxAttempts) {
                    $progress("Could not claim slug \"{$originalSlug}\" after {$maxAttempts} attempts ({$e->getMessage()}).");
                    $progress("Source is still renamed to \"{$archivedSlug}\" — re-run this command later to retry.");

                    return;
                }
                $elapsed = ($attempt - 1) * 10;
                $progress("Slug not yet released (attempt {$attempt}/{$maxAttempts}, {$elapsed}s elapsed) — retrying in 10s...");
                sleep(10);
            }
        }

        if (! $claimed) {
            return;
        }

        // Step C: rename target env names to match source (affects vanity format).
        foreach ($plan->environments as $env) {
            $targetEnvId = $this->envIdMap[$env->id] ?? null;
            if (! $targetEnvId) {
                continue;
            }

            try {
                $this->target->patch("environments/{$targetEnvId}", ['name' => $env->name]);
            } catch (\RuntimeException) {
                // Non-fatal — slug rename already done.
            }
        }

        // Report new target vanity after rename.
        sleep(1);
        try {
            foreach ($this->target->getAll("applications/{$targetAppId}/environments") as $env) {
                if ($v = $env['attributes']['vanity_domain'] ?? null) {
                    $progress("Target vanity: {$v}");
                }
            }
        } catch (\RuntimeException) {
        }
    }

    public function triggerDeployments(callable $progress): void
    {
        foreach ($this->envIdMap as $newEnvId) {
            $envName = $this->envNameMap[$newEnvId] ?? $newEnvId;

            try {
                $this->target->post("environments/{$newEnvId}/deployments", []);
                $progress("Deployment triggered for \"{$envName}\"");
            } catch (\RuntimeException $e) {
                $progress("Could not trigger deployment for \"{$envName}\": {$e->getMessage()}");
            }
        }
    }

    private function migrateDatabase(array $dbInfo, string $newEnvId): ?string
    {
        $cluster = $dbInfo['cluster'];
        $schema = $dbInfo['schema'];

        if (! $cluster) {
            return null;
        }

        $attrs = $cluster['attributes'];
        $sourceClusterId = $cluster['id'];

        // Reuse an already-registered target cluster (pre-filled by prefillRegistriesFromTarget or a prior env).
        if (isset($this->clusterRegistry[$sourceClusterId])) {
            $newClusterId = $this->clusterRegistry[$sourceClusterId];
        } else {
            $clusterPayload = [
                'type' => $attrs['type'],
                'name' => $attrs['name'],
                'region' => $attrs['region'],
                'config' => $attrs['config'] ?? [],
            ];

            if (isset($attrs['version'])) {
                $clusterPayload['version'] = $attrs['version'];
            } else {
                try {
                    $types = $this->target->get('databases/types');
                    foreach ($types['data'] ?? [] as $t) {
                        if (($t['type'] ?? '') === $attrs['type'] && ! empty($t['versions'])) {
                            $clusterPayload['version'] = end($t['versions']);
                            break;
                        }
                    }
                } catch (\RuntimeException) {
                }

                if (! isset($clusterPayload['version'])) {
                    if ($attrs['type'] === 'laravel_mysql' || $attrs['type'] === 'aws_rds_mysql') {
                        $clusterPayload['version'] = '8.4';
                    } elseif (str_contains($attrs['type'], 'postgres')) {
                        $clusterPayload['version'] = '18';
                    }
                }
            }

            try {
                $newCluster = $this->target->post('databases/clusters', $clusterPayload);
                $newClusterId = $newCluster['data']['id'];
                $this->lastCreatedClusterId = $newClusterId;
            } catch (\RuntimeException $e) {
                throw new \RuntimeException("Could not create or find cluster \"{$attrs['name']}\" in target org: {$e->getMessage()}");
            }

            // Wait for the newly created cluster to finish provisioning.
            $waited = 0;
            while ($waited < 300) {
                $status = $this->target->get("databases/clusters/{$newClusterId}")['data']['attributes']['status'] ?? 'unknown';
                if ($status === 'available') {
                    break;
                }
                sleep(5);
                $waited += 5;
            }

            $this->clusterRegistry[$sourceClusterId] = $newClusterId;
        }

        $schemaAttrs = $schema['attributes'];
        $schemaName = $schemaAttrs['name'];

        // Cluster may auto-create a default schema — reuse it if it matches.
        $existingSchemas = $this->target->getAll("databases/clusters/{$newClusterId}/databases");
        $existingSchema = null;
        foreach ($existingSchemas as $s) {
            if (($s['attributes']['name'] ?? '') === $schemaName) {
                $existingSchema = $s;
                break;
            }
        }

        if ($existingSchema) {
            return $existingSchema['id'];
        }

        $newSchema = $this->target->post("databases/clusters/{$newClusterId}/databases", [
            'name' => $schemaName,
        ]);

        return $newSchema['data']['id'] ?? null;
    }

    public function migrateDbData(callable $progress, array $skipSchemas = [], array $ignoreTables = []): void
    {
        foreach ($this->dbDataMap as $sourceEnvId => $info) {
            $srcDb = $info['source_db'];

            if (in_array($srcDb, $skipSchemas, true)) {
                $progress("Skipping data for \"{$srcDb}\" (excluded via --skip-data).");

                continue;
            }

            $progress('Waiting for target cluster to be ready...');
            $targetConn = $this->pollClusterUntilAvailable($info['target_cluster_id'], $progress);

            if (! $targetConn) {
                $progress('Timed out waiting for cluster — skipping data migration for this environment.');

                continue;
            }

            // Resolve which tables to ignore for this schema
            $schemaIgnoreTables = [];
            foreach ($ignoreTables as $entry) {
                if (str_contains($entry, '.')) {
                    [$schema, $table] = explode('.', $entry, 2);
                    if ($schema === $srcDb) {
                        $schemaIgnoreTables[] = $table;
                    }
                } else {
                    $schemaIgnoreTables[] = $entry;
                }
            }

            $this->runDatabaseMigration(
                $info['source_conn'],
                $srcDb,
                $targetConn,
                $info['target_db'],
                $info['db_type'],
                $progress,
                $schemaIgnoreTables,
            );
        }
    }

    private function pollClusterUntilAvailable(string $clusterId, callable $progress, int $maxWaitSeconds = 300): ?array
    {
        $waited = 0;

        while ($waited < $maxWaitSeconds) {
            $cluster = $this->target->get("databases/clusters/{$clusterId}");
            $status = $cluster['data']['attributes']['status'] ?? 'unknown';
            $conn = $cluster['data']['attributes']['connection'] ?? null;

            if ($status === 'available' && $conn) {
                return $conn;
            }

            $progress("  Cluster status: {$status} — checking again in 10s...");
            sleep(10);
            $waited += 10;
        }

        return null;
    }

    public function runDatabaseMigration(array $srcConn, string $srcDb, array $tgtConn, string $tgtDb, string $dbType, callable $progress, array $ignoreTables = [], int $concurrency = 4, int $chunkSize = TableChunker::DEFAULT_CHUNK_SIZE, array $tablePolicies = []): void
    {
        $isPostgres = str_contains($dbType, 'pgsql') || str_contains($dbType, 'postgres');

        if ($isPostgres) {
            $dumpBin = $this->findBinary('pg_dump');
            $importBin = $this->findBinary('psql');
            if (! $dumpBin || ! $importBin) {
                throw new \RuntimeException('pg_dump/psql not found — install PostgreSQL client tools and retry.');
            }

            $this->runPostgresMigration($dumpBin, $importBin, $srcConn, $srcDb, $tgtConn, $tgtDb, $progress, $ignoreTables, $concurrency);
        } else {
            $dumpBin = $this->findBinary('mysqldump');
            $importBin = $this->findBinary('mysql');
            if (! $dumpBin || ! $importBin) {
                throw new \RuntimeException('mysqldump/mysql not found — install MySQL client tools and retry.');
            }

            $this->runMysqlMigration($dumpBin, $importBin, $srcConn, $srcDb, $tgtConn, $tgtDb, $progress, $ignoreTables, $concurrency, $chunkSize, $tablePolicies);
        }
    }

    /**
     * Parallel per-table PostgreSQL migration.
     *
     * Phase 1: schema-only dump (pg_dump --schema-only) → import.
     * Phase 2: per-table data dumps (pg_dump -t {table} --data-only) in parallel.
     */
    private function runPostgresMigration(
        string $dumpBin,
        string $importBin,
        array $srcConn,
        string $srcDb,
        array $tgtConn,
        string $tgtDb,
        callable $progress,
        array $ignoreTables = [],
        int $concurrency = 4,
    ): void {
        $srcDsn = sprintf('postgresql://%s:%s@%s:%d/%s',
            rawurlencode($srcConn['username']),
            rawurlencode($srcConn['password']),
            $srcConn['hostname'],
            (int) $srcConn['port'],
            $srcDb,
        );
        $tgtDsn = sprintf('postgresql://%s:%s@%s:%d/%s',
            rawurlencode($tgtConn['username']),
            rawurlencode($tgtConn['password']),
            $tgtConn['hostname'],
            (int) $tgtConn['port'],
            $tgtDb,
        );

        $tmpDir = sys_get_temp_dir().'/cloud_migrator_pg_'.uniqid();
        mkdir($tmpDir, 0700, true);

        try {
            // ── Phase 1: schema-only dump ────────────────────────────────────
            $progress("Dumping schema for {$srcDb}...");

            $schemaFile = $tmpDir.'/schema.sql';
            $schemaErrFile = $tmpDir.'/schema.err';

            $schemaDumpCmd = escapeshellarg($dumpBin)
                .' --schema-only'
                .' --no-owner'
                .' --no-acl'
                .' '.escapeshellarg($srcDsn);

            $proc = proc_open($schemaDumpCmd, [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $schemaFile, 'w'],
                2 => ['file', $schemaErrFile, 'w'],
            ], $pipes);

            if (! is_resource($proc)) {
                throw new \RuntimeException('Failed to start pg_dump for schema.');
            }
            $exitCode = $this->waitProc($proc);
            if ($exitCode !== 0) {
                throw new \RuntimeException('Schema dump failed: '.trim(file_get_contents($schemaErrFile) ?: ''));
            }

            $schemaImportErrFile = $tmpDir.'/schema.import.err';
            $proc = proc_open(escapeshellarg($importBin).' '.escapeshellarg($tgtDsn), [
                0 => ['file', $schemaFile, 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', $schemaImportErrFile, 'w'],
            ], $pipes);

            if (! is_resource($proc)) {
                throw new \RuntimeException('Failed to start psql for schema import.');
            }
            $exitCode = $this->waitProc($proc);
            if ($exitCode !== 0) {
                throw new \RuntimeException('Schema import failed: '.trim(file_get_contents($schemaImportErrFile) ?: ''));
            }
            @unlink($schemaFile);

            // ── Phase 2: parallel per-table data dumps ───────────────────────
            $tables = $this->getPostgresTables($importBin, $srcDsn, $ignoreTables);
            $total = count($tables);

            if ($ignoreTables) {
                $progress('  Excluding tables: '.implode(', ', $ignoreTables));
            }

            $progress("  Migrating {$total} table(s) with concurrency={$concurrency}...");

            $pending = array_values($tables);
            $running = [];
            $completed = 0;
            $failedTables = [];

            while (! empty($pending) || ! empty($running)) {
                while (count($running) < $concurrency && ! empty($pending)) {
                    $table = array_shift($pending);
                    $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', $table);
                    $sqlFile = $tmpDir.'/'.$safe.'.sql';
                    $dumpErrFile = $tmpDir.'/'.$safe.'.dump.err';

                    $tableDumpCmd = escapeshellarg($dumpBin)
                        .' --data-only'
                        .' --no-owner'
                        .' --no-acl'
                        .' --disable-triggers'
                        .' -t '.escapeshellarg($table)
                        .' '.escapeshellarg($srcDsn);

                    $proc = proc_open($tableDumpCmd, [
                        0 => ['file', '/dev/null', 'r'],
                        1 => ['file', $sqlFile, 'w'],
                        2 => ['file', $dumpErrFile, 'w'],
                    ], $pipes);

                    if (is_resource($proc)) {
                        $running[$table] = [
                            'phase' => 'dumping',
                            'proc' => $proc,
                            'sqlFile' => $sqlFile,
                            'dumpErrFile' => $dumpErrFile,
                            'importErrFile' => null,
                        ];
                    } else {
                        $progress("  ✗ Could not start dump for {$table}");
                        $failedTables[] = $table;
                    }
                }

                foreach (array_keys($running) as $table) {
                    $worker = $running[$table];
                    $status = proc_get_status($worker['proc']);

                    if ($status['running']) {
                        continue;
                    }

                    $exitCode = proc_close($worker['proc']);
                    if ($exitCode === -1) {
                        $exitCode = $status['exitcode'] ?? -1;
                    }

                    if ($worker['phase'] === 'dumping') {
                        if ($exitCode !== 0) {
                            $err = trim(file_get_contents($worker['dumpErrFile']) ?: '');
                            $progress("  ✗ Dump failed: {$table}".($err ? " — {$err}" : ''));
                            $failedTables[] = $table;
                            unset($running[$table]);
                        } else {
                            $importErrFile = $tmpDir.'/'.preg_replace('/[^a-zA-Z0-9_]/', '_', $table).'.import.err';
                            $importProc = proc_open(escapeshellarg($importBin).' '.escapeshellarg($tgtDsn), [
                                0 => ['file', $worker['sqlFile'], 'r'],
                                1 => ['file', '/dev/null', 'w'],
                                2 => ['file', $importErrFile, 'w'],
                            ], $pipes);

                            if (is_resource($importProc)) {
                                $running[$table] = [
                                    'phase' => 'importing',
                                    'proc' => $importProc,
                                    'sqlFile' => $worker['sqlFile'],
                                    'dumpErrFile' => $worker['dumpErrFile'],
                                    'importErrFile' => $importErrFile,
                                ];
                            } else {
                                $progress("  ✗ Could not start import for {$table}");
                                $failedTables[] = $table;
                                unset($running[$table]);
                            }
                        }
                    } elseif ($worker['phase'] === 'importing') {
                        if ($exitCode !== 0) {
                            $err = trim(file_get_contents($worker['importErrFile']) ?: '');
                            $progress("  ✗ Import failed: {$table}".($err ? " — {$err}" : ''));
                            $failedTables[] = $table;
                        } else {
                            $completed++;
                            $rows = $this->getPostgresRowCount($importBin, $tgtDsn, $table);
                            $progress("  Migrated {$table} ({$rows} rows)");
                        }

                        unset($running[$table]);
                        @unlink($worker['sqlFile']);
                    }
                }

                if (! empty($running)) {
                    usleep(200000);
                }
            }

            if (! empty($failedTables)) {
                $count = count($failedTables);
                $progress("  ⚠ {$count} table(s) failed: ".implode(', ', $failedTables));
            }

            $progress("Data migrated: {$srcDb}");

        } finally {
            foreach (glob($tmpDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($tmpDir);
        }
    }

    /** Return user tables in a Postgres database, excluding $ignoreTables, largest first. */
    private function getPostgresTables(string $psql, string $dsn, array $ignoreTables = []): array
    {
        $excludeClause = '';
        if ($ignoreTables) {
            $quoted = implode(',', array_map(fn ($t) => "'".addslashes($t)."'", $ignoreTables));
            $excludeClause = " AND tablename NOT IN ({$quoted})";
        }

        $sql = "SELECT tablename FROM pg_tables WHERE schemaname='public'{$excludeClause} ORDER BY tablename";

        $cmd = escapeshellarg($psql)
            .' --no-psqlrc'
            .' --tuples-only'
            .' --no-align'
            .' -c '.escapeshellarg($sql)
            .' '.escapeshellarg($dsn)
            .' 2>/dev/null';

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            return [];
        }

        return array_values(array_filter(array_map('trim', $output)));
    }

    /** Return row count for a single Postgres table. */
    private function getPostgresRowCount(string $psql, string $dsn, string $table): int
    {
        $cmd = escapeshellarg($psql)
            .' --no-psqlrc'
            .' --tuples-only'
            .' --no-align'
            .' -c '.escapeshellarg("SELECT COUNT(*) FROM \"{$table}\"")
            .' '.escapeshellarg($dsn)
            .' 2>/dev/null';

        exec($cmd, $output);

        return (int) trim($output[0] ?? '0');
    }

    /**
     * Parallel per-table file-based MySQL migration.
     *
     * Phase 1: dump schema only (blocking, fast) → import into target.
     * Phase 2: for each table, dump data to a temp file then import it;
     *          up to $concurrency tables processed simultaneously.
     *
     * Per-table dumps avoid the source's max_execution_time limit that
     * kills full-database mysqldump queries on large datasets.
     */
    private function runMysqlMigration(
        string $dumpBin,
        string $importBin,
        array $srcConn,
        string $srcDb,
        array $tgtConn,
        string $tgtDb,
        callable $progress,
        array $ignoreTables = [],
        int $concurrency = 4,
        int $chunkSize = TableChunker::DEFAULT_CHUNK_SIZE,
        array $tablePolicies = [],
    ): void {
        $tmpDir = sys_get_temp_dir().'/cloud_migrator_'.uniqid();
        mkdir($tmpDir, 0700, true);

        // Empty options file — still passed via --defaults-extra-file so the flag is accepted.
        $optFile = $tmpDir.'/my.cnf';
        file_put_contents($optFile, "[mysqldump]\n");

        try {
            // ── Phase 1: schema dump (no data) ──────────────────────────────
            $progress("Dumping schema for {$srcDb}...");

            $schemaFile = $tmpDir.'/schema.sql';
            $schemaErrFile = $tmpDir.'/schema.err';

            // Collect any tables with policy 'ignore' to skip from schema dump
            $schemaIgnoreTables = $ignoreTables;
            foreach ($tablePolicies as $tbl => $pol) {
                if (($pol instanceof TablePolicy && $pol->isIgnore()) || $pol === 'ignore') {
                    $schemaIgnoreTables[] = $tbl;
                }
            }
            $schemaIgnoreTables = array_unique($schemaIgnoreTables);

            $schemaDumpCmd = $this->buildMysqldumpCommand(
                $dumpBin,
                $srcConn,
                $srcDb,
                schemaOnly: true,
                optFile: $optFile
            );

            if (! empty($schemaIgnoreTables)) {
                $ignoreArgs = [];
                foreach ($schemaIgnoreTables as $ign) {
                    $ignoreArgs[] = '--ignore-table='.escapeshellarg("{$srcDb}.{$ign}");
                }
                $schemaDumpCmd .= ' '.implode(' ', $ignoreArgs);
            }

            $proc = proc_open($schemaDumpCmd, [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $schemaFile, 'w'],
                2 => ['file', $schemaErrFile, 'w'],
            ], $pipes);

            if (! is_resource($proc)) {
                throw new \RuntimeException('Failed to start mysqldump for schema.');
            }
            $exitCode = $this->waitProc($proc);
            if ($exitCode !== 0) {
                throw new \RuntimeException('Schema dump failed: '.trim(file_get_contents($schemaErrFile) ?: ''));
            }

            // Wrap schema with permanent anti-deadlock and constraint disable guards
            $schemaSql = file_get_contents($schemaFile) ?: '';
            file_put_contents(
                $schemaFile,
                $this->getAntiDeadlockSqlPrefix().$schemaSql.$this->getAntiDeadlockSqlSuffix()
            );

            $schemaImportCmd = $this->buildMysqlRestoreCommand(
                $importBin,
                $tgtConn,
                $tgtDb,
                force: false
            );

            $schemaImportErrFile = $tmpDir.'/schema.import.err';
            $proc = proc_open($schemaImportCmd, [
                0 => ['file', $schemaFile, 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', $schemaImportErrFile, 'w'],
            ], $pipes);

            if (! is_resource($proc)) {
                throw new \RuntimeException('Failed to start mysql for schema import.');
            }
            $exitCode = $this->waitProc($proc);
            if ($exitCode !== 0) {
                throw new \RuntimeException('Schema import failed: '.trim(file_get_contents($schemaImportErrFile) ?: ''));
            }

            @unlink($schemaFile);

            // Relax any STORED GENERATED columns in target schema before data load
            $storedGenCols = $this->getStoredGeneratedColumns($importBin, $tgtConn, $tgtDb);
            if (empty($storedGenCols)) {
                $storedGenCols = $this->getStoredGeneratedColumns($importBin, $srcConn, $srcDb);
            }
            foreach ($storedGenCols as $colInfo) {
                $progress("  Relaxing stored generated column: {$colInfo['table']}.{$colInfo['column']}...");
                $this->relaxStoredGeneratedColumn($importBin, $tgtConn, $tgtDb, $colInfo);
            }

            // ── Phase 2: parallel per-table data dumps ───────────────────────
            $tables = $this->getSourceTables($srcConn, $srcDb, $ignoreTables, $concurrency, $chunkSize, $tablePolicies);
            $total = count($tables);

            if ($ignoreTables) {
                $progress('  Excluding tables: '.implode(', ', $ignoreTables));
            }

            $progress("  Migrating {$total} item(s) with concurrency={$concurrency}...");

            $this->runParallelTableDumps(
                $dumpBin, $importBin, $optFile,
                $srcConn, $srcDb, $tgtConn, $tgtDb,
                $tables, $tmpDir, $concurrency, $progress,
            );

            // Restore any STORED GENERATED columns in target schema
            foreach ($storedGenCols as $colInfo) {
                $progress("  Restoring stored generated column: {$colInfo['table']}.{$colInfo['column']}...");
                $this->restoreStoredGeneratedColumn($importBin, $tgtConn, $tgtDb, $colInfo);
            }

            $progress("Data migrated: {$srcDb}");

        } finally {
            foreach (glob($tmpDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($tmpDir);
        }
    }

    /** Return any stored generated columns in target DB so they can be relaxed during data load. */
    private function getStoredGeneratedColumns(string $mysqlBin, array $tgtConn, string $tgtDb): array
    {
        $sql = "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '".addslashes($tgtDb)."' AND EXTRA LIKE '%STORED GENERATED%'";
        $cmd = escapeshellarg($mysqlBin)
            .' --ssl-mode=DISABLED --batch --skip-column-names'
            .' -h '.escapeshellarg($tgtConn['hostname'])
            .' -P '.(int) $tgtConn['port']
            .' -u '.escapeshellarg($tgtConn['username'])
            .' --password='.escapeshellarg($tgtConn['password'])
            .' -e '.escapeshellarg($sql)
            .' '.escapeshellarg($tgtDb)
            .' 2>/dev/null';

        exec($cmd, $output, $exitCode);
        if ($exitCode !== 0 || empty($output)) {
            return [];
        }

        $cols = [];
        foreach ($output as $line) {
            $parts = explode("\t", trim($line));
            if (count($parts) >= 4) {
                $cleanExpr = str_replace(["\\\\'", "\\'"], "'", $parts[3]);
                $cols[] = [
                    'table' => $parts[0],
                    'column' => $parts[1],
                    'type' => $parts[2],
                    'expression' => $cleanExpr,
                ];
            }
        }

        return $cols;
    }

    private function relaxStoredGeneratedColumn(string $mysqlBin, array $tgtConn, string $tgtDb, array $colInfo): void
    {
        $sql = "ALTER TABLE `{$colInfo['table']}` MODIFY COLUMN `{$colInfo['column']}` {$colInfo['type']} NULL";
        $cmd = escapeshellarg($mysqlBin)
            .' --ssl-mode=DISABLED'
            .' --init-command='.escapeshellarg('SET SESSION wait_timeout=28800, net_read_timeout=3600, net_write_timeout=3600')
            .' -h '.escapeshellarg($tgtConn['hostname'])
            .' -P '.(int) $tgtConn['port']
            .' -u '.escapeshellarg($tgtConn['username'])
            .' --password='.escapeshellarg($tgtConn['password'])
            .' -e '.escapeshellarg($sql)
            .' '.escapeshellarg($tgtDb);

        exec($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException("Failed to relax stored generated column {$colInfo['table']}.{$colInfo['column']}: ".implode("\n", $output));
        }
    }

    private function restoreStoredGeneratedColumn(string $mysqlBin, array $tgtConn, string $tgtDb, array $colInfo): void
    {
        $sql = "ALTER TABLE `{$colInfo['table']}` MODIFY COLUMN `{$colInfo['column']}` {$colInfo['type']} GENERATED ALWAYS AS ({$colInfo['expression']}) STORED";
        $cmd = escapeshellarg($mysqlBin)
            .' --ssl-mode=DISABLED'
            .' --init-command='.escapeshellarg('SET SESSION wait_timeout=28800, net_read_timeout=3600, net_write_timeout=3600')
            .' -h '.escapeshellarg($tgtConn['hostname'])
            .' -P '.(int) $tgtConn['port']
            .' -u '.escapeshellarg($tgtConn['username'])
            .' --password='.escapeshellarg($tgtConn['password'])
            .' -e '.escapeshellarg($sql)
            .' '.escapeshellarg($tgtDb);

        exec($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException("Failed to restore stored generated column {$colInfo['table']}.{$colInfo['column']}: ".implode("\n", $output));
        }
    }

    /** Block until a proc_open resource exits and return its exit code. */
    private function waitProc(mixed $proc): int
    {
        do {
            $status = proc_get_status($proc);
            if ($status['running']) {
                usleep(100000);
            }
        } while ($status['running']);

        $exitCode = proc_close($proc);

        // proc_close returns the exit code; fall back to status if it returns -1
        if ($exitCode === -1) {
            $exitCode = $status['exitcode'] ?? -1;
        }

        return $exitCode;
    }

    /**
     * Return table partitions (chunked and unchunked) in the given schema ordered largest-first,
     * dynamically auto-chunking tables exceeding 1 GB or 100,000 rows.
     *
     * @return array<int, array{table: string, where: string|null, label: string, chunked: bool, strategy: string, part: int, total_parts: int}>
     */
    public function getSourceTables(
        array $conn,
        string $dbName,
        array $ignoreTables = [],
        int $concurrency = 4,
        int $chunkSize = TableChunker::DEFAULT_CHUNK_SIZE,
        array $tablePolicies = []
    ): array {
        $mysql = $this->findBinary('mysql');
        if (! $mysql) {
            return [];
        }

        return $this->tableChunker->getTablePartitions(
            $mysql,
            $conn,
            $dbName,
            $ignoreTables,
            $concurrency,
            $chunkSize,
            tablePolicies: $tablePolicies
        );
    }

    /**
     * Run up to $concurrency mysqldump-then-import pipelines simultaneously.
     * Each table is: dump data → temp file → import from file → delete file.
     */
    private function runParallelTableDumps(
        string $dumpBin,
        string $importBin,
        string $optFile,
        array $srcConn,
        string $srcDb,
        array $tgtConn,
        string $tgtDb,
        array $tables,
        string $tmpDir,
        int $concurrency,
        callable $progress,
    ): void {
        $baseImportCmd = $this->buildMysqlRestoreCommand($importBin, $tgtConn, $tgtDb, force: true);

        $pending = array_values($tables);
        $running = [];  // label → ['phase', 'proc', 'sqlFile', 'dumpErrFile', 'importErrFile', 'label', 'table', 'attempt']
        $completed = 0;
        $total = count($tables);
        $failedTables = [];

        while (! empty($pending) || ! empty($running)) {
            // Start new dump workers up to the concurrency cap.
            while (count($running) < $concurrency && ! empty($pending)) {
                $item = array_shift($pending);
                $table = is_array($item) ? $item['table'] : $item;
                $where = is_array($item) ? $item['where'] : null;
                $label = is_array($item) ? $item['label'] : $item;

                $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', $label);
                $sqlFile = $tmpDir.'/'.$safe.'.sql';
                $dumpErrFile = $tmpDir.'/'.$safe.'.dump.err';

                $dumpCmd = $this->buildMysqldumpCommand(
                    $dumpBin,
                    $srcConn,
                    $srcDb,
                    table: $table,
                    where: $where,
                    schemaOnly: false,
                    optFile: $optFile
                );

                $proc = proc_open($dumpCmd, [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['file', $sqlFile, 'w'],
                    2 => ['file', $dumpErrFile, 'w'],
                ], $pipes);

                if (is_resource($proc)) {
                    $running[$label] = [
                        'phase' => 'dumping',
                        'proc' => $proc,
                        'sqlFile' => $sqlFile,
                        'dumpErrFile' => $dumpErrFile,
                        'importErrFile' => null,
                        'label' => $label,
                        'table' => $table,
                        'attempt' => 1,
                    ];
                } else {
                    $progress("  ✗ Could not start dump for {$label}");
                    $failedTables[] = $label;
                }
            }

            // Poll each running worker.
            foreach (array_keys($running) as $label) {
                $worker = $running[$label];
                $status = proc_get_status($worker['proc']);

                if ($status['running']) {
                    continue;
                }

                $exitCode = proc_close($worker['proc']);
                if ($exitCode === -1) {
                    $exitCode = $status['exitcode'] ?? -1;
                }

                $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', $worker['label']);

                if ($worker['phase'] === 'dumping') {
                    if ($exitCode !== 0) {
                        $err = trim(file_get_contents($worker['dumpErrFile']) ?: '');
                        $progress("  ✗ Dump failed: {$label}".($err ? " — {$err}" : ''));
                        $failedTables[] = $label;
                        unset($running[$label]);
                    } else {
                        // Transition: start import immediately.
                        $importErrFile = $tmpDir.'/'.$safe.'.import.err';
                        $importProc = proc_open($baseImportCmd, [
                            0 => ['file', $worker['sqlFile'], 'r'],
                            1 => ['file', '/dev/null', 'w'],
                            2 => ['file', $importErrFile, 'w'],
                        ], $pipes);

                        if (is_resource($importProc)) {
                            $running[$label] = [
                                'phase' => 'importing',
                                'proc' => $importProc,
                                'sqlFile' => $worker['sqlFile'],
                                'dumpErrFile' => $worker['dumpErrFile'],
                                'importErrFile' => $importErrFile,
                                'label' => $label,
                                'table' => $worker['table'],
                                'attempt' => 1,
                            ];
                        } else {
                            $progress("  ✗ Could not start import for {$label}");
                            $failedTables[] = $label;
                            unset($running[$label]);
                        }
                    }
                } elseif ($worker['phase'] === 'importing') {
                    if ($exitCode !== 0) {
                        $err = trim(file_get_contents($worker['importErrFile']) ?: '');
                        $isDeadlock = str_contains($err, '1213')
                            || stripos($err, 'Deadlock found') !== false
                            || str_contains($err, '1205')
                            || stripos($err, 'Lock wait timeout') !== false;

                        $attempt = $worker['attempt'] ?? 1;
                        if ($isDeadlock && $attempt < 3) {
                            $progress("  ⚠ Deadlock/lock timeout on {$label} (attempt {$attempt}/3) — retrying in ".($attempt * 2).'s...');
                            sleep($attempt * 2);
                            $importProc = proc_open($baseImportCmd, [
                                0 => ['file', $worker['sqlFile'], 'r'],
                                1 => ['file', '/dev/null', 'w'],
                                2 => ['file', $worker['importErrFile'], 'w'],
                            ], $pipes);

                            if (is_resource($importProc)) {
                                $running[$label]['proc'] = $importProc;
                                $running[$label]['attempt'] = $attempt + 1;

                                continue;
                            }
                        }

                        $progress("  ✗ Import failed: {$label}".($err ? " — {$err}" : ''));
                        $failedTables[] = $label;
                    } else {
                        $completed++;
                        $progress("  ✓ {$label} ({$completed}/{$total})");

                        // Surface errors mysql swallowed via --force.
                        $importErrors = array_filter(
                            explode("\n", file_get_contents($worker['importErrFile']) ?: ''),
                            fn ($line) => str_starts_with(trim($line), 'ERROR'),
                        );
                        foreach (array_slice(array_values($importErrors), 0, 3) as $err) {
                            $progress('    ⚠ '.trim($err));
                        }
                        if (count($importErrors) > 3) {
                            $progress('    ⚠ ... and '.(count($importErrors) - 3).' more error(s).');
                        }
                    }

                    unset($running[$label]);
                    @unlink($worker['sqlFile']);  // free disk space as we go
                }
            }

            if (! empty($running)) {
                usleep(200000); // 200ms poll
            }
        }

        if (! empty($failedTables)) {
            $count = count($failedTables);
            $progress("  ⚠ {$count} table(s) failed: ".implode(', ', $failedTables));
        }
    }

    private function migrateCache(array $cacheData, ?callable $progress = null): ?string
    {
        $sourceCacheId = $cacheData['id'];

        if (! isset($this->cacheRegistry[$sourceCacheId])) {
            $attrs = $cacheData['attributes'];

            $payload = array_filter([
                'type' => $attrs['type'],
                'name' => $attrs['name'],
                'region' => $attrs['region'],
                'size' => $attrs['size'],
                'auto_upgrade_enabled' => $attrs['auto_upgrade_enabled'] ?? false,
                'is_public' => $attrs['is_public'] ?? false,
                'eviction_policy' => $attrs['eviction_policy'] ?? null,
            ], fn ($v) => $v !== null);

            try {
                $newCache = $this->target->post('caches', $payload);
                $newCacheId = $newCache['data']['id'] ?? null;
            } catch (\RuntimeException $e) {
                if ($progress) {
                    $progress("  Could not create cache \"{$attrs['name']}\": {$e->getMessage()}");
                }
                $newCacheId = null;
            }

            if (! $newCacheId) {
                return null;
            }

            $this->cacheRegistry[$sourceCacheId] = $newCacheId;
        }

        $newCacheId = $this->cacheRegistry[$sourceCacheId];

        // Wait for the cache to finish provisioning before linking it.
        $waited = 0;
        while ($waited < 300) {
            $status = $this->target->get("caches/{$newCacheId}")['data']['attributes']['status'] ?? 'unknown';
            if ($status === 'available') {
                break;
            }
            sleep(5);
            $waited += 5;
        }

        return $newCacheId;
    }
}
