<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Expiry\ActionType;
use Bpmore\StatamicLifecycle\Expiry\Candidate;
use Bpmore\StatamicLifecycle\Expiry\NavigationCheck;
use Bpmore\StatamicLifecycle\Expiry\NavigationReferences;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;

beforeEach(function () {
    Collection::make('pages')->routes('/{slug}')->save();
    foreach (['about', 'contact', 'team', 'orphan'] as $slug) {
        Entry::make()->id($slug)->collection('pages')->slug($slug)->published(true)->data(['title' => ucfirst($slug)])->save();
    }
});

it('finds which navigations link to which entries, at any depth, ignoring links that are not entries', function () {
    $main = Nav::make('main')->title('Main navigation');
    $main->save();
    $main->makeTree('default', [
        ['entry' => 'about', 'children' => [['entry' => 'contact', 'children' => [['entry' => 'team']]]]],
        ['title' => 'Elsewhere', 'url' => 'https://example.edu'],
    ])->save();

    $footer = Nav::make('footer')->title('Footer');
    $footer->save();
    $footer->makeTree('default', [['entry' => 'contact']])->save();

    $references = (new NavigationReferences)->for(['about', 'contact', 'team', 'orphan', 'nope']);

    expect($references)->toBe([
        'about' => ['Main navigation'],
        'contact' => ['Main navigation', 'Footer'],
        'team' => ['Main navigation'],
    ]);
});

it('names the site when a navigation has a tree per site', function () {
    config(['statamic.system.multisite' => true]);
    Site::setSites([
        'default' => ['name' => 'English', 'locale' => 'en_US', 'url' => '/'],
        'fr' => ['name' => 'French', 'locale' => 'fr_FR', 'url' => '/fr/'],
    ]);
    $nav = Nav::make('main')->title('Main navigation');   // a nav is in a site by having a tree there
    $nav->save();
    $nav->makeTree('default', [['entry' => 'about']])->save();
    $nav->makeTree('fr', [['entry' => 'contact']])->save();

    expect((new NavigationReferences)->for(['about', 'contact']))->toBe([
        'about' => ['Main navigation (default)'],
        'contact' => ['Main navigation (fr)'],
    ]);
});

it('asks about nothing and gets nothing', function () {
    expect((new NavigationReferences)->for([]))->toBe([]);
});

it('feeds the check, end to end, and the override from config lets a page through', function () {
    $nav = Nav::make('main')->title('Main navigation');
    $nav->save();
    $nav->makeTree('default', [['entry' => 'about']])->save();

    $candidates = [
        new Candidate('about', 'default', 'pages', ActionType::Unpublish, new DateTimeImmutable('2026-08-01')),
        new Candidate('orphan', 'default', 'pages', ActionType::Unpublish, new DateTimeImmutable('2026-08-01')),
    ];
    $references = (new NavigationReferences)->for(['about', 'orphan']);

    $verdict = app(NavigationCheck::class)->check($candidates, $references);
    expect(array_map(fn ($c) => $c->entryId, $verdict->allowed))->toBe(['orphan'])
        ->and($verdict->refused[0]->reason)->toContain('[Main navigation]');

    config(['lifecycle.expiry.unpublish_in_navigation' => ['about']]);
    expect(array_map(fn ($c) => $c->entryId, app(NavigationCheck::class)->check($candidates, $references)->allowed))->toBe(['about', 'orphan']);
});
