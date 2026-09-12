<?php

namespace App\Services;

use RuntimeException;

class CrossAppEnvResolver
{
    /** @var array<string, array{id: string, attributes: array, environments: array<string, array>}> */
    private array $appsCache = [];

    /** @var array<string, string|callable> */
    private array $customResolvers = [];

    public function __construct(
        private ?CloudApiClient $targetClient = null,
        private bool $strict = true,
    ) {}

    public function setTargetClient(CloudApiClient $client): self
    {
        $this->targetClient = $client;

        return $this;
    }

    public function setStrict(bool $strict): self
    {
        $this->strict = $strict;

        return $this;
    }

    public function registerApp(string $appKey, array $appData, array $environments = []): self
    {
        $envMap = [];
        foreach ($environments as $env) {
            $envName = $env['attributes']['name'] ?? $env['id'] ?? 'unknown';
            $envSlug = $env['attributes']['slug'] ?? $envName;
            $envMap[$envName] = $env;
            $envMap[$envSlug] = $env;
        }

        $record = [
            'id' => $appData['id'] ?? $appKey,
            'attributes' => $appData['attributes'] ?? $appData,
            'environments' => $envMap,
        ];

        $this->appsCache[$appKey] = $record;
        if (isset($appData['attributes']['slug'])) {
            $this->appsCache[$appData['attributes']['slug']] = $record;
        }
        if (isset($appData['attributes']['name'])) {
            $this->appsCache[$appData['attributes']['name']] = $record;
        }

        return $this;
    }

    public function setAppsCache(array $appsCache): self
    {
        $this->appsCache = $appsCache;

        return $this;
    }

    public function registerCustomResolver(string $placeholder, string|callable $resolver): self
    {
        $this->customResolvers[$placeholder] = $resolver;

        return $this;
    }

    /**
     * Resolve all placeholders in a string value.
     */
    public function resolve(string $value, array $context = []): string
    {
        $pattern = '/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/';

        return preg_replace_callback($pattern, function (array $matches) use ($context, $value) {
            $placeholder = trim($matches[1]);

            // 1. Check custom resolvers
            if (isset($this->customResolvers[$placeholder])) {
                $custom = $this->customResolvers[$placeholder];

                return is_callable($custom) ? (string) $custom($context) : (string) $custom;
            }

            // 2. Resolve target.vanity_domain
            if ($placeholder === 'target.vanity_domain' || $placeholder === 'vanity_domain') {
                $targetVanity = $this->resolveTargetVanityFromContext($context);
                if ($targetVanity !== null) {
                    return $targetVanity;
                }
            }

            // 3. Resolve context variable (e.g. {{env.APP_ENV}} or {{app}})
            if (str_starts_with($placeholder, 'env.')) {
                $envVar = substr($placeholder, 4);
                $envVal = getenv($envVar) ?: ($_ENV[$envVar] ?? null);
                if ($envVal !== null) {
                    return (string) $envVal;
                }
            }
            if (isset($context[$placeholder])) {
                return (string) $context[$placeholder];
            }

            // 4. Resolve apps.<app>...
            if (str_starts_with($placeholder, 'apps.')) {
                $resolved = $this->resolveAppPlaceholder($placeholder);
                if ($resolved !== null) {
                    return $resolved;
                }
            }

            if ($this->strict) {
                throw new RuntimeException("Could not resolve template placeholder '{{{$placeholder}}}' in '{$value}'");
            }

            return $matches[0];
        }, $value);
    }

    /**
     * Resolve array of environment variable records.
     *
     * @param  array<int, array{key: string, value: string, is_secret?: bool}>  $variables
     * @return array<int, array{key: string, value: string, is_secret?: bool}>
     */
    public function resolveVariables(array $variables, array $context = []): array
    {
        return array_map(function ($item) use ($context) {
            if (is_array($item) && isset($item['value'])) {
                $item['value'] = $this->resolve((string) $item['value'], $context);

                return $item;
            }
            if (is_string($item)) {
                return $this->resolve($item, $context);
            }

            return $item;
        }, $variables);
    }

    private function resolveAppPlaceholder(string $placeholder): ?string
    {
        $parts = explode('.', $placeholder);
        if (count($parts) < 2) {
            return null;
        }

        $appName = $parts[1];
        $app = $this->getOrFetchApp($appName);
        if (! $app) {
            return null;
        }

        // apps.<app>.id
        if (count($parts) === 3 && $parts[2] === 'id') {
            return $app['id'];
        }

        // apps.<app>.url
        if (count($parts) === 3 && $parts[2] === 'url') {
            return $this->getDefaultEnvUrl($app);
        }

        // apps.<app>.vanity_domain
        if (count($parts) === 3 && $parts[2] === 'vanity_domain') {
            return $this->getDefaultEnvVanityDomain($app);
        }

        // apps.<app>.envs.<env>.<prop>
        if (count($parts) >= 4 && $parts[2] === 'envs') {
            $envName = $parts[3];
            $prop = $parts[4] ?? 'url';

            $env = $this->getOrFetchEnv($app, $envName);
            if (! $env) {
                return null;
            }

            return match ($prop) {
                'url' => $this->formatEnvUrl($env),
                'vanity_domain', 'domain' => $env['attributes']['vanity_domain'] ?? null,
                'id' => $env['id'],
                'name' => $env['attributes']['name'] ?? null,
                'slug' => $env['attributes']['slug'] ?? null,
                default => $env['attributes'][$prop] ?? null,
            };
        }

        return null;
    }

