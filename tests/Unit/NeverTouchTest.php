<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Expiry\ActionType;
use Bpmore\StatamicLifecycle\Expiry\Candidate;
use Bpmore\StatamicLifecycle\Expiry\NeverTouch;

function page(string $id, string $collection = 'pages', string $action = 'unpublish'): Candidate
{
    return new Candidate($id, 'default', $collection, ActionType::from($action), new DateTimeImmutable('2026-08-01'));
}

function kept(NeverTouch $list, array $candidates, array $uris = []): array
{
    $verdict = $list->check($candidates, $uris);

    return [
        'allowed' => array_map(fn ($c) => $c->entryId, $verdict->allowed),
        'refused' => array_map(fn ($r) => $r->candidate->entryId.': '.$r->reason, $verdict->refused),
    ];
}

it('keeps out nothing when nothing is listed', function () {
    $list = new NeverTouch;

    expect($list->isEmpty())->toBeTrue()
        ->and(kept($list, [page('a'), page('b')], ['a' => '/', 'b' => '/legal/privacy']))->toBe(['allowed' => ['a', 'b'], 'refused' => []]);
});

it('keeps out an entry by ID', function () {
    expect(kept(new NeverTouch(entries: ['b']), [page('a'), page('b')]))
        ->toBe(['allowed' => ['a'], 'refused' => ['b: entry [b] is on the never-touch list']]);
});

it('keeps out a whole collection', function () {
    expect(kept(new NeverTouch(collections: ['redirects']), [page('a'), page('r', 'redirects')]))
        ->toBe(['allowed' => ['a'], 'refused' => ['r: the [redirects] collection is on the never-touch list']]);
});

it('keeps out pages whose URL matches a pattern, slashes and all', function () {
    $list = new NeverTouch(urls: ['/legal/*']);
    $uris = ['a' => '/about', 'b' => '/legal/privacy', 'c' => '/legal/terms/2024', 'd' => '/legal', 'e' => '/legality'];

    expect(kept($list, [page('a'), page('b'), page('c'), page('d'), page('e')], $uris))->toBe([
        'allowed' => ['a', 'd', 'e'],
        'refused' => [
            'b: [/legal/privacy] matches the never-touch pattern [/legal/*]',
            'c: [/legal/terms/2024] matches the never-touch pattern [/legal/*]',
        ],
    ]);
});

it('matches the home page by / and nothing else', function () {
    $list = new NeverTouch(urls: ['/']);

    expect(kept($list, [page('home'), page('about')], ['home' => '/', 'about' => '/about']))
        ->toBe(['allowed' => ['about'], 'refused' => ['home: [/] matches the never-touch pattern [/]']]);
});

it('ignores case and a trailing slash, and forgives a missing leading one', function () {
    expect(NeverTouch::matches('/Legal/*', '/legal/privacy/'))->toBeTrue()
        ->and(NeverTouch::matches('legal/privacy', '/legal/privacy'))->toBeTrue()
        ->and(NeverTouch::matches('/legal/privacy/', '/legal/privacy'))->toBeTrue()
        ->and(NeverTouch::matches('/legal/*', '/about/legal'))->toBeFalse()
        ->and(NeverTouch::matches('*/archive', '/news/2019/archive'))->toBeTrue()
        ->and(NeverTouch::matches('/news/*/archive', '/news/2019/archive'))->toBeTrue()
        ->and(NeverTouch::matches('/news/*/archive', '/news/archive'))->toBeFalse();
});

it('cannot judge a URL it was not given, and lets the page through on that rule alone', function () {
    // The command supplies URIs; a page with none (no route) has no URL to
    // match. It can still be listed by ID or collection.
    $list = new NeverTouch(urls: ['/legal/*'], entries: ['b']);

    expect(kept($list, [page('a'), page('b')], []))->toBe(['allowed' => ['a'], 'refused' => ['b: entry [b] is on the never-touch list']]);
});

it('never keeps out a restore', function () {
    $list = new NeverTouch(collections: ['pages'], urls: ['/'], entries: ['a']);

    expect(kept($list, [page('a', action: 'restore')], ['a' => '/'])['allowed'])->toBe(['a']);
});

it('names the first rule that applies, entry before collection before URL', function () {
    $list = new NeverTouch(collections: ['pages'], urls: ['/'], entries: ['a']);

    expect($list->reasonToKeepOut(page('a'), '/'))->toContain('entry [a]')
        ->and($list->reasonToKeepOut(page('b'), '/'))->toContain('the [pages] collection')
        ->and($list->reasonToKeepOut(page('c', 'news'), '/'))->toContain('pattern [/]')
        ->and($list->reasonToKeepOut(page('d', 'news'), '/about'))->toBeNull();
});

it('is read from config, tidied, with the home page kept out unless the site says otherwise', function () {
    $list = NeverTouch::fromConfig(['collections' => ['redirects', ' redirects ', ''], 'urls' => ['/legal/*/', 'legal/*'], 'entries' => ['x']]);

    expect($list->collections)->toBe(['redirects'])
        ->and($list->urls)->toBe(['/legal/*'])
        ->and($list->entries)->toBe(['x'])
        ->and(NeverTouch::fromConfig([])->isEmpty())->toBeTrue();
});
