<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\State\EntryFacts;
use Bpmore\StatamicLifecycle\State\IntervalSource;
use Bpmore\StatamicLifecycle\State\LifecycleState;
use Bpmore\StatamicLifecycle\State\NextReviewSource;
use Bpmore\StatamicLifecycle\State\OwnerSource;
use Bpmore\StatamicLifecycle\State\Policy;
use Bpmore\StatamicLifecycle\State\PriorState;
use Bpmore\StatamicLifecycle\State\StateComputer;
use Bpmore\StatamicLifecycle\State\Status;

// No Statamic here. These run in milliseconds, and there are a lot of them.

function at(string $date): DateTimeImmutable
{
    return new DateTimeImmutable($date);
}

/** An entry with sensible defaults; override what the case is about. */
function facts(array $overrides = []): EntryFacts
{
    return new EntryFacts(...array_merge([
        'entryId' => 'entry-1',
        'site' => 'default',
        'collection' => 'pages',
        'blueprint' => 'page',
        'lastModifiedAt' => at('2026-01-01'),
    ], $overrides));
}

function compute(EntryFacts $facts, ?PriorState $prior = null, ?Policy $policy = null, string $now = '2026-06-01'): LifecycleState
{
    return (new StateComputer($policy ?? new Policy))->compute($facts, $prior ?? PriorState::none(), at($now));
}

describe('interval: entry → blueprint → collection → global', function () {
    $policy = new Policy(
        defaultInterval: 'yearly',
        collectionIntervals: ['news' => 'quarterly'],
        blueprintIntervals: ['article' => 'monthly', 'news/article' => 14],
    );

    it('takes the entry\'s own interval first, by name', function () use ($policy) {
        $state = compute(facts(['collection' => 'news', 'blueprint' => 'article', 'reviewInterval' => 'every_6_months']), policy: $policy);

        expect($state->intervalDays)->toBe(180)
            ->and($state->intervalSource)->toBe(IntervalSource::Entry);
    });

    it('takes the entry\'s own interval as a number of days', function () use ($policy) {
        expect(compute(facts(['reviewInterval' => 45]), policy: $policy)->intervalDays)->toBe(45)
            ->and(compute(facts(['reviewInterval' => '45']), policy: $policy)->intervalDays)->toBe(45);
    });

    it('falls through an interval name it does not know', function () use ($policy) {
        // A site that replaced the options with its own and did not tell the
        // policy. The entry is not broken; it just has no say.
        $state = compute(facts(['collection' => 'news', 'reviewInterval' => 'fortnightly']), policy: $policy);

        expect($state->intervalDays)->toBe(90)
            ->and($state->intervalSource)->toBe(IntervalSource::Collection);
    });

    it('falls through a zero or negative number', function () use ($policy) {
        expect(compute(facts(['reviewInterval' => 0]), policy: $policy)->intervalSource)->toBe(IntervalSource::Global)
            ->and(compute(facts(['reviewInterval' => -30]), policy: $policy)->intervalSource)->toBe(IntervalSource::Global);
    });

    it('then the blueprint, most specific key first', function () use ($policy) {
        $specific = compute(facts(['collection' => 'news', 'blueprint' => 'article']), policy: $policy);
        $bare = compute(facts(['collection' => 'pages', 'blueprint' => 'article']), policy: $policy);

        expect($specific->intervalDays)->toBe(14)
            ->and($specific->intervalSource)->toBe(IntervalSource::Blueprint)
            ->and($bare->intervalDays)->toBe(30)
            ->and($bare->intervalSource)->toBe(IntervalSource::Blueprint);
    });

    it('then the collection', function () use ($policy) {
        $state = compute(facts(['collection' => 'news', 'blueprint' => 'page']), policy: $policy);

        expect($state->intervalDays)->toBe(90)
            ->and($state->intervalSource)->toBe(IntervalSource::Collection);
    });

    it('then the global default', function () use ($policy) {
        $state = compute(facts(), policy: $policy);

        expect($state->intervalDays)->toBe(365)
            ->and($state->intervalSource)->toBe(IntervalSource::Global);
    });
});

