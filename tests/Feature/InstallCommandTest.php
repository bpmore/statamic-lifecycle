<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use Illuminate\Support\Facades\Schema;

it('is registered under please', function () {
    // RunsInPlease is what puts it there. Without the trait the command
    // exists under artisan and the documented `php please lifecycle:install`
    // says "command not found".
    $this->artisan('list')->expectsOutputToContain('lifecycle:install');
});

it('installs onto the addon\'s own connection without asking', function () {
    // Nobody else's schema is being touched, so there is nothing to confirm.
    $scratch = scratchConnection(LifecycleDatabase::DEFAULT_CONNECTION);

    $this->artisan('lifecycle:install')
        ->expectsOutputToContain('Lifecycle tables are in place on [lifecycle_sqlite].')
        ->assertExitCode(0);

    expect(Schema::connection('lifecycle_sqlite')->hasTable('lifecycle_states'))->toBeTrue();

    removeScratch($scratch);
});

it('asks before creating tables on a connection the site administers', function () {
    $scratch = scratchConnection('lifecycle_theirs');
    mkdir($scratch['dir'], 0755, true);
    touch($scratch['file']);

    $this->artisan('lifecycle:install')
        ->expectsConfirmation('This will create tables on [lifecycle_theirs], which this site administers. Continue?', 'no')
        ->expectsOutputToContain('Nothing was changed.')
        ->assertExitCode(1);

    expect(Schema::connection('lifecycle_theirs')->hasTable('lifecycle_states'))->toBeFalse();

    $this->artisan('lifecycle:install')
        ->expectsConfirmation('This will create tables on [lifecycle_theirs], which this site administers. Continue?', 'yes')
        ->assertExitCode(0);

    expect(Schema::connection('lifecycle_theirs')->hasTable('lifecycle_states'))->toBeTrue();

    removeScratch($scratch);
});

it('does not ask when forced', function () {
    // For deploy scripts, where there is nobody to answer.
    $scratch = scratchConnection('lifecycle_forced');
    mkdir($scratch['dir'], 0755, true);
    touch($scratch['file']);

    $this->artisan('lifecycle:install', ['--force' => true])->assertExitCode(0);

    expect(Schema::connection('lifecycle_forced')->hasTable('lifecycle_states'))->toBeTrue();

    removeScratch($scratch);
});

it('says so when there is nothing to do', function () {
    $scratch = scratchConnection(LifecycleDatabase::DEFAULT_CONNECTION);

    $this->artisan('lifecycle:install')->assertExitCode(0);
    $this->artisan('lifecycle:install')
        ->expectsOutputToContain('Already installed on [lifecycle_sqlite].')
        ->assertExitCode(0);

    removeScratch($scratch);
});
