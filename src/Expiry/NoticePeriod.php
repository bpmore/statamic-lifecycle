<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Expiry;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Rail 3 (spec §6): the owner is told first, and the action happens some
 * days later — never in the same run.
 *
 * A candidate goes one of three ways. Never noticed for this review date
 * and this action: it becomes a notice to send, and nothing else happens
 * today. Noticed, but fewer than the period's days ago: it waits. Noticed
 * long enough ago: it may happen. Reviewing the page starts a new cycle,
 * and old notices do not count towards it — the page is no longer overdue.
 *
 * Counted in days, and never fewer than one: a period of zero still means
 * the day after the notice, so the notice and the action are never the
 * same run. Restores need no notice.
 *
 * Pure.
 */
final readonly class NoticePeriod
{
    public function __construct(public int $days = 7)
    {
        if ($days < 0) {
            throw new InvalidArgumentException('The notice period cannot be negative.');
        }
    }

    /** @param  array<string, mixed>  $config  the `lifecycle.expiry` array */
    public static function fromConfig(array $config): self
    {
        return new self(max(0, (int) ($config['notice_days'] ?? 7)));
    }

    /** The first day an action may happen after a notice sent on this day. */
    public function actionableFrom(DateTimeImmutable $noticedOn): DateTimeImmutable
    {
        return $noticedOn->setTime(0, 0)->modify('+'.max(1, $this->days).' days');
    }

    /**
     * @param  list<Candidate>  $candidates
     * @param  list<Notice>  $notices  every notice sent, for these entries
     */
    public function check(array $candidates, array $notices, DateTimeImmutable $today): NoticeVerdict
    {
        $today = $today->setTime(0, 0);

        // The earliest notice per entry, action and cycle: the period is
        // counted from the first time the owner was told, not the last.
        $first = [];

        foreach ($notices as $notice) {
            $key = $notice->entryId.'|'.$notice->action->value.'|'.($notice->dueAt?->format('Y-m-d') ?? '');

            if (! isset($first[$key]) || $notice->sentAt < $first[$key]) {
                $first[$key] = $notice->sentAt;
            }
        }

        $allowed = [];
        $waiting = [];
        $toNotify = [];

        foreach ($candidates as $candidate) {
            if ($candidate->action === ActionType::Restore) {
                $allowed[] = $candidate;

                continue;
            }

            $key = $candidate->entryId.'|'.$candidate->action->value.'|'.($candidate->dueAt?->format('Y-m-d') ?? '');
            $noticedOn = $first[$key] ?? null;

            if ($noticedOn === null) {
                $toNotify[] = $candidate;

                continue;
            }

            $from = $this->actionableFrom($noticedOn);

            if ($today >= $from) {
                $allowed[] = $candidate;
            } else {
                $waiting[] = new Waiting($candidate, $noticedOn->setTime(0, 0), $from);
            }
        }

        return new NoticeVerdict($allowed, $waiting, $toNotify);
    }
}
