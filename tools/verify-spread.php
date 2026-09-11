<?php

declare(strict_types=1);

/**
 * Reads what lifecycle:baseline wrote for the fixture site and says whether
 * it is a spread or a pile.
 *
 *     php tools/verify-spread.php --site=/path/to/site [--horizon=12]
 *
 * Joins lifecycle_states (on the site's storage/lifecycle/lifecycle.sqlite)
 * to the manifest build-fixture-site.php left, so it knows when each entry
 * was last modified. Exits non-zero if any window group fails a check.
 */

use Bpmore\StatamicLifecycle\Tests\Support\SpreadAnalysis;

require dirname(__DIR__).'/vendor/autoload.php';

$options = getopt('', ['site:', 'horizon::']);
$site = rtrim((string) ($options['site'] ?? ''), '/');
$horizon = (int) ($options['horizon'] ?? 12);

$manifestPath = "{$site}/storage/lifecycle/fixture-manifest.csv";
$dbPath = "{$site}/storage/lifecycle/lifecycle.sqlite";

if (! is_file($manifestPath) || ! is_file($dbPath)) {
    fwrite(STDERR, "Need both {$manifestPath} and {$dbPath}. Build the fixture site, then run lifecycle:baseline.\n");
    exit(2);
}

$manifest = [];
$handle = fopen($manifestPath, 'r');
$header = fgetcsv($handle, escape: '');
while (($line = fgetcsv($handle, escape: '')) !== false) {
    $row = array_combine($header, $line);
    $manifest[$row['id']] = $row;
}
fclose($handle);

$pdo = new PDO("sqlite:{$dbPath}");
$states = [];
$query = $pdo->query("select entry_id, collection, interval_days, next_review_at, next_review_source, baseline_review_at, status, owner_source, computed_at from lifecycle_states where entry_id like 'fx-%'");
foreach ($query as $row) {
    $states[$row['entry_id']] = $row;
}

$today = (new DateTimeImmutable(min(array_column($states, 'computed_at')) ?: 'now'))->setTime(0, 0);
$horizonDays = (int) $today->diff($today->modify("+{$horizon} months"))->days;

// ---- The joins the analysis needs, and the checks it does not do ---------

$rows = [];
$missing = 0;
$draftsWithRows = 0;
$hardDateWrong = 0;
$inferred = 0;

foreach ($manifest as $id => $entry) {
    $state = $states[$id] ?? null;

    if (! (bool) $entry['published']) {
        $draftsWithRows += $state !== null ? 1 : 0;

        continue;
    }

    if ($state === null) {
        $missing++;

        continue;
    }

    if ($entry['review_by'] !== '') {
        if ($state['baseline_review_at'] !== null || substr($state['next_review_at'], 0, 10) !== $entry['review_by'] || $state['next_review_source'] !== 'hard_date') {
            $hardDateWrong++;
        }
    }

    $inferred += $state['owner_source'] === 'inferred' ? 1 : 0;

    $rows[] = [
        'id' => $id,
        'interval_days' => (int) $state['interval_days'],
        'last_modified' => (int) $entry['updated_at'],
        'next_review' => substr($state['next_review_at'], 0, 10),
        'baseline' => $state['baseline_review_at'] === null ? null : substr($state['baseline_review_at'], 0, 10),
    ];
}

$report = SpreadAnalysis::byWindow($rows, $horizonDays, $today);

// ---- Report -----------------------------------------------------------------

printf("Baseline run on %s, horizon %d months (%d days). %s fixture rows in lifecycle_states.\n\n", $today->format('Y-m-d'), $horizon, $horizonDays, number_format(count($states)));

$failed = false;

foreach ($report as $group) {
    printf("Window %d days — %s entries\n", $group['window'], number_format($group['count']));
    printf("  days used %d of %d · per day min %d / mean %.1f / max %d · busiest day %.3f× average\n",
        $group['days_used'], $group['days_expected'], $group['min_per_day'], $group['mean_per_day'], $group['max_per_day'], $group['max_over_mean']);
    printf("  overdue on day one %d · past the horizon %d · scheduled before older content %d\n",
        $group['overdue'], $group['beyond_horizon'], $group['order_violations']);

    $max = max($group['by_month']);
    foreach ($group['by_month'] as $month => $n) {
        printf("  %s  %-40s %s\n", (new DateTimeImmutable($month.'-01'))->format('M Y'), str_repeat('█', max(1, (int) round($n / $max * 40))), number_format($n));
    }

    $problems = SpreadAnalysis::problems($group);
    if ($problems !== []) {
        $failed = true;
        echo '  FAIL: '.implode('; ', $problems)."\n";
    } else {
        echo "  ok\n";
    }
    echo "\n";
}

printf("Published entries with no row: %d\n", $missing);
printf("Drafts that got a row: %d\n", $draftsWithRows);
printf("Hard-dated entries not kept on their date: %d\n", $hardDateWrong);
printf("Owners inferred from last author: %s\n", number_format($inferred));

if ($missing > 0 || $draftsWithRows > 0 || $hardDateWrong > 0) {
    $failed = true;
}

echo $failed ? "\nRESULT: FAIL\n" : "\nRESULT: spread, not clustered.\n";
exit($failed ? 1 : 0);
