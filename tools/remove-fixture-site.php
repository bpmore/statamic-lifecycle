<?php

declare(strict_types=1);

/**
 * Removes what build-fixture-site.php wrote: the fixture collections, their
 * entries, the manifest, and the fixture rows in lifecycle_states.
 *
 *     php tools/remove-fixture-site.php --site=/path/to/site
 */

use Bpmore\StatamicLifecycle\Tests\Support\FixtureSite;

require dirname(__DIR__).'/vendor/autoload.php';

$options = getopt('', ['site:']);
$site = rtrim((string) ($options['site'] ?? ''), '/');

if ($site === '' || ! is_dir("{$site}/content/collections")) {
    fwrite(STDERR, "Pass --site=/path/to/a/statamic/site\n");
    exit(2);
}

$removed = 0;

foreach (array_keys(FixtureSite::COLLECTIONS) as $handle) {
    $dir = "{$site}/content/collections/{$handle}";

    foreach (glob("{$dir}/*.md") ?: [] as $file) {
        unlink($file);
        $removed++;
    }
    if (is_dir($dir)) {
        rmdir($dir);
    }
    if (is_file("{$site}/content/collections/{$handle}.yaml")) {
        unlink("{$site}/content/collections/{$handle}.yaml");
    }
}

if (is_file("{$site}/storage/lifecycle/fixture-manifest.csv")) {
    unlink("{$site}/storage/lifecycle/fixture-manifest.csv");
}

$rows = 0;
if (is_file($db = "{$site}/storage/lifecycle/lifecycle.sqlite")) {
    $rows = (new PDO("sqlite:{$db}"))->exec("delete from lifecycle_states where entry_id like 'fx-%'");
}

printf("Removed %s entry files, the fixture collections, the manifest, and %s state rows.\n", number_format($removed), number_format((int) $rows));
echo "Next: cd {$site} && php please stache:clear\n";
