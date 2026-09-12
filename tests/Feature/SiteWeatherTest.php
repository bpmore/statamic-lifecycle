<?php

declare(strict_types=1);

use Bpmore\SiteWeather\Contracts\WeatherContributor;
use Bpmore\SiteWeather\Contributors;
use Bpmore\SiteWeather\Forecast;
use Bpmore\SiteWeather\Reading;
use Bpmore\SiteWeather\State;
use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use Bpmore\StatamicLifecycle\Weather\FreshnessContributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * The Freshness band on Site Weather's tile. Site Weather is a dev dependency
 * here so the real contract is what is tested, not a stand-in.
 */
uses(RefreshDatabase::class);

/** One stored state row: the shape the weather is decided from. */
function seedState(string $status, string $computedAt = '2026-09-11 01:00:00'): void
{
    static $n = 0;
    $n++;

    EntryState::query()->insert([
        'entry_id' => "entry-$n", 'site' => 'default', 'collection' => 'pages',
        'owner_id' => null, 'owner_type' => null, 'owner_source' => null,
        'interval_days' => 365, 'next_review_at' => '2026-09-01 00:00:00', 'status' => $status,
        'baselined' => false, 'computed_at' => $computedAt,
    ]);
}

function seedStates(int $count, string $status): void
{
    for ($i = 0; $i < $count; $i++) {
        seedState($status);
    }
}

function freshnessBand(): Reading
{
    return app(FreshnessContributor::class)->reading();
}

it('is tagged for Site Weather to find, under the freshness key', function () {
    $tagged = collect(app()->tagged('site-weather.contributors'));

    expect($tagged)->toHaveCount(1)
        ->and($tagged->first())->toBeInstanceOf(FreshnessContributor::class)
        ->and($tagged->first())->toBeInstanceOf(WeatherContributor::class)
        ->and($tagged->first()->key())->toBe('freshness')
        ->and($tagged->first()->label())->toBe('Freshness');
});

it('reads unknown with the next step when the tables were never installed', function () {
    // The real condition, not a mock: no lifecycle_states table on the
    // connection. RefreshDatabase rolls the rename back with the test.
    Schema::connection(LifecycleDatabase::connectionName())->rename('lifecycle_states', 'lifecycle_states_absent');
    expect(app(LifecycleDatabase::class)->isInstalled())->toBeFalse();

    $reading = freshnessBand();

    expect($reading->state)->toBe(State::Unknown)
        ->and($reading->headline)->toBe('Not set up yet: run php please lifecycle:install')
        ->and($reading->url)->toBe(cp_route('lifecycle.dashboard'));
});

it('reads unknown, not clear, before anything is tracked', function () {
    $reading = freshnessBand();

    expect($reading->state)->toBe(State::Unknown)
        ->and($reading->headline)->toBe('Nothing tracked yet: run php please lifecycle:baseline')
        ->and($reading->url)->toBe(cp_route('lifecycle.dashboard'))
        ->and($reading->computedAt)->toBeNull();
});

it('reads unknown when everything tracked is exempt from review', function () {
    seedStates(5, 'exempt');

    $reading = freshnessBand();

    expect($reading->state)->toBe(State::Unknown)
        ->and($reading->headline)->toBe('All 5 entries are exempt from review');
});

it('is clear when nothing is late, dated to the latest recompute', function () {
    seedState('fresh', '2026-09-10 01:00:00');
    seedState('due_soon', '2026-09-11 01:30:00');
    seedState('fresh', '2026-09-09 01:00:00');

    $reading = freshnessBand();

    expect($reading->state)->toBe(State::Clear)
        ->and($reading->headline)->toBe('Nothing overdue across 3 entries')
        ->and($reading->computedAt?->toDateTimeString())->toBe('2026-09-11 01:30:00');
});

it('is fair for a little overdue content', function () {
    seedStates(19, 'fresh');
    seedState('overdue'); // one in twenty

    expect(freshnessBand()->state)->toBe(State::Fair)
        ->and(freshnessBand()->headline)->toBe('1 of 20 entries overdue');
});

it('is overcast when a tenth is late, or when anything has expired', function () {
    seedStates(18, 'fresh');
    seedStates(2, 'overdue'); // a tenth
    expect(freshnessBand()->state)->toBe(State::Overcast);

    EntryState::query()->delete();

    seedStates(99, 'fresh');
    seedState('expired'); // one in a hundred, but past the site's own expiry line
    expect(freshnessBand()->state)->toBe(State::Overcast)
        ->and(freshnessBand()->headline)->toBe('1 of 100 entries overdue, 1 past expiry');
});

it('rains when a quarter is late, or when a tenth is late and some of it has expired', function () {
    seedStates(15, 'fresh');
    seedStates(5, 'overdue'); // a quarter
    expect(freshnessBand()->state)->toBe(State::Rain);

    EntryState::query()->delete();

    seedStates(18, 'fresh');
    seedState('overdue');
    seedState('expired'); // a tenth late, one of them expired
    expect(freshnessBand()->state)->toBe(State::Rain)
        ->and(freshnessBand()->headline)->toBe('2 of 20 entries overdue, 1 past expiry');
});

it('storms when half is late, or when expired content reaches a tenth', function () {
    seedStates(10, 'fresh');
    seedStates(10, 'overdue'); // half
    expect(freshnessBand()->state)->toBe(State::Storm)
        ->and(freshnessBand()->headline)->toBe('10 of 20 entries overdue');

    EntryState::query()->delete();

    seedStates(18, 'fresh');
    seedStates(2, 'expired'); // a tenth expired
    expect(freshnessBand()->state)->toBe(State::Storm)
        ->and(freshnessBand()->headline)->toBe('2 of 20 entries overdue, 2 past expiry');
});

it('measures proportions against content under review, not the exempt archive', function () {
    seedStates(50, 'exempt'); // an archive the site has deliberately exempted
    seedState('fresh');
    seedState('overdue'); // one of two under review: half

    $reading = freshnessBand();

    expect($reading->state)->toBe(State::Storm)
        ->and($reading->headline)->toBe('1 of 2 entries overdue');
});

it('reaches the tile end to end from a real recompute', function () {
    Collection::make('pages')->save();
    Entry::make()->collection('pages')->slug('home')->published(true)->data(['title' => 'Home'])->save();
    $this->artisan('lifecycle:recompute', ['--sync' => true])->assertExitCode(0);

    $forecast = Forecast::from(app(Contributors::class));

    expect($forecast->bands)->toHaveCount(1)
        ->and($forecast->bands[0]->key)->toBe('freshness')
        ->and($forecast->bands[0]->label)->toBe('Freshness')
        ->and($forecast->overall())->toBe(State::Clear)
        ->and($forecast->worstBand()?->reading->computedAt)->not->toBeNull()
        ->and($forecast->summary())->toBe('Overall: clear. Freshness: clear, Nothing overdue across 1 entry.');
});
