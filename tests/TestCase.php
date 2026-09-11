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
}
