<?php

namespace App\Data;

class ApplicationData
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly string $repository,
        public readonly string $region,
        public readonly ?string $sourceControlProviderType,
    ) {}

    public static function fromApi(array $data): self
    {
        $attrs = $data['attributes'];

        $repository = $attrs['repository'];

        return new self(
            id: $data['id'],
            name: $attrs['name'],
            slug: $attrs['slug'],
            repository: is_array($repository) ? $repository['full_name'] : $repository,
            region: $attrs['region'],
            sourceControlProviderType: $attrs['source_control_provider_type'] ?? null,
        );
    }
}
