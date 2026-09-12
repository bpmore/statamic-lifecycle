<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Reporting;

use Bpmore\StatamicLifecycle\Models\Review;
use Bpmore\StatamicLifecycle\Models\Snapshot;
use DateTimeImmutable;

/** Writes the day's numbers down, and reads the last so many days back. */
final class Snapshots
{
    public function __construct(private readonly Overview $overview) {}

    /** Record today's numbers — again, if the day already has some: the later run is the truer one. */
    public function record(DateTimeImmutable $now): Snapshot
    {
        $day = $now->setTime(0, 0);
        $statuses = $this->overview->statuses();
        $attention = $this->overview->attention();

        $values = [
            'total' => $this->overview->total(),
            'fresh' => $statuses['fresh'],
            'due_soon' => $statuses['due_soon'],
            'overdue' => $statuses['overdue'],
            'expired' => $statuses['expired'],
            'exempt' => $statuses['exempt'],
            'never_reviewed' => $attention['never_reviewed'],
            'no_owner' => $attention['no_owner'],
            'reviews' => Review::query()
                ->where('reviewed_at', '>=', $day->format('Y-m-d H:i:s'))
                ->where('reviewed_at', '<', $day->modify('+1 day')->format('Y-m-d H:i:s'))
                ->count(),
            'recorded_at' => $now->format('Y-m-d H:i:s'),
        ];

        Snapshot::query()->upsert([['day' => $day->format('Y-m-d')] + $values], ['day'], array_keys($values));

        return Snapshot::query()->find($day->format('Y-m-d'));
    }

    /**
     * The last so many days, oldest first, only the days that were recorded.
     *
     * @return list<Snapshot>
     */
    public function last(int $days, DateTimeImmutable $now): array
    {
        $from = $now->setTime(0, 0)->modify('-'.($days - 1).' days');

        return Snapshot::query()
            ->where('day', '>=', $from->format('Y-m-d'))
            ->where('day', '<=', $now->format('Y-m-d'))
            ->orderBy('day')
            ->get()
            ->all();
    }
}
