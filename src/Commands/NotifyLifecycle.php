<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Commands;

use Bpmore\StatamicLifecycle\Notifications\Reminder;
use Bpmore\StatamicLifecycle\Notifications\Reminders;
use Bpmore\StatamicLifecycle\Notifications\ReminderType;
use Bpmore\StatamicLifecycle\Notifications\Schedule;
use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\User;

/**
 * Sends the day's reminders. Scheduled daily.
 *
 * --dry-run shows who would hear about what, and sends nothing. As of this
 * task only the plan exists; the digest that carries it is the next one,
 * and until it lands a run without --dry-run says so rather than pretend.
 */
class NotifyLifecycle extends Command
{
    use RunsInPlease;

    protected $signature = 'lifecycle:notify
        {--dry-run : Show who would be reminded about what, and send nothing}';

    protected $description = 'Send the day\'s review reminders, one digest per person';

    public function handle(LifecycleDatabase $database, Reminders $reminders, Schedule $schedule): int
    {
        if (! $database->isInstalled()) {
            $this->components->error('Lifecycle is not installed on ['.LifecycleDatabase::connectionName().']. Run `php please lifecycle:install` first.');

            return self::FAILURE;
        }

        $today = Carbon::now()->toImmutable();
        $owed = $reminders->owed($today);

        $this->components->info(sprintf(
            'Schedule: %s%s%s. %s %s owed today.',
            implode(' / ', $schedule->daysBefore),
            $schedule->onDueDay ? ' / 0' : '',
            $schedule->overdueEvery > 0 ? ' days, then every '.$schedule->overdueEvery.' days overdue' : ' days, then once overdue',
            number_format(count($owed)),
            count($owed) === 1 ? 'reminder is' : 'reminders are',
        ));

        if ($owed !== []) {
            $this->printPlan($owed);
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->components->warn('Dry run: nothing was sent.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->error('Sending is not built yet: nothing was sent. Run with --dry-run to see the plan.');

        return self::FAILURE;
    }

    /**
     * Per person, how many of each — the shape the digest will take.
     *
     * @param  list<Reminder>  $owed
     */
    private function printPlan(array $owed): void
    {
        $byRecipient = [];

        foreach ($owed as $reminder) {
            $byRecipient[$reminder->recipient][$reminder->type->value][] = $reminder;
        }

        ksort($byRecipient);
        $this->newLine();

        foreach ($byRecipient as $recipient => $byType) {
            $user = User::find($recipient);
            $name = $user === null ? $recipient : ($user->name() ?: (string) $user->email());

            $parts = [];
            foreach ([ReminderType::DueSoon, ReminderType::Due, ReminderType::Overdue, ReminderType::Escalation] as $type) {
                if (! isset($byType[$type->value])) {
                    continue;
                }
                $count = count($byType[$type->value]);
                $parts[] = match ($type) {
                    ReminderType::DueSoon => $count.' due soon',
                    ReminderType::Due => $count.' due today',
                    ReminderType::Overdue => $count.' overdue',
                    ReminderType::Escalation => $count.' escalated',
                };
            }

            $this->components->twoColumnDetail('  '.$name, implode(', ', $parts));

            if ($this->output->isVerbose()) {
                foreach ($byType as $type => $items) {
                    foreach ($items as $reminder) {
                        $this->line(sprintf('      %-9s %s  (due %s)', $type, $reminder->entryId, $reminder->dueAt->format('Y-m-d')));
                    }
                }
            }
        }
    }
}
