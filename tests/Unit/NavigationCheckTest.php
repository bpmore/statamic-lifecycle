<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Expiry\ActionType;
use Bpmore\StatamicLifecycle\Expiry\Candidate;
use Bpmore\StatamicLifecycle\Expiry\NavigationCheck;

function proposed(string $id, string $action = 'unpublish'): Candidate
{
    return new Candidate($id, 'default', 'pages', ActionType::from($action), new DateTimeImmutable('2026-08-01'));
}

function judged(NavigationCheck $check, array $candidates, array $references, array $allowedThisRun = []): array
{
    $verdict = $check->check($candidates, $references, $allowedThisRun);

    return [
        'allowed' => array_map(fn ($c) => $c->entryId, $verdict->allowed),
        'refused' => array_map(fn ($r) => $r->candidate->entryId.': '.$r->reason, $verdict->refused),
    ];
}

it('refuses to unpublish a page a navigation links to, and says which', function () {
    expect(judged(new NavigationCheck, [proposed('about'), proposed('orphan')], ['about' => ['Main navigation']]))->toBe([
        'allowed' => ['orphan'],
        'refused' => ['about: linked from the [Main navigation] navigation; unpublishing it would break the menu. Allow it explicitly to go ahead.'],
    ]);
});

it('names every navigation that links to it', function () {
    $reason = (new NavigationCheck)->reasonToRefuse(proposed('about'), ['Main navigation', 'Footer', 'Main navigation']);

    expect($reason)->toStartWith('linked from the [Main navigation] navigation and the [Footer] navigation;');
});

it('lets a banner or a noindex through: the link still works', function () {
    $refs = ['about' => ['Main navigation']];

    expect(judged(new NavigationCheck, [proposed('about', 'banner'), proposed('about', 'noindex'), proposed('about', 'restore')], $refs)['allowed'])
        ->toBe(['about', 'about', 'about']);
});

it('lets an unpublish through when the page is named in config, or allowed for this run', function () {
    $refs = ['about' => ['Main navigation'], 'contact' => ['Main navigation']];

    expect(judged(new NavigationCheck(['about']), [proposed('about'), proposed('contact')], $refs))
        ->toBe(['allowed' => ['about'], 'refused' => ['contact: linked from the [Main navigation] navigation; unpublishing it would break the menu. Allow it explicitly to go ahead.']]);

    expect(judged(new NavigationCheck, [proposed('about'), proposed('contact')], $refs, allowedThisRun: ['contact'])['allowed'])->toBe(['contact']);
});

it('has nothing to say about a page nothing links to', function () {
    expect((new NavigationCheck)->reasonToRefuse(proposed('about'), []))->toBeNull()
        ->and(judged(new NavigationCheck, [proposed('a'), proposed('b')], [])['allowed'])->toBe(['a', 'b']);
});

it('is read from config, tidied', function () {
    expect(NavigationCheck::fromConfig(['unpublish_in_navigation' => [' about ', '', 'about', 'contact']])->overrides)->toBe(['about', 'contact'])
        ->and(NavigationCheck::fromConfig([])->overrides)->toBe([]);
});
