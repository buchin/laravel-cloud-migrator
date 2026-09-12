<?php

use App\Commands\MigrateDbCommand;
use App\Commands\VerifyDbCommand;

test('MigrateDbCommand fails with clear error when manifest file not found', function () {
    $this->artisan('db:migrate', [
        '--source-token' => 'dummy-src',
        '--target-token' => 'dummy-tgt',
        '--manifest' => '/nonexistent/path/to/manifest.json',
        '--yes' => true,
    ])
        ->expectsOutputToContain('Specified manifest file does not exist')
        ->assertExitCode(MigrateDbCommand::FAILURE);
});

test('VerifyDbCommand fails with clear error when manifest file not found', function () {
    $this->artisan('db:verify', [
        '--source-token' => 'dummy-src',
        '--target-token' => 'dummy-tgt',
        '--manifest' => '/nonexistent/path/to/manifest.json',
    ])
        ->expectsOutputToContain('Specified manifest file does not exist')
        ->assertExitCode(VerifyDbCommand::FAILURE);
});
