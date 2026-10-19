<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Doctor\Doctor;
use Bpmore\StatamicLifecycle\Doctor\Finding;
use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\Reporting\Pdf\PdfRenderer;
use Bpmore\StatamicLifecycle\Reporting\Snapshots;
use Bpmore\StatamicLifecycle\StateRecomputer;
use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * Spec §11's doctor. Each check has a healthy case and a found case; the
 * found case says what to run.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-10-19 15:00:00');
    Collection::make('pages')->save();
    User::make()->id('alice')->email('alice@example.edu')->save();
    config(['lifecycle.owners.fallback' => ['default' => 'alice']]);

    // A browser is a machine's business, not the suite's.
    $this->app->bind(PdfRenderer::class, fn () => new class implements PdfRenderer
    {
        public function isAvailable(): bool
        {
            return true;
        }

        public function render(string $html, string $destination, string $title = ''): void {}
    });
});

afterEach(fn () => Carbon::setTestNow());

/** @return array<string, Finding> by subject, the last of each */
function findings(): array
{
    $by = [];
    foreach (app(Doctor::class)->examine(Carbon::now()->toImmutable()) as $finding) {
        $by[$finding->subject] = $finding;
    }

    return $by;
}

function trackedPage(string $id = 'page'): void
{
    Entry::make()->id($id)->collection('pages')->slug($id)->published(true)->data(['title' => $id, 'updated_at' => Carbon::now()->subMonth()->getTimestamp()])->save();
    app(StateRecomputer::class)->recompute([$id], Carbon::now()->toImmutable());
}

it('is registered under please', function () {
    $this->artisan('list')->expectsOutputToContain('lifecycle:doctor');
});

it('says everything is in order on a healthy site, and exits 0', function () {
    trackedPage();
    EntryState::query()->whereKey('page')->update(['baselined' => true]);
    app(LifecycleDatabase::class)->ensureBatchTable();
    app(Snapshots::class)->record(Carbon::now()->toImmutable());

    $this->artisan('lifecycle:doctor')
        ->expectsOutputToContain('Everything is in order.')
        ->assertExitCode(0);

    expect(Doctor::worst(app(Doctor::class)->examine(Carbon::now()->toImmutable())))->toBe(Finding::OK);
});

it('fails, and stops looking at content, when the tables are not there', function () {
    config(['lifecycle.connection' => 'lifecycle_absent', 'database.connections.lifecycle_absent' => ['driver' => 'sqlite', 'database' => '/nonexistent/lifecycle.sqlite']]);

    $this->artisan('lifecycle:doctor')
        ->expectsOutputToContain('Not installed on [lifecycle_absent]')
        ->expectsOutputToContain('php please lifecycle:install')
        ->assertExitCode(1);

    expect(findings())->not->toHaveKey('Content');
});

it('notices a migration this version ships that has not run', function () {
    DB::table('migrations')->where('migration', 'like', '%add_source_to_lifecycle_reviews%')->delete();

    $f = findings()['Migrations'];

    expect($f->level)->toBe(Finding::WARN)
        ->and($f->detail)->toContain('1 not yet applied')
        ->and($f->fix)->toBe('php please lifecycle:install');
});

it('notices the queue\'s batch table missing', function () {
    Schema::dropIfExists('job_batches');

    expect(findings()['Queue']->level)->toBe(Finding::WARN);
});

it('fails on an interval that does not resolve, and an expiry action that does not exist', function () {
    config(['lifecycle.intervals.default' => 'whenever', 'lifecycle.expiry.collections' => ['pages' => ['delete' => true]]]);

    $f = findings();

    expect($f['Intervals']->level)->toBe(Finding::FAIL)
        ->and($f['Expiry']->level)->toBe(Finding::FAIL)
        ->and($f['Expiry']->detail)->toContain('[delete] is not an expiry action');

    $this->artisan('lifecycle:doctor')->assertExitCode(1);
});

it('warns about a gate rule with no date, and config naming a collection the site lacks', function () {
    config(['lifecycle.gate.collections' => ['pages' => true], 'lifecycle.expiry.collections' => ['policies' => ['banner']]]);

    $f = findings();

    expect($f['Gate']->level)->toBe(Finding::WARN)
        ->and($f['Config']->detail)->toContain('expiry.collections names a collection this site does not have: policies');
});

it('warns about people who have left and sites with nobody of last resort', function () {
    config(['lifecycle.owners.fallback' => ['default' => 'gone'], 'lifecycle.escalation.contacts' => ['pages' => 'also-gone']]);

    $all = app(Doctor::class)->examine(Carbon::now()->toImmutable());
    $details = array_map(fn (Finding $f): string => $f->detail, $all);

    expect(implode("\n", $details))
        ->toContain('[gone], named for the [default] site, is no longer a user')
        ->toContain('None for: default')
        ->toContain('[also-gone], the contact for [pages], is no longer a user');
});

it('counts published entries with no row, and says to recompute', function () {
    Entry::make()->id('new')->collection('pages')->slug('new')->published(true)->data(['title' => 'New'])->save();

    $f = findings()['Content'];

    expect($f->level)->toBe(Finding::WARN)
        ->and($f->detail)->toContain('1 of 1 published entry has no state row')
        ->and($f->fix)->toBe('php please lifecycle:recompute --sync');
});

it('counts rows for entries that are gone', function () {
    trackedPage();
    Entry::find('page')->deleteQuietly();

    $f = findings()['Orphans'];

    expect($f->level)->toBe(Finding::WARN)->and($f->detail)->toContain('1 row is for entries no longer published or gone');
});

it('counts entries never baselined or reviewed, and says to baseline', function () {
    trackedPage();

    $f = findings()['Baseline'];

    expect($f->level)->toBe(Finding::WARN)
        ->and($f->detail)->toContain('1 tracked entry was never baselined or reviewed')
        ->and($f->fix)->toContain('lifecycle:baseline --dry-run');
});

it('notices a recompute that has not run in a while', function () {
    trackedPage();
    expect(findings()['Scheduler']->level)->toBe(Finding::OK);

    Carbon::setTestNow('2026-10-21 15:00:00');

    $f = findings()['Scheduler'];

    expect($f->level)->toBe(Finding::WARN)->and($f->detail)->toContain('48 hours ago');
});

it('warns when no browser is there for the PDF', function () {
    $this->app->bind(PdfRenderer::class, fn () => new class implements PdfRenderer
    {
        public function isAvailable(): bool
        {
            return false;
        }

        public function render(string $html, string $destination, string $title = ''): void {}
    });

    expect(findings()['PDF export']->level)->toBe(Finding::WARN);
});

it('prints JSON when asked, with the worst level as the status', function () {
    // One line of output, so one expectation per run.
    $this->artisan('lifecycle:doctor', ['--json' => true])->expectsOutputToContain('"status": "warn"')->assertExitCode(0);
    $this->artisan('lifecycle:doctor', ['--json' => true])->expectsOutputToContain('"subject": "Tables"');
});
