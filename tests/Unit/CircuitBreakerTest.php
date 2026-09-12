<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Expiry\ActionType;
use Bpmore\StatamicLifecycle\Expiry\Candidate;
use Bpmore\StatamicLifecycle\Expiry\CircuitBreaker;

// No Statamic here: numbers in, a verdict out.

/** @return list<Candidate> $count candidates in one collection */
function proposing(int $count, string $collection = 'pages', string $action = 'unpublish', string $prefix = 'e'): array
{
    $candidates = [];
    for ($i = 0; $i < $count; $i++) {
        $candidates[] = new Candidate("{$prefix}-{$i}", 'default', $collection, ActionType::from($action));
    }

    return $candidates;
}

describe('the rule', function () {
    it('lets a run through that touches a tenth of a collection or less', function () {
        $verdict = (new CircuitBreaker)->check(proposing(100), ['pages' => 1000]);

        expect($verdict->tripped())->toBeFalse()
            ->and($verdict->allowed)->toHaveCount(100)
            ->and($verdict->refused)->toBe([]);
    });

    it('refuses the whole collection when a run would touch more than a tenth', function () {
        $verdict = (new CircuitBreaker)->check(proposing(101), ['pages' => 1000]);

        expect($verdict->tripped())->toBeTrue()
            ->and($verdict->allowed)->toBe([])
            ->and($verdict->refused)->toHaveCount(101)
            ->and($verdict->trips)->toHaveCount(1);

        $trip = $verdict->trips[0];

        expect($trip->collection)->toBe('pages')
            ->and($trip->affected)->toBe(101)
            ->and($trip->size)->toBe(1000)
            ->and($trip->limit)->toBe(0.10)
            ->and($trip->reason())->toBe('Refused to act on 101 of the 1,000 entries in [pages] (10%): more than the 10% a single run may touch. Nothing was changed.');
    });

    it('is exact at the line: 10% is allowed, 10% and one page is not', function () {
        expect((new CircuitBreaker)->check(proposing(10), ['pages' => 100])->tripped())->toBeFalse()
            ->and((new CircuitBreaker)->check(proposing(11), ['pages' => 100])->tripped())->toBeTrue();
    });

    it('judges each collection on its own, and refuses only the ones over the line', function () {
        $candidates = [...proposing(50, 'news'), ...proposing(5, 'pages'), ...proposing(2, 'policies')];

        $verdict = (new CircuitBreaker)->check($candidates, ['news' => 100, 'pages' => 100, 'policies' => 10]);

        expect(array_map(fn ($t) => $t->collection, $verdict->trips))->toBe(['news', 'policies'])
            ->and(array_unique(array_map(fn ($c) => $c->collection, $verdict->allowed)))->toBe(['pages'])
            ->and($verdict->refused)->toHaveCount(52);
    });

    it('counts an entry once, whatever is proposed for it', function () {
        // Banner and noindex for the same ten pages is ten pages, not twenty.
        $candidates = [...proposing(10, 'pages', 'banner'), ...proposing(10, 'pages', 'noindex')];

        expect((new CircuitBreaker)->check($candidates, ['pages' => 100])->tripped())->toBeFalse();
    });

    it('never refuses a restore, even in a collection it has tripped', function () {
        $candidates = [...proposing(20, 'pages', 'unpublish'), ...proposing(3, 'pages', 'restore', 'r')];

        $verdict = (new CircuitBreaker)->check($candidates, ['pages' => 100]);

        expect($verdict->tripped())->toBeTrue()
            ->and($verdict->refused)->toHaveCount(20)
            ->and(array_map(fn ($c) => $c->action, $verdict->allowed))->toBe([ActionType::Restore, ActionType::Restore, ActionType::Restore]);
    });

    it('treats a collection it has no size for as exactly the run, and refuses it', function () {
        // A collection the sizes do not mention is one the run would touch
        // entirely. Fail closed.
        expect((new CircuitBreaker)->check(proposing(3, 'mystery'), [])->tripped())->toBeTrue();
    });

    it('lets nothing through of nothing', function () {
        $verdict = (new CircuitBreaker)->check([], ['pages' => 100]);

        expect($verdict->tripped())->toBeFalse()->and($verdict->allowed)->toBe([]);
    });
});

describe('the settings', function () {
    it('takes the share as a fraction, a percentage, or a string with a sign', function () {
        expect(CircuitBreaker::fromConfig([])->maxShare)->toBe(0.10)
            ->and(CircuitBreaker::fromConfig(['max_share' => 0.25])->maxShare)->toBe(0.25)
            ->and(CircuitBreaker::fromConfig(['max_share' => 25])->maxShare)->toBe(0.25)
            ->and(CircuitBreaker::fromConfig(['max_share' => '25%'])->maxShare)->toBe(0.25)
            ->and(CircuitBreaker::fromConfig(['max_share' => '5'])->maxShare)->toBe(0.05);
    });

    it('can be told not to trip over a handful of pages in a small collection', function () {
        // Two of ten is a fifth, and also two pages. A site may say that a
        // run of fewer than five is never a catastrophe.
        $strict = new CircuitBreaker(0.10, minAffected: 0);
        $lenient = new CircuitBreaker(0.10, minAffected: 5);

        expect($strict->check(proposing(2), ['pages' => 10])->tripped())->toBeTrue()
            ->and($lenient->check(proposing(2), ['pages' => 10])->tripped())->toBeFalse()
            ->and($lenient->check(proposing(5), ['pages' => 10])->tripped())->toBeTrue()
            ->and(CircuitBreaker::fromConfig(['min_affected' => 5])->minAffected)->toBe(5);
    });

    it('refuses nonsense', function () {
        expect(fn () => new CircuitBreaker(1.5))->toThrow(InvalidArgumentException::class);
        expect(fn () => new CircuitBreaker(-0.1))->toThrow(InvalidArgumentException::class);
        expect(fn () => new CircuitBreaker(0.1, -1))->toThrow(InvalidArgumentException::class);
    });

    it('can be set to refuse any action at all, or none', function () {
        expect((new CircuitBreaker(0.0))->check(proposing(1), ['pages' => 1000])->tripped())->toBeTrue()
            ->and((new CircuitBreaker(1.0))->check(proposing(1000), ['pages' => 1000])->tripped())->toBeFalse();
    });
});
