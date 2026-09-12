<?php

use App\Commands\VerifyDbCommand;

// Access the private classify() method via reflection.
function classify(int $src, int $tgt): array
{
    $cmd = new ReflectionClass(VerifyDbCommand::class);
    $method = $cmd->getMethod('classify');
    $method->setAccessible(true);

    return $method->invoke($cmd->newInstanceWithoutConstructor(), $src, $tgt);
}

function isTransient(string $table): bool
{
    $cmd = new ReflectionClass(VerifyDbCommand::class);
    $method = $cmd->getMethod('isTransient');
    $method->setAccessible(true);

    return $method->invoke($cmd->newInstanceWithoutConstructor(), $table);
}

function isIgnored(string $schema, string $table, array $ignoreTables = []): bool
{
    $cmd = new ReflectionClass(VerifyDbCommand::class);
    $method = $cmd->getMethod('isIgnored');
    $method->setAccessible(true);

    return $method->invoke($cmd->newInstanceWithoutConstructor(), $schema, $table, $ignoreTables);
}

test('both zero is empty', function () {
    expect(classify(0, 0))->toBe(['·', 'gray', 'empty']);
});

test('equal counts is exact', function () {
    expect(classify(100, 100))->toBe(['✓', 'green', 'exact']);
});

test('target at 95% or above is green', function () {
    [$icon, $color] = classify(100, 95);
    expect($icon)->toBe('✓')->and($color)->toBe('green');

    [$icon, $color] = classify(100, 99);
    expect($icon)->toBe('✓')->and($color)->toBe('green');
});

test('target below 95% is yellow incomplete', function () {
    [$icon, $color, $label] = classify(100, 80);
    expect($icon)->toBe('⚠')
        ->and($color)->toBe('yellow')
        ->and($label)->toContain('incomplete');
});

test('target empty when source has rows is red', function () {
    expect(classify(100, 0))->toBe(['✗', 'red', 'target empty']);
});

test('src empty but target has data is yellow warning', function () {
    expect(classify(0, 50))->toBe(['⚠', 'yellow', 'src empty, tgt has data']);
});

test('transient tables are detected', function () {
    foreach (['jobs', 'cache', 'cache_locks', 'sessions', 'job_batches'] as $table) {
        expect(isTransient($table))->toBeTrue("Expected {$table} to be transient");
    }
});

test('non-transient tables are not detected', function () {
    foreach (['users', 'orders', 'products'] as $table) {
        expect(isTransient($table))->toBeFalse("Expected {$table} to not be transient");
    }
});

test('policy skipped tables are detected', function () {
    expect(isIgnored('nerd', 'links'))->toBeTrue();
    expect(isIgnored('dojo', 'nerd_urls'))->toBeTrue();
    expect(isIgnored('main', 'episodes'))->toBeTrue();
    expect(isIgnored('dojo', 'users'))->toBeFalse();
    expect(isIgnored('nerd', 'projects'))->toBeFalse();
});

test('cli ignoreTables are detected by schema.table or table', function () {
    expect(isIgnored('custom', 'tbl', ['tbl']))->toBeTrue();
    expect(isIgnored('custom', 'tbl', ['custom.tbl']))->toBeTrue();
    expect(isIgnored('other', 'tbl', ['custom.tbl']))->toBeFalse();
});
