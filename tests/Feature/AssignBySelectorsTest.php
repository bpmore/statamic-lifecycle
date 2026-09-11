<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Assignment\Selectors\ByAuthor;
use Bpmore\StatamicLifecycle\Assignment\Selectors\InCollection;
use Bpmore\StatamicLifecycle\Assignment\Selectors\WithBlueprint;
use Bpmore\StatamicLifecycle\Assignment\Selectors\WithTerm;
use Bpmore\StatamicLifecycle\Blueprint\LifecycleFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

/**
 * A small site with enough variety for every selector: two collections,
 * two blueprints in one of them, a taxonomy, two authors, and a draft.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-11 15:00:00');
    Blueprint::setDirectory($this->fakeStacheDirectory.'/blueprints');

    User::make()->id('sam')->email('sam@example.edu')->data(['name' => 'Sam Okafor'])->save();
    User::make()->id('priya')->email('priya@example.edu')->data(['name' => 'Priya Nair'])->save();

    Taxonomy::make('topics')->save();
    Term::make('cardiology')->taxonomy('topics')->data(['title' => 'Cardiology'])->save();
    Term::make('oncology')->taxonomy('topics')->data(['title' => 'Oncology'])->save();

    // Both collections use the taxonomy: a term is only a relationship in a
    // collection attached to its taxonomy.
    Collection::make('news')->taxonomies(['topics'])->save();
    Collection::make('pages')->taxonomies(['topics'])->save();

    $fields = ['tabs' => ['main' => ['sections' => [['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]]]]]];
    Blueprint::make('news')->setNamespace('collections.news')->setContents($fields)->save();
    Blueprint::make('press_release')->setNamespace('collections.news')->setContents($fields)->save();

    $make = fn (string $id, string $collection, array $data, bool $published = true) => Entry::make()->id($id)->collection($collection)->slug($id)->published($published)->data(array_merge(['title' => ucfirst($id)], $data))->save();

    $make('n1', 'news', ['topics' => ['cardiology'], 'author' => 'sam']);
    $make('n2', 'news', ['topics' => ['cardiology', 'oncology'], 'blueprint' => 'press_release', 'updated_by' => 'sam']);
    $make('n3', 'news', ['blueprint' => 'press_release', 'author' => ['priya', 'sam']]);
    $make('n4', 'news', ['updated_by' => 'priya'], published: false);
    $make('p1', 'pages', ['topics' => ['cardiology'], 'author' => 'sam']);
    $make('p2', 'pages', []);
});

afterEach(fn () => Carbon::setTestNow());

$ids = fn ($selection): array => $selection->entries->map(fn ($e): string => (string) $e->id())->sort()->values()->all();

describe('by collection', function () use ($ids) {
    it('is every entry in the collection, drafts included', function () use ($ids) {
        $selection = (new InCollection)->select('news');

        expect($ids($selection))->toBe(['n1', 'n2', 'n3', 'n4'])
            ->and($selection->description)->toBe('every entry in news');
    });

    it('refuses a collection that does not exist', function () {
        expect(fn () => (new InCollection)->select('nope'))
            ->toThrow(InvalidArgumentException::class, 'There is no collection called [nope].');
    });
});

describe('by blueprint', function () use ($ids) {
    it('is every entry using the blueprint, within a collection', function () use ($ids) {
        $selection = (new WithBlueprint)->select('press_release', 'news');

        expect($ids($selection))->toBe(['n2', 'n3'])
            ->and($selection->description)->toBe('every press_release entry in news');
    });

    it('counts the collection default for entries that do not name a blueprint', function () use ($ids) {
        expect($ids((new WithBlueprint)->select('news', 'news')))->toBe(['n1', 'n4']);
    });

    it('is every entry using the blueprint anywhere, when no collection is given', function () use ($ids) {
        $selection = (new WithBlueprint)->select('press_release');

        expect($ids($selection))->toBe(['n2', 'n3'])
            ->and($selection->description)->toBe('every entry using the press_release blueprint');
    });

    it('refuses a blueprint the collection does not have, or no collection has', function () {
        expect(fn () => (new WithBlueprint)->select('press_release', 'pages'))
            ->toThrow(InvalidArgumentException::class, 'The [pages] collection has no blueprint called [press_release].');

        expect(fn () => (new WithBlueprint)->select('memo'))
            ->toThrow(InvalidArgumentException::class, 'No collection has a blueprint called [memo].');

        expect(fn () => (new WithBlueprint)->select('press_release', 'nope'))
            ->toThrow(InvalidArgumentException::class, 'There is no collection called [nope].');
    });
});

describe('by author', function () use ($ids) {
    it('is what the person wrote and what they last edited, across collections', function () use ($ids) {
        $selection = (new ByAuthor)->select('sam');

        // n1: author. n2: last edited. n3: one of two authors. p1: author.
        expect($ids($selection))->toBe(['n1', 'n2', 'n3', 'p1'])
            ->and($selection->description)->toBe('every entry written or last edited by Sam Okafor');
    });

    it('finds the person by email too', function () use ($ids) {
        expect($ids((new ByAuthor)->select('priya@example.edu')))->toBe(['n3', 'n4']);
    });

    it('refuses a person who does not exist', function () {
        expect(fn () => (new ByAuthor)->select('nobody'))
            ->toThrow(InvalidArgumentException::class, 'There is no user with the ID or email [nobody].');
    });
});

describe('by term', function () use ($ids) {
    it('is every entry tagged with the term, across collections', function () use ($ids) {
        $selection = (new WithTerm)->select('topics::cardiology');

        expect($ids($selection))->toBe(['n1', 'n2', 'p1'])
            ->and($selection->description)->toBe('every entry tagged cardiology in topics');
    });

    it('needs taxonomy::slug, and both to exist', function () {
        expect(fn () => (new WithTerm)->select('cardiology'))
            ->toThrow(InvalidArgumentException::class, 'Name the term as taxonomy::slug');

        expect(fn () => (new WithTerm)->select('tags::cardiology'))
            ->toThrow(InvalidArgumentException::class, 'There is no taxonomy called [tags].');

        expect(fn () => (new WithTerm)->select('topics::dermatology'))
            ->toThrow(InvalidArgumentException::class, 'There is no term [dermatology] in the [topics] taxonomy.');
    });
});

describe('by site', function () use ($ids) {
    it('narrows any selector to one site', function () use ($ids) {
        Site::setSites([
            'default' => ['name' => 'English', 'locale' => 'en_US', 'url' => '/'],
            'fr' => ['name' => 'French', 'locale' => 'fr_FR', 'url' => '/fr/'],
        ]);
        Collection::make('news')->taxonomies(['topics'])->sites(['default', 'fr'])->save();
        Entry::make()->id('n1-fr')->collection('news')->locale('fr')->origin('n1')->slug('n1-fr')->data(['title' => 'N1 fr'])->save();

        expect($ids((new InCollection)->select('news', 'fr')))->toBe(['n1-fr'])
            ->and((new InCollection)->select('news', 'fr')->description)->toBe('every entry in news in the fr site')
            ->and($ids((new InCollection)->select('news')))->toBe(['n1', 'n1-fr', 'n2', 'n3', 'n4']);
    });
});

describe('the command', function () {
    it('narrows with more than one selector, and says so', function () {
        $this->artisan('lifecycle:assign', ['--owner' => 'priya', '--collection' => 'news', '--author' => 'sam', '--dry-run' => true])
            ->expectsOutputToContain('Every entry in news and every entry written or last edited by Sam Okafor: 3 entries; 3 would get Priya Nair as owner, 0 already have them.')
            ->assertExitCode(0);

        $this->artisan('lifecycle:assign', ['--owner' => 'priya', '--term' => 'topics::cardiology', '--blueprint' => 'press_release', '--collection' => 'news', '--dry-run' => true])
            ->expectsOutputToContain('Every press_release entry in news and every entry tagged cardiology in topics: 1 entry; 1 would get Priya Nair as owner, 0 already have them.')
            ->assertExitCode(0);
    });

    it('assigns by term, and only what the term touches', function () {
        $this->artisan('lifecycle:assign', ['--owner' => 'priya', '--term' => 'topics::cardiology', '--force' => true])
            ->expectsOutputToContain('Assigned Priya Nair to 3 entries, and recomputed their review state.')
            ->assertExitCode(0);

        foreach (['n1', 'n2', 'p1'] as $id) {
            expect(Entry::find($id)->get(LifecycleFields::CONTENT_OWNER))->toBe('priya');
        }
        foreach (['n3', 'n4', 'p2'] as $id) {
            expect(Entry::find($id)->get(LifecycleFields::CONTENT_OWNER))->toBeNull();
        }
    });

    it('reports a selector\'s complaint in its own words', function () {
        $this->artisan('lifecycle:assign', ['--owner' => 'priya', '--term' => 'topics::dermatology'])
            ->expectsOutputToContain('There is no term [dermatology] in the [topics] taxonomy.')
            ->assertExitCode(1);

        $this->artisan('lifecycle:assign', ['--owner' => 'priya', '--blueprint' => 'memo'])
            ->expectsOutputToContain('No collection has a blueprint called [memo].')
            ->assertExitCode(1);
    });
});
