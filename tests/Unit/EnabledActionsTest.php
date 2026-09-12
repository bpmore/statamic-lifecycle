<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Expiry\ActionType;
use Bpmore\StatamicLifecycle\Expiry\EnabledActions;

it('is all off unless a collection opts in', function () {
    $enabled = EnabledActions::fromConfig([]);

    expect($enabled->any())->toBeFalse()
        ->and($enabled->for('news'))->toBe([]);
});

it('reads a map of flags, or a plain list, and orders least to most drastic', function () {
    $enabled = EnabledActions::fromConfig(['collections' => [
        'news' => ['unpublish' => true, 'banner' => true, 'noindex' => false],
        'policies' => ['noindex', 'banner'],
        'pages' => ['banner' => false],
    ]]);

    expect($enabled->any())->toBeTrue()
        ->and($enabled->for('news'))->toBe([ActionType::Banner, ActionType::Unpublish])
        ->and($enabled->for('policies'))->toBe([ActionType::Banner, ActionType::Noindex])
        ->and($enabled->for('pages'))->toBe([])
        ->and(array_keys($enabled->byCollection))->toBe(['news', 'policies']);
});

it('refuses an action that is not one, and restore', function () {
    expect(fn () => new EnabledActions(['news' => ['delete' => true]]))
        ->toThrow(InvalidArgumentException::class, '[delete] is not an expiry action');
    expect(fn () => new EnabledActions(['news' => ['restore']]))
        ->toThrow(InvalidArgumentException::class, '[restore] is not an expiry action');
});