describe('owner: assigned → inferred → fallback', function () {
    $policy = new Policy(fallbackOwners: ['default' => 'webmaster', 'fr' => 'webmestre']);

    it('is the assigned owner when the entry names one', function () use ($policy) {
        $state = compute(facts(['ownerId' => 'alice', 'lastAuthorId' => 'bob']), policy: $policy);

        expect($state->ownerId)->toBe('alice')
            ->and($state->ownerSource)->toBe(OwnerSource::Assigned)
            ->and($state->ownerType)->toBe('user');
    });

    it('is inferred from the last author when nobody was assigned', function () use ($policy) {
        $state = compute(facts(['lastAuthorId' => 'bob']), policy: $policy);

        expect($state->ownerId)->toBe('bob')
            ->and($state->ownerSource)->toBe(OwnerSource::Inferred);
    });

    it('does not infer when the site has said not to', function () {
        $policy = new Policy(inferOwnerFromLastAuthor: false, fallbackOwners: ['default' => 'webmaster']);
        $state = compute(facts(['lastAuthorId' => 'bob']), policy: $policy);

        expect($state->ownerId)->toBe('webmaster')
            ->and($state->ownerSource)->toBe(OwnerSource::Fallback);
    });

    it('falls back to the site\'s owner-of-last-resort, per site', function () use ($policy) {
        expect(compute(facts(), policy: $policy)->ownerId)->toBe('webmaster')
            ->and(compute(facts(['site' => 'fr']), policy: $policy)->ownerId)->toBe('webmestre')
            ->and(compute(facts(['site' => 'fr']), policy: $policy)->ownerSource)->toBe(OwnerSource::Fallback);
    });

    it('is honestly nobody when there is nobody', function () {
        $state = compute(facts(['site' => 'de']), policy: new Policy(fallbackOwners: ['default' => 'webmaster']));

        expect($state->ownerId)->toBeNull()
            ->and($state->ownerType)->toBeNull()
            ->and($state->ownerSource)->toBeNull();
    });

    it('treats an empty string as nobody', function () use ($policy) {
        $state = compute(facts(['ownerId' => '', 'lastAuthorId' => '']), policy: $policy);

        expect($state->ownerSource)->toBe(OwnerSource::Fallback);
    });
});

describe('next review date', function () {
    it('is the last review plus the interval', function () {
        $prior = new PriorState(lastReviewedAt: at('2026-03-15 14:00'), lastReviewedBy: 'alice');
        $state = compute(facts(['reviewInterval' => 'quarterly']), $prior);

        expect($state->nextReviewAt)->toEqual(at('2026-06-13 14:00'))
            ->and($state->nextReviewSource)->toBe(NextReviewSource::LastReview)
            ->and($state->lastReviewedAt)->toEqual(at('2026-03-15 14:00'))
            ->and($state->lastReviewedBy)->toBe('alice');
    });

    it('is an unmet hard deadline, whatever the interval says', function () {
        // Later than the cadence would give: the deadline still wins, because
        // a person typed it and the computed date is a default.
        $prior = new PriorState(lastReviewedAt: at('2026-01-15'));
        $state = compute(facts(['reviewInterval' => 'yearly', 'reviewBy' => at('2027-03-01')]), $prior);

        expect($state->nextReviewAt)->toEqual(at('2027-03-01'))
            ->and($state->nextReviewSource)->toBe(NextReviewSource::HardDate);
    });

    it('is an unmet hard deadline even when never reviewed', function () {
        $state = compute(facts(['reviewBy' => at('2026-09-01')]));

        expect($state->nextReviewAt)->toEqual(at('2026-09-01'))
            ->and($state->nextReviewSource)->toBe(NextReviewSource::HardDate);
    });

    it('treats a hard deadline as met once a review is recorded on or after it', function () {
        $deadline = at('2026-03-01');

        $onTheDay = compute(facts(['reviewInterval' => 'quarterly', 'reviewBy' => $deadline]), new PriorState(lastReviewedAt: at('2026-03-01 09:00')));
        $after = compute(facts(['reviewInterval' => 'quarterly', 'reviewBy' => $deadline]), new PriorState(lastReviewedAt: at('2026-03-05')));
        $before = compute(facts(['reviewInterval' => 'quarterly', 'reviewBy' => $deadline]), new PriorState(lastReviewedAt: at('2026-02-20')));

        expect($onTheDay->nextReviewSource)->toBe(NextReviewSource::LastReview)
            ->and($after->nextReviewSource)->toBe(NextReviewSource::LastReview)
            ->and($after->nextReviewAt)->toEqual(at('2026-06-03'))
            ->and($before->nextReviewSource)->toBe(NextReviewSource::HardDate);
    });

    it('keeps the baseline date until the first real review', function () {
        // Changing the interval must not collapse the spread the baseline
        // created; that spread is the answer to day one on a large site.
        $prior = new PriorState(baselined: true, baselineNextReviewAt: at('2026-11-20'));
        $state = compute(facts(['reviewInterval' => 'monthly']), $prior);

        expect($state->nextReviewAt)->toEqual(at('2026-11-20'))
            ->and($state->nextReviewSource)->toBe(NextReviewSource::Baseline)
            ->and($state->baselined)->toBeTrue();
    });

    it('prefers a real review over the baseline date', function () {
        $prior = new PriorState(lastReviewedAt: at('2026-05-01'), baselined: true, baselineNextReviewAt: at('2026-11-20'));
        $state = compute(facts(['reviewInterval' => 'monthly']), $prior);

        expect($state->nextReviewAt)->toEqual(at('2026-05-31'))
            ->and($state->nextReviewSource)->toBe(NextReviewSource::LastReview)
            ->and($state->baselined)->toBeTrue();
    });

    it('falls back to the last modification plus the interval, and says so', function () {
        $state = compute(facts(['lastModifiedAt' => at('2026-02-10'), 'reviewInterval' => 'quarterly']));

        expect($state->nextReviewAt)->toEqual(at('2026-05-11'))
            ->and($state->nextReviewSource)->toBe(NextReviewSource::LastModified)
            ->and($state->baselined)->toBeFalse();
    });
});

