<?php

namespace App\Data;

class MigrationPlan
{
    public function __construct(
        public readonly ApplicationData $application,
        /** @var EnvironmentData[] */
        public readonly array $environments,
        /** @var array[] keyed by environment id */
        public readonly array $variables,
        /** @var array[] keyed by environment id */
        public readonly array $databases,
        /** @var array[] keyed by environment id */
        public readonly array $caches,
        /** @var array[] keyed by environment id */
        public readonly array $instances,
        /** @var array[] keyed by environment id */
        public readonly array $domains = [],
        /** @var array[] org-level object storage buckets */
        public readonly array $buckets = [],
        /** @var string[] */
        public readonly array $warnings = [],
        /** @var array<string, int|null> envId → approximate row count (null = unknown) */
        public readonly array $dbRowCounts = [],
        /** @var array<string, bool> envId → has prior deployments */
        public readonly array $hasDeployments = [],
    ) {}
}
