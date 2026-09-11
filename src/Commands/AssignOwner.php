<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Commands;

use Bpmore\StatamicLifecycle\Assignment\OwnerAssigner;
use Bpmore\StatamicLifecycle\Assignment\Selection;
use Bpmore\StatamicLifecycle\Assignment\Selectors\Branch;
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
        {--site= : The site whose tree the branch is read from (default: the default site)}
        {--dry-run : Show what would change and change nothing}
        {--force : Do not ask before writing}';

    protected $description = 'Assign a content owner to many entries at once';

    public function handle(Branch $branch, OwnerAssigner $assigner): int
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
            $selection = $this->selection($branch, $site);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($selection === null) {
            $this->components->error('Say which entries: --branch=/some/page.');

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

    /** The one selector so far; the rest of Phase 4 adds the others here. */
    private function selection(Branch $branch, ?string $site): ?Selection
    {
        if ($uri = $this->option('branch')) {
            return $branch->select((string) $uri, $site);
        }

        return null;
    }
}
