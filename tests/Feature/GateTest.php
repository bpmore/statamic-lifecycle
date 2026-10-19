<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Assignment\OwnerAssigner;
use Bpmore\StatamicLifecycle\Expiry\ActionApplier;
use Bpmore\StatamicLifecycle\Expiry\ActionType;
use Bpmore\StatamicLifecycle\Gate\GateResult;
use Bpmore\StatamicLifecycle\Gate\GateSettings;
use Bpmore\StatamicLifecycle\Gate\PublishGate;
use Bpmore\StatamicLifecycle\Listeners\RefuseUnlessOwnedAndDated;
use Bpmore\StatamicLifecycle\Models\Creation;
use Bpmore\StatamicLifecycle\Storage\Creations;
use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Statamic\Facades\User;

/**
 * The gate (spec §7): a confirmed owner and a review date before an entry
 * publishes. Opt-in per collection, off by default, never the backlog
 * unless asked, and the refusal names the missing thing where it is set.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-10-15 10:00:00');
    RefuseUnlessOwnedAndDated::forgetReportedProblems();
    Collection::make('policies')->title('Policies')->save();
    Collection::make('news')->title('News')->save();
    User::make()->id('alice')->email('alice@example.edu')->data(['name' => 'Alice Ahmed'])->save();
});

afterEach(fn () => Carbon::setTestNow());

/** Save, and hand back the refusal rather than throwing it. */
function refusal(callable $save): ?ValidationException
{
    try {
        $save();

        return null;
    } catch (ValidationException $e) {
        return $e;
    }
}

/**
 * The entry as saved, not as the object a refused save was called on: a
 * refusal leaves the in-memory entry mutated, the way a request's would be,
 * and only the store says what actually happened.
 */
function persisted(string $id): ?Statamic\Contracts\Entries\Entry
{
    Stache::clear();

    return Entry::find($id);
}

function policyEntry(string $id, bool $published = true, array $data = []): Statamic\Contracts\Entries\Entry
{
    return Entry::make()->id($id)->collection('policies')->slug($id)->published($published)->data(['title' => ucfirst($id)] + $data);
}

describe('off by default', function () {
    it('lets an entry with neither owner nor date publish', function () {
        expect(config('lifecycle.gate.collections'))->toBe([]);

        policyEntry('handbook')->save();

        expect(Entry::find('handbook'))->not->toBeNull();
    });

    it('gates only the collections named', function () {
        config(['lifecycle.gate.collections' => ['policies' => 'all']]);

        Entry::make()->id('story')->collection('news')->slug('story')->published(true)->data(['title' => 'Story'])->save();

        expect(Entry::find('story'))->not->toBeNull();
    });
});

