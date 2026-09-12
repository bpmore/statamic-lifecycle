<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Reporting;

use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\Models\Review;
use Bpmore\StatamicLifecycle\Reviews\Outcome;
use Bpmore\StatamicLifecycle\State\OwnerSource;
use Bpmore\StatamicLifecycle\State\Status;
use DateTimeImmutable;

/**
 * The governance report's numbers (spec §4): the per-site answer to the
 * questions an audit, a records request or an accreditation review asks.
 *
 *   How much content has a confirmed owner — a person named, not guessed?
 *   How much was reviewed by a person in the last twelve months?
 *   Where is the overdue content: which collection, which site, whose?
 *   How much reviewing is actually happening, month by month?
 *   What has never been reviewed since it was baselined?
 *
 * Read from the tables in grouped queries; nothing here walks entries or
 * computes state. The same numbers feed the console, JSON, CSV, the HTML
 * report and the PDF printed from it, so the formats cannot disagree.
 */
final class GovernanceReport
{
    /** Reviewed "recently" means within this many months. */
    public const RECENT_MONTHS = 12;

    /** How many months of review activity the report shows. */
    public const ACTIVITY_MONTHS = 12;

    public function __construct(private readonly Overview $overview) {}

    /** @return array<string, mixed> */
    public function build(DateTimeImmutable $now): array
    {
        $statuses = $this->overview->statuses();
        $total = array_sum($statuses);
        $exempt = $statuses[Status::Exempt->value];
        $underReview = $total - $exempt;
        $late = $statuses[Status::Overdue->value] + $statuses[Status::Expired->value];

        $owners = $this->owners($total);
        $reviews = $this->reviews($underReview, $now);

        return [
            'generated_at' => $now,
            'total' => $total,
            'exempt' => $exempt,
            'under_review' => $underReview,
            'statuses' => $statuses,
            'late' => $late,
            'owners' => $owners,
            'reviews' => $reviews,
            'headline' => $this->headline($total, $underReview, $late, $owners, $reviews),
            'by_collection' => $this->breakdown($this->overview->byCollection()),
            'by_site' => $this->breakdown($this->overview->bySite()),
            'by_owner' => $this->breakdown($this->overview->byOwner()),
            'activity' => $this->activity($now),
        ];
    }

    /**
     * Who owns the content, and how sure we are: named on the page, guessed
     * from the last author, the owner of last resort, or nobody at all.
     *
     * @return array<string, mixed>
     */
    private function owners(int $total): array
    {
        $bySource = array_fill_keys(array_map(fn (OwnerSource $s): string => $s->value, OwnerSource::cases()), 0);

        foreach (EntryState::query()->whereNotNull('owner_id')->selectRaw('owner_source, count(*) as n')->groupBy('owner_source')->pluck('n', 'owner_source') as $source => $n) {
            if ($source !== null) {
                $bySource[$source] = (int) $n;
            }
        }

        $confirmed = $bySource[OwnerSource::Assigned->value];

        return [
            'confirmed' => $confirmed,
            'inferred' => $bySource[OwnerSource::Inferred->value],
            'fallback' => $bySource[OwnerSource::Fallback->value],
            'nobody' => $total - array_sum($bySource),
            'confirmed_share' => self::share($confirmed, $total),
        ];
    }

    /** @return array<string, mixed> */
    private function reviews(int $underReview, DateTimeImmutable $now): array
    {
        $since = $now->modify('-'.self::RECENT_MONTHS.' months');

        $recent = (int) EntryState::query()
            ->where('status', '!=', Status::Exempt->value)
            ->where('last_reviewed_at', '>=', $since->format('Y-m-d H:i:s'))
            ->count();

        $never = (int) EntryState::query()
            ->where('status', '!=', Status::Exempt->value)
            ->whereNull('last_reviewed_at')
            ->count();

        $neverSinceBaseline = (int) EntryState::query()
            ->where('status', '!=', Status::Exempt->value)
            ->whereNull('last_reviewed_at')
            ->where('baselined', true)
            ->count();

        return [
            'recent' => $recent,
            'recent_share' => self::share($recent, $underReview),
            'stale' => $underReview - $recent - $never,
            'never' => $never,
            'never_since_baseline' => $neverSinceBaseline,
        ];
    }

