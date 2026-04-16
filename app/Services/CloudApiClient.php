<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use RuntimeException;

class CloudApiClient
{
    private Client $http;

    public function __construct(private readonly string $token)
    {
        $this->http = new Client([
            'base_uri' => 'https://cloud.laravel.com/api/',
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function get(string $path, array $query = []): array
    {
        try {
            $response = $this->http->get($path, ['query' => $query]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (ClientException $e) {
            $this->throwApiError($e);
        }
    }

    public function getAll(string $path, array $query = []): array
    {
        $items = [];
        $query['page[size]'] = 100;

        do {
            $response = $this->get($path, $query);
            $items = array_merge($items, $response['data'] ?? []);
            $nextUrl = $response['links']['next'] ?? null;

            if ($nextUrl) {
                parse_str(parse_url($nextUrl, PHP_URL_QUERY), $query);
            }
        } while ($nextUrl);

        return $items;
    }

    public function post(string $path, array $data): array
    {
        try {
            $response = $this->http->post($path, ['json' => $data]);

            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (ClientException $e) {
            $this->throwApiError($e);
        }
    }

    public function patch(string $path, array $data): array
    {
        try {
            $response = $this->http->patch($path, ['json' => $data]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (ClientException $e) {
            $this->throwApiError($e);
        }
    }

    public function delete(string $path): void
    {
        try {
            $this->http->delete($path);
        } catch (ClientException $e) {
            $this->throwApiError($e);
        }
    }

    private function throwApiError(ClientException $e): never
    {
        $body = json_decode($e->getResponse()->getBody()->getContents(), true);
        $message = $body['message'] ?? $e->getMessage();

        throw new RuntimeException("API Error [{$e->getResponse()->getStatusCode()}]: {$message}");
    }
}
