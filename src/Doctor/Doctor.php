<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Doctor;

use Bpmore\StatamicLifecycle\Expiry\EnabledActions;
use Bpmore\StatamicLifecycle\Gate\GateSettings;
use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\Models\Snapshot;
use Bpmore\StatamicLifecycle\Owners\EscalationContacts;
use Bpmore\StatamicLifecycle\Owners\FallbackOwners;
use Bpmore\StatamicLifecycle\Reporting\Pdf\PdfRenderer;
use Bpmore\StatamicLifecycle\State\Policy;
use Bpmore\StatamicLifecycle\StateRecomputer;
use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use Bpmore\StatamicLifecycle\Storage\StateStore;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Schema;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Throwable;

/**
 * Spec §11's doctor: the questions a person asks when Lifecycle seems to be
 * doing nothing, or the wrong thing, answered in one run — is it installed
 * and up to date, does the config say what it was meant to, is the content
 * tracked, is the scheduler running, are there rows for pages that are gone.
 *
 * Reads only. Every finding that is not fine says what to run.
 */
final class Doctor
{
    /** A recompute older than this is a scheduler that has stopped, not a quiet night. */
    private const STALE_AFTER_HOURS = 36;

    public function __construct(
        private readonly Repository $config,
        private readonly LifecycleDatabase $database,
        private readonly StateStore $store,
        // Resolved when the content is examined, not here: building the
        // recomputer builds the policy, and a policy that cannot be built is
        // one of the findings, not a reason the doctor cannot be.
        private readonly Container $container,
        private readonly FallbackOwners $fallbackOwners,
        private readonly EscalationContacts $escalationContacts,
        private readonly PdfRenderer $pdf,
    ) {}

    /** @return list<Finding> */
    public function examine(DateTimeImmutable $now): array
    {
        $installed = $this->database->isInstalled();

        return [
            ...$this->installation($installed),
            ...$this->configuration(),
            ...($installed ? $this->content($now) : []),
            ...$this->tools(),
        ];
    }

    /** @param  list<Finding>  $findings */
    public static function worst(array $findings): string
    {
        $levels = array_column(array_map(fn (Finding $f): array => $f->toArray(), $findings), 'level');

        return in_array(Finding::FAIL, $levels, true) ? Finding::FAIL : (in_array(Finding::WARN, $levels, true) ? Finding::WARN : Finding::OK);
    }

    /** @return list<Finding> */
    private function installation(bool $installed): array
    {
        $connection = LifecycleDatabase::connectionName();

        if (! $installed) {
            return [Finding::fail('Tables', "Not installed on [{$connection}]. Nothing is tracked until they exist.", 'php please lifecycle:install')];
        }

        $findings = [Finding::ok('Tables', "In place on [{$connection}].")];

        $pending = $this->database->pendingMigrations();

        if ($pending !== []) {
            $findings[] = Finding::warn(
                'Migrations',
                count($pending).' not yet applied: '.implode(', ', $pending).'. This version expects them.',
                'php please lifecycle:install',
            );
        }

        $batching = (string) ($this->config->get('queue.batching.database') ?: $this->config->get('database.default'));
        $table = (string) $this->config->get('queue.batching.table', 'job_batches');

        try {
            $hasBatchTable = Schema::connection($batching)->hasTable($table);
        } catch (Throwable) {
            $hasBatchTable = false;
        }

        if (! $hasBatchTable) {
            $findings[] = Finding::warn(
                'Queue',
                "No [{$table}] table on [{$batching}]. The nightly recompute runs as a job batch and will fail without it.",
                'php please lifecycle:install',
            );
        }

        return $findings;
    }

    /** @return list<Finding> */
    private function configuration(): array
    {
        $findings = [];
        $config = (array) $this->config->get('lifecycle', []);
        $collections = Collection::handles()->all();
        $sites = Site::all()->map->handle()->all();

        try {
            Policy::fromConfig($config);
            $findings[] = Finding::ok('Intervals', 'Every configured interval resolves to a number of days.');
        } catch (Throwable $e) {
            $findings[] = Finding::fail('Intervals', $e->getMessage(), 'Fix intervals.* in config/lifecycle.php; nothing can be computed until it resolves.');
        }

        try {
            $actions = EnabledActions::fromConfig((array) ($config['expiry'] ?? []));
            $findings[] = $actions->any()
                ? Finding::ok('Expiry', 'Opted in: '.implode(', ', array_map(
                    fn (string $c, array $list): string => $c.' ('.implode(', ', array_map(fn ($a) => $a->value, $list)).')',
                    array_keys($actions->byCollection),
                    $actions->byCollection,
                )).'. Run lifecycle:expire --dry-run after any change.')
                : Finding::ok('Expiry', 'Notify-only: no collection has opted into an action.');
        } catch (Throwable $e) {
            $findings[] = Finding::fail('Expiry', $e->getMessage(), 'Fix expiry.collections in config/lifecycle.php.');
        }

        $gate = new GateSettings((array) (($config['gate'] ?? [])['collections'] ?? []));

        foreach ($gate->problems() as $problem) {
            $findings[] = Finding::warn('Gate', $problem, 'Name a date, or \'all\', under gate.collections.');
        }

        if ($gate->problems() === []) {
            $findings[] = $gate->isOn()
                ? Finding::ok('Gate', 'On for: '.implode(', ', array_keys($gate->rules())).'.')
                : Finding::ok('Gate', 'Off. Nothing is required before publishing.');
        }

        // Config that names a collection nobody has.
        $named = [
            'intervals.collections' => array_keys((array) (($config['intervals'] ?? [])['collections'] ?? [])),
            'gate.collections' => array_keys((array) (($config['gate'] ?? [])['collections'] ?? [])),
            'expiry.collections' => array_keys((array) (($config['expiry'] ?? [])['collections'] ?? [])),
            'expiry.never_touch.collections' => array_values((array) ((($config['expiry'] ?? [])['never_touch'] ?? [])['collections'] ?? [])),
            'escalation.contacts' => array_keys((array) (($config['escalation'] ?? [])['contacts'] ?? [])),
        ];

        foreach ($named as $key => $handles) {
            $unknown = array_diff(array_map('strval', $handles), $collections);

            if ($unknown !== []) {
                $findings[] = Finding::warn('Config', "{$key} names a collection this site does not have: ".implode(', ', $unknown).'. It has no effect.', 'Check the handle in config/lifecycle.php.');
            }
        }

        // People named who are no longer users, and sites with nobody at all.
        foreach ($this->fallbackOwners->missing() as $site => $id) {
            $findings[] = Finding::warn('Owner of last resort', "[{$id}], named for the [{$site}] site, is no longer a user.", 'Choose another in Addons → Lifecycle, or under owners.fallback.');
        }

        $resolved = $this->fallbackOwners->resolved();
        $without = array_values(array_diff($sites, array_keys($resolved)));

        if ($without !== []) {
            $findings[] = Finding::warn('Owner of last resort', 'None for: '.implode(', ', $without).'. A page nobody owns there has nobody to remind.', 'Name one in Addons → Lifecycle, or under owners.fallback.');
        } elseif ($this->fallbackOwners->missing() === []) {
            $findings[] = Finding::ok('Owner of last resort', 'Named for every site.');
        }

        foreach ($this->escalationContacts->missing() as $collection => $id) {
            $findings[] = Finding::warn('Escalation', "[{$id}], the contact for [{$collection}], is no longer a user. That collection escalates straight to the owner of last resort.", 'Choose another in Addons → Lifecycle, or under escalation.contacts.');
        }

        return $findings;
    }