describe('the refusal', function () {
    beforeEach(fn () => config(['lifecycle.gate.collections' => ['policies' => 'all']]));

    it('names each missing thing under the field where it is set, and saves nothing', function () {
        $refusal = refusal(fn () => policyEntry('handbook')->save());

        expect($refusal)->not->toBeNull()
            ->and($refusal->status)->toBe(422)
            ->and($refusal->errors())->toBe([
                'content_owner' => ['Choose a content owner before publishing. Every entry in Policies needs one.'],
                'review_by' => ['Set a review-by date or a review interval before publishing. Every entry in Policies needs one.'],
            ])
            ->and(Entry::find('handbook'))->toBeNull();
    });

    it('asks only for what is missing', function () {
        $refusal = refusal(fn () => policyEntry('handbook', data: ['content_owner' => 'alice'])->save());

        expect(array_keys($refusal->errors()))->toBe(['review_by']);

        $refusal = refusal(fn () => policyEntry('charter', data: ['review_interval' => 'quarterly'])->save());

        expect(array_keys($refusal->errors()))->toBe(['content_owner']);
    });

    it('lets an owned entry with a review interval through, or with a review-by date', function () {
        policyEntry('handbook', data: ['content_owner' => 'alice', 'review_interval' => 'yearly'])->save();
        policyEntry('charter', data: ['content_owner' => ['alice'], 'review_by' => '2027-03-01'])->save();

        expect(Entry::find('handbook'))->not->toBeNull()->and(Entry::find('charter'))->not->toBeNull();
    });

    it('asks an exempt page for an owner only: "never" is its answer to "when"', function () {
        policyEntry('archive', data: ['content_owner' => 'alice', 'review_exempt' => true])->save();

        expect(Entry::find('archive'))->not->toBeNull();

        $refusal = refusal(fn () => policyEntry('orphan-archive', data: ['review_exempt' => true])->save());

        expect(array_keys($refusal->errors()))->toBe(['content_owner']);
    });

    it('does not count an inferred owner: the person named on the page, or nobody', function () {
        // The last author would be inferred as owner by the computation; the gate wants a decision.
        $refusal = refusal(fn () => policyEntry('handbook', data: ['updated_by' => 'alice', 'review_interval' => 'yearly'])->save());

        expect(array_keys($refusal->errors()))->toBe(['content_owner']);
    });

    it('does not count an owner who is no longer a user', function () {
        $refusal = refusal(fn () => policyEntry('handbook', data: ['content_owner' => 'left-last-year', 'review_interval' => 'yearly'])->save());

        expect($refusal->errors())->toBe([
            'content_owner' => ['The content owner named here is no longer a user. Choose another before publishing.'],
        ]);
    });

    it('never gates a draft', function () {
        policyEntry('draft', published: false)->save();

        expect(Entry::find('draft'))->not->toBeNull()->and(Entry::find('draft')->published())->toBeFalse();
    });

    it('gates the draft when it is published later', function () {
        policyEntry('draft', published: false)->save();

        $refusal = refusal(fn () => Entry::find('draft')->published(true)->save());

        expect($refusal)->not->toBeNull()->and(persisted('draft')->published())->toBeFalse();
    });

    it('is what PublishGate decides', function () {
        $result = app(PublishGate::class)->inspect(policyEntry('handbook'));

        expect($result->shouldRefuse())->toBeTrue()
            ->and($result->missing)->toBe([GateResult::OWNER, GateResult::REVIEW_DATE])
            ->and($result->collectionTitle)->toBe('Policies')
            ->and(app(PublishGate::class)->inspect(policyEntry('handbook', published: false))->reason)->toBe('a draft');
    });
});

describe('the backlog', function () {
    it('is never gated by a dated rule: only entries created on or after the date are', function () {
        // Before the gate: an entry created in September, published without either.
        Carbon::setTestNow('2026-09-20 10:00:00');
        policyEntry('old')->save();
        policyEntry('old-draft', published: false)->save();

        Carbon::setTestNow('2026-10-15 10:00:00');
        config(['lifecycle.gate.collections' => ['policies' => '2026-10-01']]);

        // Re-saving the old entry, and publishing the old draft: neither is gated.
        Entry::find('old')->set('title', 'Old, edited')->save();
        Entry::find('old-draft')->published(true)->save();
        expect(Entry::find('old')->get('title'))->toBe('Old, edited')
            ->and(Entry::find('old-draft')->published())->toBeTrue();

        // Created after the date: gated, whether published on creation or later.
        expect(refusal(fn () => policyEntry('new')->save()))->not->toBeNull();
        policyEntry('new-draft', published: false)->save();
        expect(refusal(fn () => Entry::find('new-draft')->published(true)->save()))->not->toBeNull()
            ->and(persisted('new-draft')->published())->toBeFalse();
    });

    it('treats an entry the addon never saw created as older than the gate', function () {
        Entry::make()->id('imported')->collection('policies')->slug('imported')->published(true)->data(['title' => 'Imported'])->saveQuietly();
        expect(Creation::query()->find('imported'))->toBeNull();

        config(['lifecycle.gate.collections' => ['policies' => '2020-01-01']]);

        Entry::find('imported')->set('title', 'Imported, edited')->save();

        expect(Entry::find('imported')->get('title'))->toBe('Imported, edited');
    });

    it('is gated when the site says all', function () {
        Carbon::setTestNow('2026-09-20 10:00:00');
        policyEntry('old')->save();

        Carbon::setTestNow('2026-10-15 10:00:00');
        config(['lifecycle.gate.collections' => ['policies' => 'all']]);

        expect(refusal(fn () => Entry::find('old')->set('title', 'Old, edited')->save()))->not->toBeNull()
            ->and(persisted('old')->get('title'))->toBe('Old');
    });
});