    private function getOrFetchApp(string $nameOrSlug): ?array
    {
        if (isset($this->appsCache[$nameOrSlug])) {
            return $this->appsCache[$nameOrSlug];
        }

        if (! $this->targetClient) {
            return null;
        }

        try {
            $apps = $this->targetClient->getAll('applications');
        } catch (RuntimeException) {
            return null;
        }

        foreach ($apps as $app) {
            $slug = $app['attributes']['slug'] ?? '';
            $name = $app['attributes']['name'] ?? '';
            $record = [
                'id' => $app['id'],
                'attributes' => $app['attributes'],
                'environments' => [],
            ];
            $this->appsCache[$app['id']] = $record;
            if ($slug) {
                $this->appsCache[$slug] = $record;
            }
            if ($name) {
                $this->appsCache[$name] = $record;
            }
        }

        return $this->appsCache[$nameOrSlug] ?? null;
    }

    private function getOrFetchEnv(array &$app, string $envNameOrSlug): ?array
    {
        if (isset($app['environments'][$envNameOrSlug])) {
            return $app['environments'][$envNameOrSlug];
        }

        if (! $this->targetClient) {
            return null;
        }

        try {
            $envs = $this->targetClient->getAll("applications/{$app['id']}/environments");
        } catch (RuntimeException) {
            return null;
        }

        foreach ($envs as $env) {
            $name = $env['attributes']['name'] ?? '';
            $slug = $env['attributes']['slug'] ?? '';
            if ($name) {
                $app['environments'][$name] = $env;
            }
            if ($slug) {
                $app['environments'][$slug] = $env;
            }
            $app['environments'][$env['id']] = $env;
        }

        // Update cache references
        $this->appsCache[$app['id']] = $app;
        if (isset($app['attributes']['slug'])) {
            $this->appsCache[$app['attributes']['slug']] = $app;
        }
        if (isset($app['attributes']['name'])) {
            $this->appsCache[$app['attributes']['name']] = $app;
        }

        return $app['environments'][$envNameOrSlug] ?? null;
    }

    private function formatEnvUrl(array $env): ?string
    {
        $vanity = $env['attributes']['vanity_domain'] ?? null;
        if ($vanity) {
            return str_starts_with($vanity, 'http') ? $vanity : "https://{$vanity}";
        }

        $customDomains = $env['attributes']['custom_domains'] ?? [];
        if (! empty($customDomains)) {
            $domain = is_array($customDomains[0]) ? ($customDomains[0]['domain'] ?? '') : $customDomains[0];
            if ($domain) {
                return str_starts_with($domain, 'http') ? $domain : "https://{$domain}";
            }
        }

        $url = $env['attributes']['url'] ?? null;
        if ($url) {
            return str_starts_with($url, 'http') ? $url : "https://{$url}";
        }

        return null;
    }

    private function getDefaultEnvUrl(array &$app): ?string
    {
        $env = $this->getDefaultEnv($app);

        return $env ? $this->formatEnvUrl($env) : null;
    }

    private function getDefaultEnvVanityDomain(array &$app): ?string
    {
        $env = $this->getDefaultEnv($app);

        return $env['attributes']['vanity_domain'] ?? null;
    }

    private function getDefaultEnv(array &$app): ?array
    {
        foreach (['production', 'main'] as $candidate) {
            $env = $this->getOrFetchEnv($app, $candidate);
            if ($env) {
                return $env;
            }
        }

        if (empty($app['environments']) && $this->targetClient) {
            $this->getOrFetchEnv($app, '__trigger_fetch__');
        }

        if (! empty($app['environments'])) {
            return reset($app['environments']);
        }

        return null;
    }

    private function resolveTargetVanityFromContext(array $context): ?string
    {
        $appSlug = $context['app'] ?? $context['appName'] ?? null;
        if (! $appSlug) {
            return null;
        }

        $app = $this->getOrFetchApp($appSlug);
        if (! $app) {
            return null;
        }

        $envName = $context['env'] ?? $context['envSlug'] ?? 'production';
        $env = $this->getOrFetchEnv($app, $envName) ?? $this->getDefaultEnv($app);

        return $env['attributes']['vanity_domain'] ?? null;
    }

    /**
     * Helper to resolve source vanity domain host to target vanity domain host.
     */
    public function resolveVanityDomain(string $sourceHost): ?string
    {
        if (preg_match('/^([a-z0-9-]+)\.laravel\.cloud$/', $sourceHost, $m)) {
            $appName = $m[1];
            $app = $this->getOrFetchApp($appName);
            if ($app) {
                return $this->getDefaultEnvVanityDomain($app);
            }
        }

        return null;
    }
}