    /** @return list<Finding> */
    private function content(DateTimeImmutable $now): array
    {
        $findings = [];

        try {
            $recomputer = $this->container->make(StateRecomputer::class);
        } catch (Throwable) {
            return [Finding::warn('Content', 'Not examined: the intervals above do not resolve, so nothing can be computed.')];
        }

        $published = $recomputer->entries()->all();
        $tracked = count($this->store->exists($published));
        $untracked = count($published) - $tracked;

        if ($published === []) {
            $findings[] = Finding::ok('Content', 'No published entries yet.');
        } elseif ($untracked > 0) {
            $findings[] = Finding::warn(
                'Content',
                number_format($untracked).' of '.number_format(count($published)).' published '.(count($published) === 1 ? 'entry has' : 'entries have').' no state row yet. They are invisible to every screen and reminder until computed.',
                'php please lifecycle:recompute --sync',
            );
        } else {
            $findings[] = Finding::ok('Content', 'Every published entry ('.number_format(count($published)).') has a state row.');
        }

        $orphans = count($recomputer->orphans());

        if ($orphans > 0) {
            $findings[] = Finding::warn('Orphans', number_format($orphans).' '.($orphans === 1 ? 'row is' : 'rows are').' for entries no longer published or gone. The nightly recompute sweeps them.', 'php please lifecycle:recompute --sync');
        } else {
            $findings[] = Finding::ok('Orphans', 'No rows for entries that are gone.');
        }

        $neverBaselined = EntryState::query()->where('baselined', false)->whereNull('last_reviewed_at')->count();

        if ($neverBaselined > 0) {
            $findings[] = Finding::warn(
                'Baseline',
                number_format($neverBaselined).' tracked '.($neverBaselined === 1 ? 'entry was' : 'entries were').' never baselined or reviewed: dated from their last modification, so old content is overdue at once rather than spread across the horizon.',
                'php please lifecycle:baseline --dry-run, then without --dry-run',
            );
        } elseif ($tracked > 0) {
            $findings[] = Finding::ok('Baseline', 'Every tracked entry is baselined or has been reviewed.');
        }

        $nobody = EntryState::query()->whereNull('owner_id')->count();

        if ($nobody > 0) {
            $findings[] = Finding::warn('Owners', number_format($nobody).' tracked '.($nobody === 1 ? 'entry has' : 'entries have').' nobody to remind.', 'Assign owners (lifecycle:assign) or name an owner of last resort.');
        }

        $lastComputed = EntryState::query()->max('computed_at');

        if ($lastComputed !== null) {
            $last = new DateTimeImmutable((string) $lastComputed);
            $hours = (int) floor(($now->getTimestamp() - $last->getTimestamp()) / 3600);

            $findings[] = $hours > self::STALE_AFTER_HOURS
                ? Finding::warn('Scheduler', "The last recompute was {$hours} hours ago. It is scheduled nightly; statuses drift until it runs.", 'Check the scheduler (php please schedule:run from cron), or run php please lifecycle:recompute --sync.')
                : Finding::ok('Scheduler', 'Last recompute '.($hours === 0 ? 'within the hour' : "{$hours} hours ago").'.');
        }

        $snapshots = Snapshot::query()->count();

        if ($tracked > 0 && $snapshots === 0) {
            $findings[] = Finding::warn('Trend', 'No daily snapshot yet; the widget\'s trend appears after the first nightly recompute.', 'php please lifecycle:recompute --sync');
        }

        return $findings;
    }

    /** @return list<Finding> */
    private function tools(): array
    {
        return [
            $this->pdf->isAvailable()
                ? Finding::ok('PDF export', 'A browser for the tagged PDF was found.')
                : Finding::warn('PDF export', 'No Chrome, Chromium or Edge found; lifecycle:report --format=pdf will fail. Every other format works.', 'Install one, or set LIFECYCLE_CHROME to its path.'),
        ];
    }
}
