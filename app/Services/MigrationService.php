<?php

namespace App\Services;

use App\Data\ApplicationData;
use App\Data\EnvironmentData;
use App\Data\MigrationPlan;

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

    public function __construct(
        private readonly CloudApiClient $source,
        private readonly CloudApiClient $target,
    ) {}

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

    public function execute(MigrationPlan $plan, callable $progress): string
    {
        $this->lastCreatedAppId = null;
        $this->lastCreatedClusterId = null;
        $this->envIdMap = [];
        $this->envNameMap = [];
        $this->dbDataMap = [];

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

        foreach ($plan->environments as $env) {
            $progress("Creating environment: {$env->name}...");

            if (isset($existingEnvs[$env->name])) {
                $newEnvId = $existingEnvs[$env->name];
            } else {
                $newEnv = $this->target->post("applications/{$newAppId}/environments", [
                    'name' => $env->name,
                    'branch' => $env->branch,
                ]);
                $newEnvId = $newEnv['data']['id'];
            }

            $this->envIdMap[$env->id] = $newEnvId;
            $this->envNameMap[$newEnvId] = $env->name;

            // Patch environment settings
            $patch = array_filter([
                'php_version' => $env->phpVersion,
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
                $formatted = array_map(fn ($v) => array_filter([
                    'key' => $v['key'],
                    'value' => $v['value'],
                    'is_secret' => $v['is_secret'] ?? null,
                ], fn ($val) => $val !== null), $vars);

                $this->target->post("environments/{$newEnvId}/variables", [
                    'method' => 'set',
                    'variables' => $formatted,
                ]);
            }

            $newDatabaseSchemaId = null;
            $newCacheId = null;

            // Database
            if (isset($plan->databases[$env->id])) {
                $progress('  Creating database cluster...');
                $dbInfo = $plan->databases[$env->id];
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
                $progress('  Creating cache...');
                $cacheData = $plan->caches[$env->id];
                $newCacheId = $this->migrateCache($cacheData);
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

                try {
                    $this->target->post("environments/{$targetEnvId}/domains", [
                        'name' => $domainName,
                    ]);
                    $progress("Moved domain: {$domainName} ({$env->name})");
                } catch (\RuntimeException $e) {
                    $progress("Failed to move {$domainName}: {$e->getMessage()}");
                }
            }
        }

        // Vanity domain transfer is handled separately via the transfer-vanity command.
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

        // Reuse an already-migrated target cluster (shared cluster scenario).
        if (isset($this->clusterRegistry[$sourceClusterId])) {
            $newClusterId = $this->clusterRegistry[$sourceClusterId];
        } else {
            try {
                $newCluster = $this->target->post('databases/clusters', [
                    'type' => $attrs['type'],
                    'name' => $attrs['name'],
                    'region' => $attrs['region'],
                    'config' => $attrs['config'] ?? [],
                ]);
                $newClusterId = $newCluster['data']['id'];
                $this->lastCreatedClusterId = $newClusterId;
            } catch (\RuntimeException) {
                // Already exists — find it in target by name (retry or shared-cluster scenario).
                $existing = $this->target->getAll('databases/clusters');
                $match = null;
                foreach ($existing as $c) {
                    if (($c['attributes']['name'] ?? '') === $attrs['name']) {
                        $match = $c;
                        break;
                    }
                }
                if (! $match) {
                    throw new \RuntimeException("Could not create or find cluster \"{$attrs['name']}\" in target org.");
                }
                $newClusterId = $match['id'];
            }

            $this->clusterRegistry[$sourceClusterId] = $newClusterId;

            // Wait for the cluster to finish provisioning before touching its schemas.
            $waited = 0;
            while ($waited < 300) {
                $status = $this->target->get("databases/clusters/{$newClusterId}")['data']['attributes']['status'] ?? 'unknown';
                if ($status === 'available') {
                    break;
                }
                sleep(5);
                $waited += 5;
            }
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

    public function runDatabaseMigration(array $srcConn, string $srcDb, array $tgtConn, string $tgtDb, string $dbType, callable $progress, array $ignoreTables = []): void
    {
        $isPostgres = str_contains($dbType, 'pgsql') || str_contains($dbType, 'postgres');

        if ($isPostgres) {
            $dumpBin = $this->findBinary('pg_dump');
            $importBin = $this->findBinary('psql');
            if (! $dumpBin || ! $importBin) {
                throw new \RuntimeException('pg_dump/psql not found — install PostgreSQL client tools and retry.');
            }

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
            $dumpCmd = escapeshellarg($dumpBin).' '.escapeshellarg($srcDsn);
            $importCmd = escapeshellarg($importBin).' '.escapeshellarg($tgtDsn);

            $progress("Dumping {$srcDb} → {$tgtDb}...");

            $mysqlProc = proc_open($importCmd, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $mysqlPipes);

            if (! is_resource($mysqlProc)) {
                throw new \RuntimeException('Failed to start psql import process.');
            }

            $dumpProc = proc_open($dumpCmd, [
                0 => ['pipe', 'r'],
                1 => $mysqlPipes[0],
                2 => ['pipe', 'w'],
            ], $dumpPipes);

            if (! is_resource($dumpProc)) {
                proc_close($mysqlProc);
                throw new \RuntimeException('Failed to start pg_dump process.');
            }

            fclose($dumpPipes[0]);
            $dumpStderr = stream_get_contents($dumpPipes[2]);
            fclose($dumpPipes[2]);
            $dumpExit = proc_close($dumpProc);

            $mysqlStderr = stream_get_contents($mysqlPipes[2]);
            fclose($mysqlPipes[1]);
            fclose($mysqlPipes[2]);
            $mysqlExit = proc_close($mysqlProc);

            if ($dumpExit !== 0) {
                throw new \RuntimeException("pg_dump failed (exit {$dumpExit}): ".trim($dumpStderr));
            }
            if ($mysqlExit !== 0) {
                throw new \RuntimeException("psql import failed (exit {$mysqlExit}): ".trim($mysqlStderr));
            }

            $progress("Data migrated: {$srcDb}");
        } else {
            $dumpBin = $this->findBinary('mysqldump');
            $importBin = $this->findBinary('mysql');
            if (! $dumpBin || ! $importBin) {
                throw new \RuntimeException('mysqldump/mysql not found — install MySQL client tools and retry.');
            }

            $this->runMysqlMigration($dumpBin, $importBin, $srcConn, $srcDb, $tgtConn, $tgtDb, $progress, $ignoreTables);
        }
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
    ): void {
        $tmpDir = sys_get_temp_dir().'/cloud_migrator_'.uniqid();
        mkdir($tmpDir, 0700, true);

        // Options file to disable server-side query timeout for mysqldump.
        $optFile = $tmpDir.'/my.cnf';
        file_put_contents($optFile, "[mysqldump]\ninit-command=SET SESSION MAX_EXECUTION_TIME=0\n");

        try {
            // ── Phase 1: schema dump (no data) ──────────────────────────────
            $progress("Dumping schema for {$srcDb}...");

            $schemaFile = $tmpDir.'/schema.sql';
            $schemaErrFile = $tmpDir.'/schema.err';

            $schemaDumpCmd = escapeshellarg($dumpBin)
                .' --defaults-extra-file='.escapeshellarg($optFile)
                .' --no-data'
                .' --add-drop-table'
                .' --no-tablespaces'
                .' --ssl-mode=DISABLED'
                .' -h '.escapeshellarg($srcConn['hostname'])
                .' -P '.(int) $srcConn['port']
                .' -u '.escapeshellarg($srcConn['username'])
                .' --password='.escapeshellarg($srcConn['password'])
                .' '.escapeshellarg($srcDb);

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

            $schemaImportCmd = escapeshellarg($importBin)
                .' --ssl-mode=DISABLED'
                .' --max-allowed-packet=64M'
                .' --init-command='.escapeshellarg('SET SESSION foreign_key_checks=0')
                .' -h '.escapeshellarg($tgtConn['hostname'])
                .' -P '.(int) $tgtConn['port']
                .' -u '.escapeshellarg($tgtConn['username'])
                .' --password='.escapeshellarg($tgtConn['password'])
                .' '.escapeshellarg($tgtDb);

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

            // ── Phase 2: parallel per-table data dumps ───────────────────────
            $tables = $this->getSourceTables($srcConn, $srcDb, $ignoreTables);
            $total = count($tables);

            if ($ignoreTables) {
                $progress('  Excluding tables: '.implode(', ', $ignoreTables));
            }

            $progress("  Migrating {$total} table(s) with concurrency={$concurrency}...");

            $this->runParallelTableDumps(
                $dumpBin, $importBin, $optFile,
                $srcConn, $srcDb, $tgtConn, $tgtDb,
                $tables, $tmpDir, $concurrency, $progress,
            );

            $progress("Data migrated: {$srcDb}");

        } finally {
            foreach (glob($tmpDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($tmpDir);
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

    /** Return tables in the given schema ordered largest-first, excluding $ignoreTables. */
    private function getSourceTables(array $conn, string $dbName, array $ignoreTables = []): array
    {
        $mysql = $this->findBinary('mysql');
        if (! $mysql) {
            return [];
        }

        $ignoreClause = '';
        if ($ignoreTables) {
            $quoted = implode(',', array_map(fn ($t) => "'".addslashes($t)."'", $ignoreTables));
            $ignoreClause = " AND TABLE_NAME NOT IN ({$quoted})";
        }

        $sql = 'SELECT TABLE_NAME FROM information_schema.TABLES'
            ." WHERE TABLE_SCHEMA='".addslashes($dbName)."'"
            ." AND TABLE_TYPE='BASE TABLE'"
            .$ignoreClause
            .' ORDER BY DATA_LENGTH DESC, TABLE_NAME';

        $cmd = escapeshellarg($mysql)
            .' --ssl-mode=DISABLED'
            .' --connect-timeout=10'
            .' --batch --skip-column-names'
            .' -h '.escapeshellarg($conn['hostname'])
            .' -P '.(int) $conn['port']
            .' -u '.escapeshellarg($conn['username'])
            .' --password='.escapeshellarg($conn['password'])
            .' -e '.escapeshellarg($sql)
            .' 2>/dev/null';

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            return [];
        }

        return array_values(array_filter(array_map('trim', $output)));
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
        $baseImportCmd = escapeshellarg($importBin)
            .' --force'
            .' --max-allowed-packet=64M'
            .' --ssl-mode=DISABLED'
            .' --init-command='.escapeshellarg('SET SESSION foreign_key_checks=0, wait_timeout=28800, net_read_timeout=3600, net_write_timeout=3600')
            .' -h '.escapeshellarg($tgtConn['hostname'])
            .' -P '.(int) $tgtConn['port']
            .' -u '.escapeshellarg($tgtConn['username'])
            .' --password='.escapeshellarg($tgtConn['password'])
            .' '.escapeshellarg($tgtDb);

        $pending = array_values($tables);
        $running = [];  // table → ['phase', 'proc', 'sqlFile', 'dumpErrFile', 'importErrFile']
        $completed = 0;
        $total = count($tables);
        $failedTables = [];

        while (! empty($pending) || ! empty($running)) {
            // Start new dump workers up to the concurrency cap.
            while (count($running) < $concurrency && ! empty($pending)) {
                $table = array_shift($pending);
                $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', $table);
                $sqlFile = $tmpDir.'/'.$safe.'.sql';
                $dumpErrFile = $tmpDir.'/'.$safe.'.dump.err';

                $dumpCmd = escapeshellarg($dumpBin)
                    .' --defaults-extra-file='.escapeshellarg($optFile)
                    .' --single-transaction'
                    .' --no-tablespaces'
                    .' --no-create-info'   // schema already imported in phase 1
                    .' --max-allowed-packet=64M'
                    .' --ssl-mode=DISABLED'
                    .' -h '.escapeshellarg($srcConn['hostname'])
                    .' -P '.(int) $srcConn['port']
                    .' -u '.escapeshellarg($srcConn['username'])
                    .' --password='.escapeshellarg($srcConn['password'])
                    .' '.escapeshellarg($srcDb)
                    .' '.escapeshellarg($table);

                $proc = proc_open($dumpCmd, [
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

            // Poll each running worker.
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
                        // Transition: start import immediately.
                        $importErrFile = $tmpDir.'/'.preg_replace('/[^a-zA-Z0-9_]/', '_', $table).'.import.err';
                        $importProc = proc_open($baseImportCmd, [
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
                        $progress("  ✓ {$table} ({$completed}/{$total})");

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

                    unset($running[$table]);
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

    private function migrateCache(array $cacheData): ?string
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
            } catch (\RuntimeException) {
                // Already exists — find it in target by name (retry or shared-cache scenario).
                $existing = $this->target->getAll('caches');
                $match = null;
                foreach ($existing as $c) {
                    if (($c['attributes']['name'] ?? '') === $attrs['name']) {
                        $match = $c;
                        break;
                    }
                }
                $newCacheId = $match['id'] ?? null;
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
