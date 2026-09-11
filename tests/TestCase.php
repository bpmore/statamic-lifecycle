<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Tests;

use Bpmore\StatamicLifecycle\ServiceProvider;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

/**
 * Boots a real Statamic through Orchestra Testbench.
 *
 * PreventsSavingStacheItemsToDisk is not optional. Without it an entry or
 * collection created in one test is written to disk and still there in the
 * next — which surfaces as a later test failing on content it never created,
 * a long way from the test that actually caused it.
 */
abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    /**
     * The addon defaults to its own SQLite file so a flat-file site needs no
     * database. Tests want Testbench's in-memory one instead, which is what a
     * site with a real database gets by naming its own connection.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['lifecycle.connection' => config('database.default')]);
    }

    /**
     * The addon does not loadMigrationsFrom — see Storage\LifecycleDatabase for
     * why — so RefreshDatabase has to be told where they are.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
