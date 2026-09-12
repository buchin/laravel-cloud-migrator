<?php

namespace App\Services;

use App\Data\HealthCheckItem;

class HealthService
{
    /** @var callable */
    private $prober;

    public function __construct(
        private ?CloudApiClient $client = null,
        private int $timeout = 10,
        ?callable $prober = null,
    ) {
        $this->prober = $prober ?? function (string $url, int $timeout): array {
            return $this->defaultProbe($url, $timeout);
        };
    }

    public function setClient(CloudApiClient $client): self
    {
        $this->client = $client;

        return $this;
    }

    public function getClient(): ?CloudApiClient
    {
        return $this->client;
    }

    public function setProber(callable $prober): self
    {
        $this->prober = $prober;

        return $this;
    }

    public function setTimeout(int $timeout): self
    {
        $this->timeout = max(1, $timeout);

        return $this;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * Probe a URL using configured prober.
     *
     * @return array{0: ?int, 1: string, 2: int} [statusCode, finalUrl, latencyMs]
     */
    public function probe(string $url, ?int $timeout = null): array
    {
        return ($this->prober)($url, $timeout ?? $this->timeout);
    }

    /**
     * Resolve target URL for an environment.
     */
    public function resolveUrl(array $env): ?string
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

    /**
     * Check single environment health.
     */
    public function checkEnvironment(array $app, array $env, ?int $timeout = null): HealthCheckItem
    {
        $appName = $app['attributes']['name'] ?? $app['id'];
        $appSlug = $app['attributes']['slug'] ?? $appName;
        $envName = $env['attributes']['name'] ?? $env['id'];
        $envSlug = $env['attributes']['slug'] ?? $envName;

        $url = $this->resolveUrl($env);

        if (! $url) {
            return new HealthCheckItem(
                appName: $appName,
                appSlug: $appSlug,
                envName: $envName,
                envSlug: $envSlug,
                url: '',
                statusCode: null,
                finalUrl: null,
                responseTimeMs: 0,
                status: 'warning',
                message: 'No URL or vanity domain available',
            );
        }

        [$code, $finalUrl, $ms] = $this->probe($url, $timeout ?? $this->timeout);

        [, , $status] = self::classify($code);

        $message = match ($status) {
            'healthy' => 'HTTP 200 OK',
            'redirect' => "Redirected to {$finalUrl}",
            'warning' => "HTTP {$code}",
            'unhealthy' => $code ? "HTTP {$code} error" : 'Unreachable / connection timed out',
            default => "HTTP {$code}",
        };

        return new HealthCheckItem(
            appName: $appName,
            appSlug: $appSlug,
            envName: $envName,
            envSlug: $envSlug,
            url: $url,
            statusCode: $code,
            finalUrl: $finalUrl,
            responseTimeMs: $ms,
            status: $status,
            message: $message,
        );
    }

    /**
     * Classify HTTP status code.
     *
     * @return array{0: string, 1: string, 2: string} [icon, color, status]
     */
    public static function classify(?int $code): array
    {
        if ($code === null) {
            return ['✗', 'red', 'unhealthy'];
        }

        return match (true) {
            $code >= 200 && $code < 300 => ['✓', 'green', 'healthy'],
            $code >= 300 && $code < 400 => ['↪', 'cyan', 'redirect'],
            $code === 401, $code === 403 => ['🔒', 'yellow', 'warning'],
            $code >= 400 && $code < 500 => ['⚠', 'yellow', 'warning'],
            default => ['✗', 'red', 'unhealthy'],
        };
    }

    /**
     * Default curl-based probe implementation.
     *
     * @return array{0: ?int, 1: string, 2: int}
     */
    private function defaultProbe(string $url, int $timeout): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'laravel-cloud-migrator/health',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADER => false,
            CURLOPT_NOBODY => false,
        ]);

        $start = microtime(true);
        curl_exec($ch);
        $ms = (int) round((microtime(true) - $start) * 1000);

        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: null;
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0) {
            return [null, $url, $ms];
        }

        return [$code, $finalUrl, $ms];
    }
}
