<?php

namespace App\Services;

use App\Data\TablePolicy;
use InvalidArgumentException;
use RuntimeException;

class MigrationManifest
{
    public const DEFAULT_FILE = 'migration-plan.json';

    private ?string $filePath = null;

    /** @var array<string, array{default_policy: string, tables: array<string, TablePolicy>}> */
    private array $databases = [];

    /** @var array<string, array<string, string>|string> */
    private array $envResolvers = [];

    /** @var array<int, array{source_bucket: string, target_bucket: string, prefix?: string, source_region?: string, target_region?: string}> */
    private array $storageBuckets = [];

    private array $raw = [];

    public function __construct(array $data = [], ?string $filePath = null)
    {
        $this->filePath = $filePath;
        $this->parse($data);
    }

    public static function fromFile(string $filePath): self
    {
        if (! file_exists($filePath)) {
            throw new RuntimeException("Migration manifest file not found: {$filePath}");
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new RuntimeException("Unable to read migration manifest file: {$filePath}");
        }

        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Invalid JSON in migration manifest: '.json_last_error_msg());
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Migration manifest JSON must represent an object.');
        }

        return new self($decoded, $filePath);
    }

    public static function fromString(string $json): self
    {
        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Invalid JSON string: '.json_last_error_msg());
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Migration manifest JSON must represent an object.');
        }

        return new self($decoded);
    }

    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    private function parse(array $data): void
    {
        $this->raw = $data;

        // Parse databases section
        $databases = $data['databases'] ?? [];
        if (! is_array($databases)) {
            throw new InvalidArgumentException("'databases' section in manifest must be an object/array.");
        }

        foreach ($databases as $dbKey => $dbConfig) {
            if (! is_array($dbConfig)) {
                throw new InvalidArgumentException("Database config for '{$dbKey}' must be an object/array.");
            }

            $defaultPolicy = $dbConfig['default_policy'] ?? TablePolicy::FULL;
            $tablesConfig = $dbConfig['tables'] ?? [];
            if (! is_array($tablesConfig)) {
                throw new InvalidArgumentException("'tables' for database '{$dbKey}' must be an object/array.");
            }

            $tablePolicies = [];
            foreach ($tablesConfig as $tableName => $tableConfig) {
                $tablePolicies[$tableName] = TablePolicy::fromConfig($tableConfig);
            }

            $this->databases[$dbKey] = [
                'default_policy' => $defaultPolicy,
                'tables' => $tablePolicies,
            ];
        }

        // Parse env_resolvers section
        $envResolvers = $data['env_resolvers'] ?? [];
        if (! is_array($envResolvers)) {
            throw new InvalidArgumentException("'env_resolvers' section in manifest must be an object/array.");
        }

        $this->envResolvers = $envResolvers;

        // Parse storage section if present
        $storage = $data['storage'] ?? [];
        if (is_array($storage)) {
            if (array_is_list($storage)) {
                foreach ($storage as $entry) {
                    if (is_array($entry) && isset($entry['source_bucket'], $entry['target_bucket'])) {
                        $this->storageBuckets[] = [
                            'source_bucket' => (string) $entry['source_bucket'],
                            'target_bucket' => (string) $entry['target_bucket'],
                            'prefix' => (string) ($entry['prefix'] ?? ''),
                            'source_region' => isset($entry['source_region']) ? (string) $entry['source_region'] : null,
                            'target_region' => isset($entry['target_region']) ? (string) $entry['target_region'] : null,
                            'source_endpoint' => isset($entry['source_endpoint']) ? (string) $entry['source_endpoint'] : null,
                            'target_endpoint' => isset($entry['target_endpoint']) ? (string) $entry['target_endpoint'] : null,
                        ];
                    }
                }
            } else {
                foreach ($storage as $src => $tgt) {
                    if (is_string($tgt)) {
                        $this->storageBuckets[] = [
                            'source_bucket' => (string) $src,
                            'target_bucket' => (string) $tgt,
                            'prefix' => '',
                            'source_region' => null,
                            'target_region' => null,
                            'source_endpoint' => null,
                            'target_endpoint' => null,
                        ];
                    } elseif (is_array($tgt) && isset($tgt['target_bucket'])) {
                        $this->storageBuckets[] = [
                            'source_bucket' => (string) $src,
                            'target_bucket' => (string) $tgt['target_bucket'],
                            'prefix' => (string) ($tgt['prefix'] ?? ''),
                            'source_region' => isset($tgt['source_region']) ? (string) $tgt['source_region'] : null,
                            'target_region' => isset($tgt['target_region']) ? (string) $tgt['target_region'] : null,
                            'source_endpoint' => isset($tgt['source_endpoint']) ? (string) $tgt['source_endpoint'] : null,
                            'target_endpoint' => isset($tgt['target_endpoint']) ? (string) $tgt['target_endpoint'] : null,
                        ];
                    }
                }
            }
        }
    }

    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    public function getRaw(): array
    {
        return $this->raw;
    }

    /**
     * Find database configuration by trying exact key match (cluster.schema)
     * or partial match on schema name.
     */
    public function getDatabaseConfig(string $schemaName, ?string $clusterName = null): ?array
    {
        if ($clusterName && isset($this->databases["{$clusterName}.{$schemaName}"])) {
            return $this->databases["{$clusterName}.{$schemaName}"];
        }

        if (isset($this->databases[$schemaName])) {
            return $this->databases[$schemaName];
        }

        // Search for any cluster.schemaName match
        foreach ($this->databases as $key => $config) {
            if (str_contains($key, '.')) {
                [, $s] = explode('.', $key, 2);
                if ($s === $schemaName) {
                    return $config;
                }
            }
        }

        return null;
    }

    /**
     * Get table policy for a specific table in a schema.
     */
    public function getTablePolicy(string $table, string $schemaName, ?string $clusterName = null): TablePolicy
    {
        $dbConfig = $this->getDatabaseConfig($schemaName, $clusterName);
        if ($dbConfig && isset($dbConfig['tables'][$table])) {
            return $dbConfig['tables'][$table];
        }

        $defaultPolicy = $dbConfig['default_policy'] ?? TablePolicy::FULL;

        return new TablePolicy(policy: $defaultPolicy);
    }

    /**
     * Check if a table has an explicit policy in the manifest.
     */
    public function hasExplicitTablePolicy(string $table, string $schemaName, ?string $clusterName = null): bool
    {
        $dbConfig = $this->getDatabaseConfig($schemaName, $clusterName);

        return $dbConfig !== null && isset($dbConfig['tables'][$table]);
    }

    /**
     * Get all explicit table policies for a given schema.
     *
     * @return array<string, TablePolicy>
     */
    public function getTablePoliciesForSchema(string $schemaName, ?string $clusterName = null): array
    {
        $dbConfig = $this->getDatabaseConfig($schemaName, $clusterName);

        return $dbConfig['tables'] ?? [];
    }

    public function isIgnored(string $table, string $schemaName, ?string $clusterName = null): bool
    {
        return $this->getTablePolicy($table, $schemaName, $clusterName)->isIgnore();
    }

    public function isSchemaOnly(string $table, string $schemaName, ?string $clusterName = null): bool
    {
        return $this->getTablePolicy($table, $schemaName, $clusterName)->isSchemaOnly();
    }

    public function isTransient(string $table, string $schemaName, ?string $clusterName = null): bool
    {
        return $this->getTablePolicy($table, $schemaName, $clusterName)->isTransient();
    }

    public function isChunked(string $table, string $schemaName, ?string $clusterName = null): bool
    {
        return $this->getTablePolicy($table, $schemaName, $clusterName)->isChunked();
    }

    /**
     * Return list of tables to ignore during data migration.
     *
     * @return string[]
     */
    public function getIgnoreTables(string $schemaName, ?string $clusterName = null): array
    {
        $policies = $this->getTablePoliciesForSchema($schemaName, $clusterName);
        $ignored = [];
        foreach ($policies as $table => $policy) {
            if ($policy->isIgnore()) {
                $ignored[] = $table;
            }
        }

        return $ignored;
    }

    /**
     * Return list of tables marked schema_only.
     *
     * @return string[]
     */
    public function getSchemaOnlyTables(string $schemaName, ?string $clusterName = null): array
    {
        $policies = $this->getTablePoliciesForSchema($schemaName, $clusterName);
        $schemaOnly = [];
        foreach ($policies as $table => $policy) {
            if ($policy->isSchemaOnly()) {
                $schemaOnly[] = $table;
            }
        }

        return $schemaOnly;
    }

    /**
     * Return list of transient tables.
     *
     * @return string[]
     */
    public function getTransientTables(string $schemaName, ?string $clusterName = null): array
    {
        $policies = $this->getTablePoliciesForSchema($schemaName, $clusterName);
        $transient = [];
        foreach ($policies as $table => $policy) {
            if ($policy->isTransient()) {
                $transient[] = $table;
            }
        }

        return $transient;
    }

    /**
     * Get environment variable resolvers/templates for an app.
     * Can return app-specific mappings or top-level mappings.
     *
     * @return array<string, string>
     */
    public function getEnvResolvers(?string $appName = null): array
    {
        if ($appName && isset($this->envResolvers[$appName]) && is_array($this->envResolvers[$appName])) {
            return $this->envResolvers[$appName];
        }

        // If top-level keys are env variables directly (e.g. "API_BASE_URL": "...")
        $directVars = [];
        foreach ($this->envResolvers as $key => $val) {
            if (is_string($val)) {
                $directVars[$key] = $val;
            }
        }

        return $directVars;
    }

    /**
     * Get storage bucket migration configurations defined in manifest.
     *
     * @return array<int, array{source_bucket: string, target_bucket: string, prefix: string, source_region: ?string, target_region: ?string, source_endpoint: ?string, target_endpoint: ?string}>
     */
    public function getStorageBuckets(): array
    {
        return $this->storageBuckets;
    }

    public function countTotalTableRules(): int
    {
        $count = 0;
        foreach ($this->databases as $db) {
            $count += count($db['tables']);
        }

        return $count;
    }
}
