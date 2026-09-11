<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Blueprint\LifecycleFields;
use Bpmore\StatamicLifecycle\State\Policy;

it('is a sensible policy with no configuration at all', function () {
    $policy = Policy::fromConfig([]);

    expect($policy->defaultIntervalDays)->toBe(365)
        ->and($policy->collectionIntervals)->toBe([])
        ->and($policy->blueprintIntervals)->toBe([])
        ->and($policy->inferOwnerFromLastAuthor)->toBeTrue()
        ->and($policy->fallbackOwners)->toBe([])
        ->and($policy->dueSoonDays)->toBe(30)
        ->and($policy->expiredAfterDays)->toBe(30);
});

it('reads every key config/lifecycle.php documents', function () {
    $policy = Policy::fromConfig([
        'intervals' => [
            'default' => 'every_2_years',
            'collections' => ['news' => 'quarterly', 'events' => 60],
            'blueprints' => ['policy' => 'every_3_years', 'news/press_release' => 'monthly'],
            'named' => ['fortnightly' => 14],
        ],
        'owners' => [
            'infer_from_last_author' => false,
            'fallback' => ['default' => 'webmaster', 'fr' => 'webmestre', 'de' => '', 'es' => null],
        ],
        'due_soon_days' => 14,
        'expired_after_days' => 60,
    ]);

    expect($policy->defaultIntervalDays)->toBe(730)
        ->and($policy->collectionIntervals)->toBe(['news' => 90, 'events' => 60])
        ->and($policy->blueprintIntervals)->toBe(['policy' => 1095, 'news/press_release' => 30])
        ->and($policy->intervalDays('fortnightly'))->toBe(14)
        ->and($policy->inferOwnerFromLastAuthor)->toBeFalse()
        // Blank fallbacks are no fallback at all, not an owner called "".
        ->and($policy->fallbackOwners)->toBe(['default' => 'webmaster', 'fr' => 'webmestre'])
        ->and($policy->dueSoonDays)->toBe(14)
        ->and($policy->expiredAfterDays)->toBe(60);
});

it('knows every interval the editor can pick', function () {
    $policy = new Policy;

    foreach (LifecycleFields::INTERVALS as $name => $interval) {
        expect($policy->intervalDays($name))->toBe($interval['days']);
    }
});

it('lets a site rename what a built-in means', function () {
    // "Quarterly" is 90 days here and 91 somewhere else. The site decides.
    $policy = new Policy(namedIntervals: ['quarterly' => 91]);

    expect($policy->intervalDays('quarterly'))->toBe(91);
});

it('resolves an interval value the way the computation will', function () {
    $policy = new Policy;

    expect($policy->intervalDays('yearly'))->toBe(365)
        ->and($policy->intervalDays(45))->toBe(45)
        ->and($policy->intervalDays('45'))->toBe(45)
        ->and($policy->intervalDays(0))->toBeNull()
        ->and($policy->intervalDays('0'))->toBeNull()
        ->and($policy->intervalDays(-1))->toBeNull()
        ->and($policy->intervalDays('never heard of it'))->toBeNull()
        ->and($policy->intervalDays(null))->toBeNull()
        ->and($policy->intervalDays(4.5))->toBeNull()
        ->and($policy->intervalDays(['yearly']))->toBeNull();
});

it('refuses a default interval it cannot resolve, loudly', function () {
    // The global default is the rung everything falls to. A typo there
    // would otherwise leave every entry on the site with no interval.
    expect(fn () => Policy::fromConfig(['intervals' => ['default' => 'anually']]))
        ->toThrow(InvalidArgumentException::class, "'anually'");
});

it('refuses a collection or blueprint interval it cannot resolve', function () {
    expect(fn () => new Policy(collectionIntervals: ['news' => 'weekly-ish']))
        ->toThrow(InvalidArgumentException::class, "collection 'news'");

    expect(fn () => new Policy(blueprintIntervals: ['article' => 0]))
        ->toThrow(InvalidArgumentException::class, "blueprint 'article'");
});

it('refuses negative thresholds', function () {
    expect(fn () => new Policy(dueSoonDays: -1))->toThrow(InvalidArgumentException::class);
    expect(fn () => new Policy(expiredAfterDays: -1))->toThrow(InvalidArgumentException::class);
});

it('reads the people who must never be guessed, dropping blanks', function () {
    $policy = Policy::fromConfig(['owners' => ['never_infer' => ['importer', '', null, 'migration-bot']]]);

    expect($policy->neverInfer)->toBe(['importer', 'migration-bot'])
        ->and($policy->mayInfer('importer'))->toBeFalse()
        ->and($policy->mayInfer('alice'))->toBeTrue()
        ->and($policy->mayInfer(null))->toBeFalse()
        ->and($policy->mayInfer(''))->toBeFalse();
});

it('may infer nobody at all when inference is off', function () {
    expect((new Policy(inferOwnerFromLastAuthor: false))->mayInfer('alice'))->toBeFalse();
});
