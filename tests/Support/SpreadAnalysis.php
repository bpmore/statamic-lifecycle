<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Tests\Support;

use DateTimeImmutable;

/**
 * Whether a baseline actually spread the backlog.
 *
 * Given every baselined row joined to when its entry was last modified, this
 * asks the questions the spec asks (§2): is nothing overdue on day one, is
 * nothing past the horizon, does the oldest content come first, and — the one
 * that matters — is any day carrying a pile while others carry nothing.
 *
 * Rows are grouped by window, the way the planner grouped them, because a
 * quarterly group spread across 90 days and a yearly group across 365 are
 * each even on their own terms and lumpy when looked at together.
 */
final class SpreadAnalysis
{
    /** A day may carry at most this many times the group's average before it counts as a pile. */
    public const MAX_OVER_MEAN = 1.5;

    /**
     * @param  iterable<array{id: string, interval_days: int, last_modified: int, next_review: string, baseline: ?string}>  $rows  next_review and baseline as Y-m-d
     * @return array<int, array{window: int, count: int, days_used: int, days_expected: int, min_per_day: int, max_per_day: int, mean_per_day: float, max_over_mean: float, overdue: int, beyond_horizon: int, order_violations: int, by_month: array<string, int>}>
     */
    public static function byWindow(iterable $rows, int $horizonDays, DateTimeImmutable $today): array
    {
        $today = $today->setTime(0, 0);
        $horizonEnd = $today->modify("+{$horizonDays} days");

        $groups = [];
        foreach ($rows as $row) {
            if ($row['baseline'] === null) {
                continue;   // a hard-dated entry: baselined, not spread
            }
            $groups[min($horizonDays, $row['interval_days'])][] = $row;
        }
        ksort($groups);

        $report = [];

        foreach ($groups as $window => $rows) {
            usort($rows, static fn (array $a, array $b): int => [$a['last_modified'], $a['id']] <=> [$b['last_modified'], $b['id']]);

            $perDay = [];
            $byMonth = [];
            $overdue = 0;
            $beyond = 0;
            $violations = 0;
            $previous = null;

            foreach ($rows as $row) {
                $date = new DateTimeImmutable($row['next_review']);
                $perDay[$row['next_review']] = ($perDay[$row['next_review']] ?? 0) + 1;
                $byMonth[$date->format('Y-m')] = ($byMonth[$date->format('Y-m')] ?? 0) + 1;

                if ($date <= $today) {
                    $overdue++;
                }
                if ($date > $horizonEnd) {
                    $beyond++;
                }
                if ($previous !== null && $row['next_review'] < $previous) {
                    $violations++;
                }
                $previous = $row['next_review'];
            }

            ksort($byMonth);
            $count = count($rows);
            $mean = $count / max(1, count($perDay));

            $report[$window] = [
                'window' => $window,
                'count' => $count,
                'days_used' => count($perDay),
                'days_expected' => min($window, $count),
                'min_per_day' => min($perDay),
                'max_per_day' => max($perDay),
                'mean_per_day' => round($mean, 2),
                'max_over_mean' => round(max($perDay) / $mean, 3),
                'overdue' => $overdue,
                'beyond_horizon' => $beyond,
                'order_violations' => $violations,
                'by_month' => $byMonth,
            ];
        }

        return $report;
    }

    /**
     * Every way a group can have failed, in words. Empty means it spread.
     *
     * @param  array{window: int, count: int, days_used: int, days_expected: int, max_over_mean: float, overdue: int, beyond_horizon: int, order_violations: int}  $group
     * @return list<string>
     */
    public static function problems(array $group): array
    {
        $problems = [];

        if ($group['overdue'] > 0) {
            $problems[] = "{$group['overdue']} overdue on day one";
        }
        if ($group['beyond_horizon'] > 0) {
            $problems[] = "{$group['beyond_horizon']} past the horizon";
        }
        if ($group['days_used'] < $group['days_expected']) {
            $problems[] = "only {$group['days_used']} of {$group['days_expected']} days used";
        }
        if ($group['max_over_mean'] > self::MAX_OVER_MEAN) {
            $problems[] = "busiest day carries {$group['max_over_mean']}× the average";
        }
        if ($group['order_violations'] > 0) {
            $problems[] = "{$group['order_violations']} entries scheduled before older content";
        }

        return $problems;
    }
}
