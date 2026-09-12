<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Expiry;

use Bpmore\StatamicLifecycle\Notifications\Notifier;
use Bpmore\StatamicLifecycle\Notifications\QuietWindow;
use DateTimeImmutable;
use Statamic\Facades\Entry;
use Throwable;

/**
 * Carries out a plan: sends the notices, takes the actions the rails
 * allowed, writes each one down, and tells an admin about anything the
 * breaker refused.
 *
 * Notices go out on their own, past the frequency preference: "your page
 * will be unpublished next week" is not a reminder anyone has muted, and
 * silence would let a change happen unannounced. They do respect a quiet
 * day, and are simply planned again tomorrow.
 *
 * Each action is written to the log before the run moves on, so a run
 * that dies halfway leaves an exact record of what it did.
 */
final class ExpiryRunner
{
    public const TRIGGERED_BY = 'lifecycle:expire';

    public function __construct(
        private readonly ActionApplier $applier,
        private readonly ActionLog $log,
        private readonly Notifier $notifier,
        private readonly QuietWindow $quiet,
        private readonly TripAlert $alert,
    ) {}

    public function run(ExpiryPlan $plan, DateTimeImmutable $now): ExpiryOutcome
    {
        // ---- Notices ----------------------------------------------------

        $noticesSent = 0;
        $noticesHeld = null;

        if ($plan->notices !== []) {
            if ($reason = $this->quiet->reason($now)) {
                $noticesHeld = $reason;
            } else {
                $noticesSent = $this->notifier->send($this->notifier->digests($plan->notices), $now)['reminders'];
            }
        }

        // ---- Actions ----------------------------------------------------

        $applied = [];
        $failed = [];

        foreach ($plan->allowed as $candidate) {
            try {
                $entry = Entry::find($candidate->entryId);

                if ($entry === null) {
                    $failed[] = new Refusal($candidate, 'the page no longer exists');

                    continue;
                }

                $previous = $this->applier->apply($entry, $candidate->action);
                $this->log->record($entry, $candidate->action, $previous, self::TRIGGERED_BY, $now);
                $applied[] = $candidate;
            } catch (Throwable $e) {
                report($e);
                $failed[] = new Refusal($candidate, $e->getMessage());
            }
        }

        // ---- The breaker ------------------------------------------------

        $this->alert->raise($plan->trips);

        return new ExpiryOutcome($applied, $failed, $noticesSent, $noticesHeld, count($plan->trips));
    }
}
