<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Commands;

use Bpmore\StatamicLifecycle\Assignment\OwnerAssigner;
use Bpmore\StatamicLifecycle\Assignment\Selection;
use Bpmore\StatamicLifecycle\Assignment\Selectors\Branch;
use Bpmore\StatamicLifecycle\Assignment\Selectors\ByAuthor;
use Bpmore\StatamicLifecycle\Assignment\Selectors\InCollection;
use Bpmore\StatamicLifecycle\Assignment\Selectors\WithBlueprint;
use Bpmore\StatamicLifecycle\Assignment\Selectors\WithTerm;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Statamic\Console\RunsInPlease;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Site;
use Statamic\Facades\User;

/**
 * Nobody is assigning owners to 40,000 pages one at a time (spec §2). This
 * is the command that does it by the branch, and — as the other selectors
 * arrive — by collection, blueprint, author, term and CSV.
 *
 * It writes entries, so it says exactly what it is about to do, asks, and
 * offers --dry-run. Each saved entry is a change like any other: it shows in
 * git, and git automation commits it where that is on.
 */
class AssignOwner extends Command
{
    use RunsInPlease;

    protected $signature = 'lifecycle:assign
        {--owner= : The owner to assign, by user ID or email}
        {--branch= : Every page at and under this URI in a structured collection}
        {--collection= : Every entry in this collection}
        {--blueprint= : Every entry using this blueprint (within --collection, if given)}
        {--author= : Every entry written or last edited by this user, by ID or email}
        {--term= : Every entry tagged with this term, as taxonomy::slug}
        {--site= : Only this site (a branch is read from this site\'s tree; default: the default site)}
        {--dry-run : Show what would change and change nothing}
        {--force : Do not ask before writing}';

    protected $description = 'Assign a content owner to many entries at once';

    public function handle(OwnerAssigner $assigner): int
    {
        $owner = $this->owner();

        if ($owner === null) {
            return self::FAILURE;
        }

        $site = $this->option('site') ?: null;

        if ($site !== null && ! Site::all()->has($site)) {
            $this->components->error("There is no site called [{$site}].");

            return self::FAILURE;
        }

        try {
            $selection = $this->selection($site);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($selection === null) {
            $this->components->error('Say which entries: --branch=/some/page, --collection=, --blueprint=, --author=, or --term=taxonomy::slug. Give more than one to narrow it down.');

            return self::FAILURE;
        }

        $name = $owner->name() ?: (string) $owner->email();
        $dryRun = (bool) $this->option('dry-run');

        // A first pass without writing, so the numbers can be shown before
        // anybody is asked to confirm them.
        $preview = $assigner->assign($selection->entries, (string) $owner->id(), dryRun: true);

        $this->components->info(sprintf(
            '%s: %s %s; %s would get %s as owner, %s already have them.',
            ucfirst($selection->description),
            number_format($selection->count()),
            $selection->count() === 1 ? 'entry' : 'entries',
            number_format(count($preview->assigned)),
            $name,
            number_format(count($preview->unchanged)),
        ));

        if ($preview->assigned === []) {
            $this->components->info('Nothing to change.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->components->warn('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(
            sprintf('Write content_owner on %s %s? Each is saved like an edit.', number_format(count($preview->assigned)), count($preview->assigned) === 1 ? 'entry' : 'entries'),
            true,
        )) {
            $this->components->warn('Nothing was changed.');

            return self::FAILURE;
        }

        $result = $assigner->assign($selection->entries, (string) $owner->id());

        $this->components->info(sprintf(
            'Assigned %s to %s %s, and recomputed their review state.',
            $name,
            number_format(count($result->assigned)),
            count($result->assigned) === 1 ? 'entry' : 'entries',
        ));

        return self::SUCCESS;
    }

    private function owner(): ?UserContract
    {
        $handle = (string) $this->option('owner');

        if ($handle === '') {
            $this->components->error('Say who: --owner=<user ID or email>.');

            return null;
        }

        $user = User::find($handle) ?? (str_contains($handle, '@') ? User::findByEmail($handle) : null);

        if ($user === null) {
            $this->components->error("There is no user with the ID or email [{$handle}].");

            return null;
        }

        return $user;
    }

    /**
     * Which entries. More than one selector narrows: --collection=news
     * --author=sam is Sam's entries in news.
     */
    private function selection(?string $site): ?Selection
    {
        $selections = [];

        if ($uri = $this->option('branch')) {
            $selections[] = (new Branch)->select((string) $uri, $site);
        }

        if (($blueprint = $this->option('blueprint')) !== null && $blueprint !== '') {
            // Blueprint takes the collection with it, so the description reads
            // "every article entry in news" rather than two clauses.
            $selections[] = (new WithBlueprint)->select((string) $blueprint, $this->option('collection') ?: null, $site);
        } elseif (($collection = $this->option('collection')) !== null && $collection !== '') {
            $selections[] = (new InCollection)->select((string) $collection, $site);
        }

        if (($author = $this->option('author')) !== null && $author !== '') {
            $selections[] = (new ByAuthor)->select((string) $author, $site);
        }

        if (($term = $this->option('term')) !== null && $term !== '') {
            $selections[] = (new WithTerm)->select((string) $term, $site);
        }

        if ($selections === []) {
            return null;
        }

        $selection = array_shift($selections);

        foreach ($selections as $other) {
            $ids = $other->entries->map(fn ($entry): string => (string) $entry->id())->flip();

            $selection = new Selection(
                $selection->description.' '.__('and').' '.$other->description,
                $selection->entries->filter(fn ($entry): bool => $ids->has((string) $entry->id()))->values(),
            );
        }

        return $selection;
    }
}
