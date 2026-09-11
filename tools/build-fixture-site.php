<?php

declare(strict_types=1);

/**
 * Writes a large, realistically lumpy population of entries into a Statamic
 * site, to run lifecycle:baseline against at the scale the spec worries
 * about (§2: day one on a 40,000-page site).
 *
 *     php tools/build-fixture-site.php --site=/path/to/site [--count=40000] [--seed=20260911]
 *
 * Files only — no Statamic is booted, so 40,000 entries take seconds. The
 * site's own users are used as the pool of last authors. A manifest of what
 * was written goes to storage/lifecycle/fixture-manifest.csv for
 * verify-spread.php to join against. Remove it all with remove-fixture-site.php.
 */

use Bpmore\StatamicLifecycle\Tests\Support\FixtureSite;

require dirname(__DIR__).'/vendor/autoload.php';

$options = getopt('', ['site:', 'count::', 'seed::']);
$site = rtrim((string) ($options['site'] ?? ''), '/');
$count = (int) ($options['count'] ?? 40_000);
$seed = (int) ($options['seed'] ?? 20260911);

if ($site === '' || ! is_dir("{$site}/content/collections")) {
    fwrite(STDERR, "Pass --site=/path/to/a/statamic/site\n");
    exit(2);
}

$authors = [];
foreach (glob("{$site}/users/*.yaml") ?: [] as $file) {
    if (preg_match('/^id:\s*(\S+)/m', (string) file_get_contents($file), $m)) {
        $authors[] = $m[1];
    }
}

$now = new DateTimeImmutable;
$written = ['published' => 0, 'draft' => 0, 'hard_dated' => 0, 'with_author' => 0];
$perCollection = [];

foreach (FixtureSite::COLLECTIONS as $handle => $collection) {
    file_put_contents("{$site}/content/collections/{$handle}.yaml", "title: '{$collection['title']}'\nroute: '/fixtures/{$handle}/{slug}'\n");
    if (! is_dir("{$site}/content/collections/{$handle}")) {
        mkdir("{$site}/content/collections/{$handle}", 0755, true);
    }
}

if (! is_dir("{$site}/storage/lifecycle")) {
    mkdir("{$site}/storage/lifecycle", 0755, true);
    file_put_contents("{$site}/storage/lifecycle/.gitignore", "*\n!.gitignore\n");
}

$manifest = fopen("{$site}/storage/lifecycle/fixture-manifest.csv", 'w');
fputcsv($manifest, ['id', 'collection', 'updated_at', 'updated_by', 'published', 'review_interval', 'review_by'], escape: '');

foreach (FixtureSite::entries($count, $now, $authors, $seed) as $entry) {
    file_put_contents("{$site}/content/collections/{$entry['collection']}/{$entry['slug']}.md", FixtureSite::fileContents($entry));
    fputcsv($manifest, [$entry['id'], $entry['collection'], $entry['updated_at'], $entry['updated_by'] ?? '', $entry['published'] ? 1 : 0, $entry['review_interval'] ?? '', $entry['review_by'] ?? ''], escape: '');

    $written[$entry['published'] ? 'published' : 'draft']++;
    $written['hard_dated'] += $entry['review_by'] !== null ? 1 : 0;
    $written['with_author'] += $entry['updated_by'] !== null ? 1 : 0;
    $perCollection[$entry['collection']] = ($perCollection[$entry['collection']] ?? 0) + 1;
}

fclose($manifest);

printf("Wrote %s entries into %s (seed %d):\n", number_format($count), $site, $seed);
foreach ($perCollection as $handle => $n) {
    printf("  %-18s %s\n", $handle, number_format($n));
}
printf("  published %s · drafts %s · with review_by %s · with last author %s (pool of %d)\n",
    number_format($written['published']), number_format($written['draft']), number_format($written['hard_dated']), number_format($written['with_author']), count($authors));
echo "Manifest: {$site}/storage/lifecycle/fixture-manifest.csv\n";
echo "Next: cd {$site} && php please stache:warm && php please lifecycle:baseline --dry-run\n";