describe('the creation record', function () {
    it('is written when an entry is created, once, and removed with the entry', function () {
        policyEntry('handbook', published: false)->save();

        $row = Creation::query()->find('handbook');
        expect($row)->not->toBeNull()
            ->and($row->site)->toBe('default')
            ->and($row->collection)->toBe('policies')
            ->and($row->created_at->format('Y-m-d H:i:s'))->toBe('2026-10-15 10:00:00');

        Carbon::setTestNow('2026-10-16 10:00:00');
        Entry::find('handbook')->set('title', 'Edited')->save();
        expect(Creation::query()->find('handbook')->created_at->format('Y-m-d'))->toBe('2026-10-15');

        Entry::find('handbook')->delete();
        expect(Creation::query()->find('handbook'))->toBeNull();
    });

    it('does not stop an entry being created when the tables are not installed', function () {
        Schema::connection(LifecycleDatabase::connectionName())->rename('lifecycle_states', 'lifecycle_states_absent');
        expect(app(LifecycleDatabase::class)->isInstalled())->toBeFalse();

        policyEntry('handbook', published: false)->save();

        expect(Entry::find('handbook'))->not->toBeNull()->and(Creation::query()->count())->toBe(0);
    });

    it('is asked for by the gate only when there is a date to compare with', function () {
        config(['lifecycle.gate.collections' => ['policies' => 'all']]);
        expect(app(Creations::class)->createdAt('nothing'))->toBeNull();

        // No record, and 'all': still gated.
        Entry::make()->id('imported')->collection('policies')->slug('imported')->published(true)->data(['title' => 'Imported'])->saveQuietly();
        expect(refusal(fn () => Entry::find('imported')->set('title', 'Edited')->save()))->not->toBeNull();
    });
});

describe('the configuration', function () {
    it('reads a date, or all, per collection', function () {
        $settings = new GateSettings(['policies' => '2026-10-01', 'news' => 'all', 'pages' => null, 'blog' => false]);

        expect($settings->isOn())->toBeTrue()
            ->and($settings->for('policies')?->since?->format('Y-m-d H:i'))->toBe('2026-10-01 00:00')
            ->and($settings->for('policies')->coversEverything())->toBeFalse()
            ->and($settings->for('news')->coversEverything())->toBeTrue()
            ->and($settings->for('pages'))->toBeNull()
            ->and($settings->for('blog'))->toBeNull()
            ->and($settings->problems())->toBe([]);
    });

    it('refuses a bare true, which names no day, and says so once', function () {
        $settings = new GateSettings(['policies' => true, 'news' => 'soon']);

        expect($settings->isOn())->toBeFalse()
            ->and($settings->problems())->toHaveCount(2)
            ->and($settings->problems()[0])->toContain('[policies] is set to true, which does not say from when')
            ->and($settings->problems()[1])->toContain("[news] is set to ['soon'], which is not a date or 'all'");

        config(['lifecycle.gate.collections' => ['policies' => true]]);
        Log::shouldReceive('warning')->once()->withArgs(fn (string $m): bool => str_contains($m, '[policies] is set to true'));

        policyEntry('handbook')->save();
        policyEntry('charter')->save();   // the second save does not log again

        expect(Entry::find('handbook'))->not->toBeNull()->and(Entry::find('charter'))->not->toBeNull();
    });
});

describe('the addon\'s own writes', function () {
    beforeEach(function () {
        Carbon::setTestNow('2026-09-20 10:00:00');
        policyEntry('old')->save();
        Carbon::setTestNow('2026-10-15 10:00:00');
        config(['lifecycle.gate.collections' => ['policies' => 'all']]);
    });

    it('assign an owner to an entry the gate would otherwise refuse', function () {
        $assignment = app(OwnerAssigner::class)->assign([Entry::find('old')], 'alice');

        expect($assignment->assigned)->toBe(['old'])
            ->and(Entry::find('old')->get('content_owner'))->toBe('alice');
    });

    it('set an expiry flag on an entry the gate would otherwise refuse', function () {
        app(ActionApplier::class)->apply(Entry::find('old'), ActionType::Banner);

        expect(Entry::find('old')->get('lifecycle_banner'))->toBeTrue();
    });

    it('are the only way past: the gate is back the moment the callback returns', function () {
        expect(PublishGate::isSuspended())->toBeFalse();

        PublishGate::suspended(function () {
            expect(PublishGate::isSuspended())->toBeTrue();
            Entry::find('old')->set('title', 'Old, edited')->save();
        });

        expect(PublishGate::isSuspended())->toBeFalse()
            ->and(Entry::find('old')->get('title'))->toBe('Old, edited')
            ->and(refusal(fn () => Entry::find('old')->set('title', 'Again')->save()))->not->toBeNull();
    });
});
