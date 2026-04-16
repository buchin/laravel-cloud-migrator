<?php

namespace App\Data;

class EnvironmentData
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly string $branch,
        public readonly ?string $phpVersion,
        public readonly ?string $nodeVersion,
        public readonly ?string $buildCommand,
        public readonly ?string $deployCommand,
        public readonly bool $usesOctane,
        public readonly ?string $databaseSchemaId,
        public readonly ?string $cacheId,
        public readonly array $variables,
        public readonly array $instances,
    ) {}

    public static function fromApi(array $data): self
    {
        $attrs = $data['attributes'];
        $relationships = $data['relationships'] ?? [];

        return new self(
            id: $data['id'],
            name: $attrs['name'],
            slug: $attrs['slug'],
            branch: $attrs['branch'] ?? 'main',
            phpVersion: $attrs['php_version'] ?? null,
            nodeVersion: $attrs['node_version'] ?? null,
            buildCommand: $attrs['build_command'] ?? null,
            deployCommand: $attrs['deploy_command'] ?? null,
            usesOctane: $attrs['uses_octane'] ?? false,
            databaseSchemaId: $relationships['database']['data']['id'] ?? null,
            cacheId: $relationships['cache']['data']['id'] ?? null,
            variables: [],
            instances: [],
        );
    }
}
