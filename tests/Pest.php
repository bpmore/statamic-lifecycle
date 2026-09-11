<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Tests\TestCase;

// Everything under Feature gets a booted Statamic. Anything that can be tested
// without one — the state computation, the staggering algorithm — should live
// elsewhere and stay fast.
uses(TestCase::class)->in('Feature');

/**
 * The repository root.
 *
 * Not base_path(): under Testbench that is the skeleton application, not this
 * addon. Resolved from this file, which is the one place guaranteed to be
 * loaded from where it actually lives.
 */
function repoPath(string $relative = ''): string
{
    $root = dirname(__DIR__);

    return $relative === '' ? $root : $root.'/'.$relative;
}
