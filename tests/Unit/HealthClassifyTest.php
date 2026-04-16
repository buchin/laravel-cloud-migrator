<?php

use App\Commands\HealthCommand;

function healthClassify(int $code): array
{
    $cmd = new ReflectionClass(HealthCommand::class);
    $method = $cmd->getMethod('classify');
    $method->setAccessible(true);

    return $method->invoke($cmd->newInstanceWithoutConstructor(), $code);
}

test('2xx codes are green', function () {
    foreach ([200, 201, 204, 299] as $code) {
        [$icon, $color] = healthClassify($code);
        expect($icon)->toBe('✓')->and($color)->toBe('green');
    }
});

test('3xx codes are cyan redirects', function () {
    foreach ([301, 302, 307, 308] as $code) {
        [$icon, $color] = healthClassify($code);
        expect($icon)->toBe('↪')->and($color)->toBe('cyan');
    }
});

test('401 and 403 are yellow lock', function () {
    foreach ([401, 403] as $code) {
        [$icon, $color] = healthClassify($code);
        expect($icon)->toBe('🔒')->and($color)->toBe('yellow');
    }
});

test('other 4xx codes are yellow warning', function () {
    foreach ([400, 404, 422, 429] as $code) {
        [$icon, $color] = healthClassify($code);
        expect($icon)->toBe('⚠')->and($color)->toBe('yellow');
    }
});

test('5xx codes are red', function () {
    foreach ([500, 502, 503] as $code) {
        [$icon, $color] = healthClassify($code);
        expect($icon)->toBe('✗')->and($color)->toBe('red');
    }
});
