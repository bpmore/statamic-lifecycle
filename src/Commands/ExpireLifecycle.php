<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Commands;

use Bpmore\StatamicLifecycle\Expiry\Candidate;
use Bpmore\StatamicLifecycle\Expiry\EnabledActions;
use Bpmore\StatamicLifecycle\Expiry\ExpiryPlan;
use Bpmore\StatamicLifecycle\Expiry\ExpiryPlanner;
use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * The dangerous part (spec §6), behind every rail.
 *
 * --dry-run prints exactly what would happen and changes nothing. It is the
 * documented first step: run it, read it, and only then let a collection
 * opt into anything. The actions themselves are the next task; until they
 * land, a run without --dry-run says so rather than pretend.
 */
class ExpireLifecycle extends Command
{
    use RunsInPlease;

    protected $signature = 'lifecycle:expire
        {--dry-run : Print exactly what would happen, and change nothing}
        {--collection= : Only this collection}
        {--allow-nav=* : Entry IDs that may be unpublished from a navigation, for this run only}';

    protected $description = 'Apply the expiry actions collections have opted into, behind every rail';

    public function handle(LifecycleDatabase $database, EnabledActions $enabled, ExpiryPlanner $planner): int
    {
        if (! $database->isInstalled()) {
            $this->components->error('Lifecycle is not installed on ['.LifecycleDatabase::connectionName().']. Run `php please lifecycle:install` first.');

            return self::FAILURE;
        }

        $collection = $this->option('collection') ?: null;

        if ($collection !== null && Collection::find($collection) === null) {
            $this->components->error("There is no collection called [{$collection}].");

            return self::FAILURE;
        }

        if (! $enabled->any()) {
            $this->components->info('No collection has opted into any expiry action, so nothing can happen. Lifecycle is notify-only until one does: see expiry.collections in config/lifecycle.php.');

            return self::SUCCESS;
        }

        $now = Carbon::now()->toImmutable();
        $plan = $planner->plan($now, $collection, array_values(array_filter((array) $this->option('allow-nav'))));

        $this->printOptIns($enabled, $collection);
        $this->printPlan($plan, $now);

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->components->warn('Dry run: nothing was changed and nobody was notified.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->error('The actions are not built yet: nothing was changed. Run with --dry-run to see the plan.');

        return self::FAILURE;
    }

    private function printOptIns(EnabledActions $enabled, ?string $only): void
    {
        $this->line('  <options=bold>Opted in:</>');

        foreach ($enabled->byCollection as $collection => $actions) {
            if ($only !== null && $collection !== $only) {
                continue;
            }

            $this->components->twoColumnDetail(
                "  {$collection}",
                implode(', ', array_map(fn ($a): string => $a->label(), $actions)),
            );
        }
    }

    private function printPlan(ExpiryPlan $plan, \DateTimeImmutable $now): void
    {
        $this->newLine();
        $this->components->info(sprintf(
            '%s expired %s in opted-in collections.',
            number_format($plan->expired),
            $plan->expired === 1 ? 'entry' : 'entries',
        ));

        if ($plan->isEmpty()) {
            $this->components->info('Nothing to do.');

            return;
        }

        $this->section('Would happen now', $plan->allowed, fn (Candidate $c): string => "{$c->action->label()} — ".$this->name($c->entryId));

        if ($plan->notices !== []) {
            $from = $plan->notices[0]->actionableFrom?->format('j M Y');
            $this->section("Would notify the owner now, to act on or after {$from}", $plan->notices, fn ($n): string => "{$n->action->label()} — ".$this->name($n->entryId).' → '.$this->person($n->recipient));
        }

        $this->section('Noticed, waiting for the period to pass', $plan->waiting, fn ($w): string => "{$w->candidate->action->label()} — ".$this->name($w->candidate->entryId).' — from '.$w->actionableFrom->format('j M Y'));
        $this->section('Held by the daily cap until a later day', $plan->deferred, fn (Candidate $c): string => "{$c->action->label()} — ".$this->name($c->entryId));
        $this->section('Kept out', $plan->refused, fn ($r): string => "{$r->candidate->action->label()} — ".$this->name($r->candidate->entryId).': '.$r->reason);
        $this->section('Refused by the circuit breaker', $plan->trips, fn ($t): string => $t->reason());
        $this->section('Nobody to notify, so nothing can happen', $plan->unowned, fn (Candidate $c): string => "{$c->action->label()} — ".$this->name($c->entryId).' — set a content owner, or an owner of last resort');
    }

    /** @param  list<mixed>  $items */
    private function section(string $title, array $items, callable $line): void
    {
        if ($items === []) {
            return;
        }

        $this->newLine();
        $this->line(sprintf('  <options=bold>%s (%s)</>', $title, number_format(count($items))));

        foreach (array_slice($items, 0, 50) as $item) {
            $this->line('    '.$line($item));
        }

        if (count($items) > 50) {
            $this->line('    … and '.number_format(count($items) - 50).' more.');
        }
    }

    private function name(string $entryId): string
    {
        $entry = Entry::find($entryId);

        return $entry === null ? "[{$entryId}]" : sprintf('%s (%s)', $entry->get('title') ?: $entry->slug(), $entry->uri() ?? $entryId);
    }

    private function person(string $userId): string
    {
        $user = User::find($userId);

        return $user === null ? "[{$userId}]" : ($user->name() ?: (string) $user->email());
    }
}
