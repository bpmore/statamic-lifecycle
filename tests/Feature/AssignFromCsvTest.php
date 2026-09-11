<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Assignment\Csv\OwnerCsv;
use Bpmore\StatamicLifecycle\Blueprint\LifecycleFields;
use Bpmore\StatamicLifecycle\Models\EntryState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-11 15:00:00');
    User::make()->id('sam')->email('sam@example.edu')->data(['name' => 'Sam Okafor'])->save();
    User::make()->id('priya')->email('priya@example.edu')->data(['name' => 'Priya Nair'])->save();

    Collection::make('pages')->routes('/{slug}')->save();
    foreach (['cardiology', 'oncology', 'radiology', 'pharmacy'] as $slug) {
        Entry::make()->id("id-{$slug}")->collection('pages')->slug($slug)->published(true)->data(['title' => ucfirst($slug)])->save();
    }

    $this->csv = function (string $contents): string {
        $path = sys_get_temp_dir().'/owners-'.uniqid().'.csv';
        file_put_contents($path, $contents);

        return $path;
    };
});

afterEach(fn () => Carbon::setTestNow());

describe('reading the file', function () {
    it('takes a URL, a URI or an ID for the entry, and an ID or an email for the owner', function () {
        $plan = (new OwnerCsv)->read(($this->csv)(
            "entry,owner\n".
            "https://example.edu/cardiology,sam\n".
            "/oncology,priya@example.edu\n".
            "id-radiology,priya\n"
        ));

        expect($plan->rows)->toBe(3)
            ->and($plan->entries())->toBe(3)
            ->and($plan->owners())->toBe(2)
            ->and($plan->problems)->toBe([])
            ->and($plan->byOwner['sam']->map->id()->all())->toBe(['id-cardiology'])
            ->and($plan->byOwner['priya']->map->id()->all())->toBe(['id-oncology', 'id-radiology'])
            ->and($plan->ownerNames)->toBe(['sam' => 'Sam Okafor', 'priya' => 'Priya Nair']);
    });

    it('works without a header, with a BOM, with blank lines, and with stray whitespace', function () {
        $plan = (new OwnerCsv)->read(($this->csv)(
            "\xEF\xBB\xBF /cardiology , sam \n".
            "\n".
            "  id-oncology,priya@example.edu\n".
            ",\n"
        ));

        expect($plan->rows)->toBe(2)
            ->and($plan->problems)->toBe([])
            ->and($plan->byOwner['sam']->map->id()->all())->toBe(['id-cardiology'])
            ->and($plan->byOwner['priya']->map->id()->all())->toBe(['id-oncology']);
    });

    it('finds the columns by their headers, whatever order and whatever else is there', function () {
        $plan = (new OwnerCsv)->read(($this->csv)(
            "Department,Email,Notes,URL\n".
            "Cardiology,sam@example.edu,busy,/cardiology\n"
        ));

        expect($plan->byOwner['sam']->map->id()->all())->toBe(['id-cardiology']);
    });

    it('reports what it cannot use, by line, and carries on', function () {
        $plan = (new OwnerCsv)->read(($this->csv)(
            "entry,owner\n".
            "/cardiology,sam\n".
            "/nowhere,sam\n".
            "/oncology,ghost@example.edu\n".
            ",sam\n".
            "/radiology,\n".
            "/pharmacy,priya\n"
        ));

        expect($plan->rows)->toBe(6)
            ->and($plan->entries())->toBe(2)
            ->and(array_map(fn ($p): string => "{$p->line}: {$p->reason}", $plan->problems))->toBe([
                '3: no entry at [/nowhere]',
                '4: no user with the ID or email [ghost@example.edu]',
                '5: no entry',
                '6: no owner, and no --owner to fall back on',
            ]);
    });

    it('fills an empty owner cell from the default, when there is one', function () {
        $plan = (new OwnerCsv)->read(($this->csv)("/cardiology,\n/oncology,priya\n"), defaultOwner: 'sam@example.edu');

        expect($plan->problems)->toBe([])
            ->and($plan->byOwner['sam']->map->id()->all())->toBe(['id-cardiology'])
            ->and($plan->byOwner['priya']->map->id()->all())->toBe(['id-oncology']);
    });

    it('lets a later row for the same entry win, once', function () {
        $plan = (new OwnerCsv)->read(($this->csv)("/cardiology,sam\n/oncology,sam\n/cardiology,priya\n/cardiology,priya\n"));

        expect($plan->byOwner['sam']->map->id()->all())->toBe(['id-cardiology', 'id-oncology'])
            ->and($plan->byOwner['priya']->map->id()->all())->toBe(['id-cardiology']);
        // The assigner runs per owner in file order: sam first, then priya —
        // so priya's later row is what the entry ends up with.
    });

    it('refuses a file it cannot read, or a default owner who does not exist', function () {
        expect(fn () => (new OwnerCsv)->read('/nowhere/owners.csv'))
            ->toThrow(InvalidArgumentException::class, 'Cannot read [/nowhere/owners.csv].');

        expect(fn () => (new OwnerCsv)->read(($this->csv)("/cardiology,sam\n"), defaultOwner: 'ghost'))
            ->toThrow(InvalidArgumentException::class, 'There is no user with the ID or email [ghost].');
    });

    it('resolves a URI in the site asked for, or in whichever site has it, with or without the site\'s prefix', function () {
        // Multisite is a system switch in Statamic 6; without it a collection
        // quietly keeps only the default site, and routes nothing elsewhere.
        config(['statamic.system.multisite' => true]);
        Site::setSites([
            'default' => ['name' => 'English', 'locale' => 'en_US', 'url' => '/'],
            'fr' => ['name' => 'French', 'locale' => 'fr_FR', 'url' => '/fr/'],
        ]);
        Collection::make('pages')->routes('/{slug}')->sites(['default', 'fr'])->save();
        Entry::make()->id('id-cardiologie')->collection('pages')->locale('fr')->origin('id-cardiology')->slug('cardiologie')->published(true)->data(['title' => 'Cardiologie'])->save();

        $ids = fn (string $csv, ?string $site = null): array => (new OwnerCsv)->read(($this->csv)($csv), $site)->byOwner['sam']?->map->id()->all() ?? [];

        expect($ids("/cardiologie,sam\n"))->toBe(['id-cardiologie'])
            ->and($ids("/cardiologie,sam\n", 'fr'))->toBe(['id-cardiologie'])
            // Pasted from a browser: the site's prefix is on the front.
            ->and($ids("https://example.edu/fr/cardiologie,sam\n"))->toBe(['id-cardiologie'])
            ->and($ids("/fr/cardiologie,sam\n", 'fr'))->toBe(['id-cardiologie'])
            ->and($ids("https://example.edu/cardiology,sam\n"))->toBe(['id-cardiology'])
            ->and((new OwnerCsv)->read(($this->csv)("/cardiologie,sam\n"), site: 'default')->problems[0]->reason)->toBe('no entry at [/cardiologie]');
    });
});