describe('status', function () {
    $policy = new Policy(dueSoonDays: 30, expiredAfterDays: 30);

    // Next review on 2026-06-30 throughout; only "now" moves.
    $next = fn () => facts(['reviewBy' => at('2026-06-30')]);

    it('is fresh well before the date', function () use ($policy, $next) {
        expect(compute($next(), policy: $policy, now: '2026-05-30')->status)->toBe(Status::Fresh);
    });

    it('becomes due soon exactly the window\'s length before', function () use ($policy, $next) {
        expect(compute($next(), policy: $policy, now: '2026-05-31')->status)->toBe(Status::DueSoon);
    });

    it('is due soon on the day itself', function () use ($policy, $next) {
        // Due today is "due", not "overdue" — even late in the day.
        expect(compute($next(), policy: $policy, now: '2026-06-30 23:30')->status)->toBe(Status::DueSoon);
    });

    it('is overdue the day after', function () use ($policy, $next) {
        expect(compute($next(), policy: $policy, now: '2026-07-01 00:01')->status)->toBe(Status::Overdue);
    });

    it('is overdue the day before it expires', function () use ($policy, $next) {
        expect(compute($next(), policy: $policy, now: '2026-07-29')->status)->toBe(Status::Overdue);
    });

    it('expires once overdue for the configured stretch', function () use ($policy, $next) {
        expect(compute($next(), policy: $policy, now: '2026-07-30')->status)->toBe(Status::Expired)
            ->and(compute($next(), policy: $policy, now: '2027-01-01')->status)->toBe(Status::Expired);
    });

    it('compares days, not times, when the date came from a review with a time', function () use ($policy) {
        $prior = new PriorState(lastReviewedAt: at('2026-03-01 15:00'));
        $entry = facts(['reviewInterval' => 'monthly']);   // next: 2026-03-31 15:00

        expect(compute($entry, $prior, $policy, '2026-03-31 16:00')->status)->toBe(Status::DueSoon)
            ->and(compute($entry, $prior, $policy, '2026-04-01 08:00')->status)->toBe(Status::Overdue);
    });

    it('never becomes due soon when the window is zero', function () use ($next) {
        $policy = new Policy(dueSoonDays: 0);

        expect(compute($next(), policy: $policy, now: '2026-06-29')->status)->toBe(Status::Fresh)
            ->and(compute($next(), policy: $policy, now: '2026-06-30')->status)->toBe(Status::DueSoon);
    });
});

describe('exempt', function () {
    it('has no interval, no date and no status but exempt', function () {
        $prior = new PriorState(lastReviewedAt: at('2026-01-01'), lastReviewedBy: 'alice');
        $state = compute(facts(['exempt' => true, 'reviewInterval' => 'monthly', 'reviewBy' => at('2026-02-01')]), $prior);

        expect($state->status)->toBe(Status::Exempt)
            ->and($state->intervalDays)->toBeNull()
            ->and($state->intervalSource)->toBeNull()
            ->and($state->nextReviewAt)->toBeNull()
            ->and($state->nextReviewSource)->toBeNull()
            // The history is still the history.
            ->and($state->lastReviewedAt)->toEqual(at('2026-01-01'))
            ->and($state->lastReviewedBy)->toBe('alice');
    });

    it('still has an owner, because somebody still owns it', function () {
        $state = compute(facts(['exempt' => true, 'ownerId' => 'alice']));

        expect($state->ownerId)->toBe('alice')
            ->and($state->ownerSource)->toBe(OwnerSource::Assigned);
    });
});

it('carries the identifying columns and the clock through', function () {
    $state = compute(facts(['entryId' => 'e-9', 'site' => 'fr', 'collection' => 'news']), now: '2026-06-01 10:30');

    expect($state->entryId)->toBe('e-9')
        ->and($state->site)->toBe('fr')
        ->and($state->collection)->toBe('news')
        ->and($state->computedAt)->toEqual(at('2026-06-01 10:30'));
});

describe('owner: guesses the site has ruled out', function () {
    it('never infers a person on the never-infer list, and falls through', function () {
        // Whoever ran the import "last touched" every page on the site.
        $policy = new Policy(neverInfer: ['importer'], fallbackOwners: ['default' => 'webmaster']);

        $state = compute(facts(['lastAuthorId' => 'importer']), policy: $policy);

        expect($state->ownerId)->toBe('webmaster')
            ->and($state->ownerSource)->toBe(OwnerSource::Fallback);
    });

    it('still takes that person as an owner when a human chose them', function () {
        // The list rules out a guess, not a decision.
        $policy = new Policy(neverInfer: ['importer']);

        $state = compute(facts(['ownerId' => 'importer', 'lastAuthorId' => 'importer']), policy: $policy);

        expect($state->ownerId)->toBe('importer')
            ->and($state->ownerSource)->toBe(OwnerSource::Assigned);
    });

    it('still infers everyone else', function () {
        $policy = new Policy(neverInfer: ['importer']);

        expect(compute(facts(['lastAuthorId' => 'alice']), policy: $policy)->ownerSource)->toBe(OwnerSource::Inferred);
    });
});
