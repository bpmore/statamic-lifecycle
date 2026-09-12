<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Tests\TestCase;
use Symfony\Component\Process\Process;

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

/**
 * PHP files under the given directories, keyed by their path relative to the
 * repository root so a failure names the file.
 *
 * @param  list<string>  $directories
 * @return array<string, string>
 */
function phpSources(array $directories): array
{
    $root = dirname(__DIR__);
    $files = [];

    foreach ($directories as $directory) {
        $iterator = new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS);
        foreach (new RecursiveIteratorIterator($iterator) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $files[substr($file->getPathname(), strlen($root) + 1)] = $file->getPathname();
        }
    }

    ksort($files);

    return $files;
}

/**
 * The namespaced names a PHP file actually references, with comments and
 * string literals excluded.
 *
 * @return list<string>
 */
function referencedNames(string $file): array
{
    $names = [];

    foreach (token_get_all((string) file_get_contents($file)) as $token) {
        if (! is_array($token)) {
            continue;
        }
        if (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
            $names[] = ltrim($token[1], '\\');
        }
    }

    return $names;
}

/** Whether veraPDF is on this machine, so the PDF/UA validation test can run. */
function veraPdfInstalled(): bool
{
    static $installed = null;

    if ($installed === null) {
        $process = new Process(['verapdf', '--version']);
        $process->setTimeout(30);

        try {
            $process->run();
            $installed = $process->isSuccessful();
        } catch (Throwable) {
            $installed = false;
        }
    }

    return $installed;
}