describe('the command', function () {
    it('previews per owner, lists the rows it could not use, and writes nothing on a dry run', function () {
        $path = ($this->csv)("entry,owner\n/cardiology,sam\n/oncology,priya\n/radiology,priya\n/nowhere,sam\n");

        $this->artisan('lifecycle:assign', ['--csv' => $path, '--dry-run' => true])
            ->expectsOutputToContain(basename($path).': 4 rows naming 3 entries for 2 owners.')
            ->expectsOutputToContain('1 row could not be used:')
            ->expectsOutputToContain('line 5: no entry at [/nowhere]')
            ->expectsOutputToContain('Sam Okafor')
            ->expectsOutputToContain('Priya Nair')
            ->expectsOutputToContain('Dry run: nothing was written.')
            ->assertExitCode(0);

        expect(Entry::find('id-cardiology')->get(LifecycleFields::CONTENT_OWNER))->toBeNull();
    });

    it('assigns each row its own owner, and recomputes', function () {
        $path = ($this->csv)("entry,owner\n/cardiology,sam\n/oncology,priya\n/radiology,priya\n");

        $this->artisan('lifecycle:assign', ['--csv' => $path])
            ->expectsConfirmation('Write content_owner on 3 entries? Each is saved like an edit.', 'yes')
            ->expectsOutputToContain('Assigned owners to 3 entries, and recomputed their review state.')
            ->assertExitCode(0);

        expect(Entry::find('id-cardiology')->get(LifecycleFields::CONTENT_OWNER))->toBe('sam')
            ->and(Entry::find('id-oncology')->get(LifecycleFields::CONTENT_OWNER))->toBe('priya')
            ->and(Entry::find('id-radiology')->get(LifecycleFields::CONTENT_OWNER))->toBe('priya')
            ->and(Entry::find('id-pharmacy')->get(LifecycleFields::CONTENT_OWNER))->toBeNull()
            ->and(EntryState::query()->find('id-oncology')->owner_source)->toBe('assigned');
    });

    it('uses --owner for rows that name none', function () {
        $path = ($this->csv)("/cardiology,\n/oncology,priya\n");

        $this->artisan('lifecycle:assign', ['--csv' => $path, '--owner' => 'sam', '--force' => true])->assertExitCode(0);

        expect(Entry::find('id-cardiology')->get(LifecycleFields::CONTENT_OWNER))->toBe('sam')
            ->and(Entry::find('id-oncology')->get(LifecycleFields::CONTENT_OWNER))->toBe('priya');
    });

    it('does the good rows and still exits non-zero when some rows were bad, so a script notices', function () {
        $path = ($this->csv)("/cardiology,sam\n/nowhere,sam\n");

        $this->artisan('lifecycle:assign', ['--csv' => $path, '--force' => true])
            ->expectsOutputToContain('Assigned owners to 1 entry')
            ->assertExitCode(1);

        expect(Entry::find('id-cardiology')->get(LifecycleFields::CONTENT_OWNER))->toBe('sam');
    });

    it('says when the file is missing, or has nothing usable', function () {
        $this->artisan('lifecycle:assign', ['--csv' => '/nowhere/owners.csv'])
            ->expectsOutputToContain('Cannot read [/nowhere/owners.csv].')
            ->assertExitCode(1);

        $this->artisan('lifecycle:assign', ['--csv' => ($this->csv)("/nowhere,sam\n")])
            ->expectsOutputToContain('Nothing usable in the file.')
            ->assertExitCode(1);
    });
});