    /**
     * One row per group, with the late count and its share, for the three
     * "where is the overdue content" tables.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function breakdown(array $rows): array
    {
        return array_map(function (array $row): array {
            $late = $row[Status::Overdue->value] + $row[Status::Expired->value];

            return [
                'key' => $row['key'],
                'label' => $row['label'],
                'total' => $row['total'],
                'overdue' => $row[Status::Overdue->value],
                'expired' => $row[Status::Expired->value],
                'late' => $late,
                'late_share' => self::share($late, $row['total'] - $row[Status::Exempt->value]),
                'never_reviewed' => $row['never_reviewed'],
                'exempt' => $row[Status::Exempt->value],
            ];
        }, $rows);
    }

    /**
     * Reviews recorded per month, oldest first, with the outcomes people
     * chose. Twelve rows, whether or not anything happened in a month: a
     * quiet month is part of the picture.
     *
     * @return list<array<string, mixed>>
     */
    private function activity(DateTimeImmutable $now): array
    {
        $months = [];
        $start = $now->modify('first day of this month')->setTime(0, 0)->modify('-'.(self::ACTIVITY_MONTHS - 1).' months');

        for ($i = 0; $i < self::ACTIVITY_MONTHS; $i++) {
            $from = $start->modify("+{$i} months");
            $to = $from->modify('+1 month');

            $outcomes = array_fill_keys(array_map(fn (Outcome $o): string => $o->value, Outcome::cases()), 0);

            $counts = Review::query()
                ->where('reviewed_at', '>=', $from->format('Y-m-d H:i:s'))
                ->where('reviewed_at', '<', $to->format('Y-m-d H:i:s'))
                ->selectRaw('outcome, count(*) as n')
                ->groupBy('outcome')
                ->pluck('n', 'outcome');

            foreach ($counts as $outcome => $n) {
                $outcomes[(string) $outcome] = (int) $n;
            }

            $months[] = [
                'month' => $from->format('Y-m'),
                'label' => $from->format('M Y'),
                'reviews' => array_sum($outcomes),
                'outcomes' => $outcomes,
            ];
        }

        return $months;
    }

    /**
     * @param  array<string, mixed>  $owners
     * @param  array<string, mixed>  $reviews
     */
    private function headline(int $total, int $underReview, int $late, array $owners, array $reviews): string
    {
        if ($total === 0) {
            return 'Nothing is tracked yet.';
        }

        return sprintf(
            '%s of %s have a confirmed owner. %s of the %s under review were reviewed in the last %d months. %s overdue.',
            self::percent($owners['confirmed_share']),
            self::entries($total),
            self::percent($reviews['recent_share']),
            self::entries($underReview),
            self::RECENT_MONTHS,
            $late === 0 ? 'Nothing is' : number_format($late).($late === 1 ? ' is' : ' are'),
        );
    }

    /** A share as a fraction, or null when there is nothing to take a share of. */
    private static function share(int $part, int $whole): ?float
    {
        return $whole === 0 ? null : $part / $whole;
    }

    public static function percent(?float $share): string
    {
        if ($share === null) {
            return '—';
        }

        $percent = $share * 100;

        // Whole numbers read better; the one place precision matters is just
        // under 100, where "100%" would claim more than is true.
        if ($percent > 99 && $percent < 100) {
            return '99%';
        }

        return round($percent).'%';
    }

    public static function entries(int $count): string
    {
        return number_format($count).' '.($count === 1 ? 'entry' : 'entries');
    }
}
