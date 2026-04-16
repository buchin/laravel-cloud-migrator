<?php

use App\Services\CloudApiClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

function makeClient(array $responses): CloudApiClient
{
    $mock = new MockHandler($responses);
    $client = new CloudApiClient('test-token');

    // Inject mock HTTP client via reflection.
    $http = new Client(['handler' => HandlerStack::create($mock)]);
    $ref = new ReflectionProperty(CloudApiClient::class, 'http');
    $ref->setAccessible(true);
    $ref->setValue($client, $http);

    return $client;
}

test('get returns decoded JSON', function () {
    $client = makeClient([
        new Response(200, [], json_encode(['data' => ['id' => '1']])),
    ]);

    expect($client->get('applications'))->toBe(['data' => ['id' => '1']]);
});

test('get throws RuntimeException on 4xx', function () {
    $client = makeClient([
        new Response(401, [], json_encode(['message' => 'Unauthenticated.'])),
    ]);

    expect(fn () => $client->get('applications'))
        ->toThrow(RuntimeException::class, 'API Error [401]: Unauthenticated.');
});

test('getAll collects all pages', function () {
    $client = makeClient([
        new Response(200, [], json_encode([
            'data' => [['id' => '1'], ['id' => '2']],
            'links' => ['next' => 'https://cloud.laravel.com/api/applications?page=2'],
        ])),
        new Response(200, [], json_encode([
            'data' => [['id' => '3']],
            'links' => ['next' => null],
        ])),
    ]);

    expect($client->getAll('applications'))->toHaveCount(3);
});

test('getAll returns empty array when data is missing', function () {
    $client = makeClient([
        new Response(200, [], json_encode(['data' => [], 'links' => ['next' => null]])),
    ]);

    expect($client->getAll('applications'))->toBe([]);
});

test('post returns decoded response', function () {
    $client = makeClient([
        new Response(201, [], json_encode(['data' => ['id' => 'app-123']])),
    ]);

    expect($client->post('applications', ['name' => 'test']))->toBe(['data' => ['id' => 'app-123']]);
});

test('delete does not throw on success', function () {
    $client = makeClient([new Response(204)]);

    expect(fn () => $client->delete('applications/app-123'))->not->toThrow(RuntimeException::class);
});

test('delete throws RuntimeException on 4xx', function () {
    $client = makeClient([
        new Response(404, [], json_encode(['message' => 'Not found.'])),
    ]);

    expect(fn () => $client->delete('applications/app-123'))
        ->toThrow(RuntimeException::class, 'API Error [404]: Not found.');
});
