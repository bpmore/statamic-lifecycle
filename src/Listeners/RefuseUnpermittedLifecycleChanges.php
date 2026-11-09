<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Listeners;

use Bpmore\StatamicLifecycle\Auth\Permissions;
use Bpmore\StatamicLifecycle\Blueprint\LifecycleFields;
use Bpmore\StatamicLifecycle\Gate\PublishGate;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Statamic\Contracts\Entries\Entry;
use Statamic\Events\EntrySaving;
use Statamic\Facades\User;
use Statamic\Facades\YAML;

/**
 * The permissions, at the moment an entry is saved.
 *
 * `assign owners` and `manage exemptions` are asked by the bulk actions, and
 * AddLifecycleFieldsToBlueprint shows the two fields read-only to anyone
 * without them. A read-only field is a thing the publish form draws; the
 * request it sends still carries every value, and a request can be written
 * by hand. So the same question is asked here, of the saved values, and a
 * change by someone who may not make it is refused.
 *
 * Refused the way the gate refuses (RefuseUnlessOwnedAndDated): a
 * ValidationException keyed by the field, so the message lands beside the
 * field in the control panel rather than as "Couldn't save entry".
 *
 * Only a change is refused. Saving an entry whose owner was set by somebody
 * else, with the owner as it was, is editing, and editing needs no
 * permission of ours.
 */
final class RefuseUnpermittedLifecycleChanges
{
    public function handle(EntrySaving $event): void
    {
        // The addon's own writes (the actions, the expiry run) have already
        // asked the permission of whoever asked for them, and a command or a
        // queue has nobody at a form to hold back.
        if (PublishGate::isSuspended() || ($user = User::current()) === null) {
            return;
        }

        $messages = [];

        foreach (Permissions::guardedFields() as $handle => $permission) {
            if ($user->can($permission) || ! self::changed($event->entry, $handle)) {
                continue;
            }

            $messages[$handle] = [self::message($handle, $permission)];
        }

        if ($messages === []) {
            return;
        }

        throw ValidationException::withMessages($messages)->status(422);
    }

    /**
     * Whether the saved value differs from the one already stored.
     *
     * Compared with the file, not with the entry's own memory of itself:
     * Entry::save() begins by asking the repository for the entry, and a
     * Stache cache that hands back the same object (the array driver does)
     * re-syncs its "original" to the very values being saved, so by the
     * time EntrySaving fires getOriginal() agrees with everything. The file
     * is what is stored, which is what the question is about; it is the
     * same comparison RecordEditAsReview makes. An entry not kept in a file
     * falls back to that memory, which is empty for a new entry.
     */
    private static function changed(Entry $entry, string $handle): bool
    {
        return ! self::same($handle, $entry->get($handle), self::stored($entry)[$handle] ?? null);
    }

    /** @return array<string, mixed> the entry's data as stored, or nothing for a new entry */
    private static function stored(Entry $entry): array
    {
        $path = $entry->initialPath() ?? $entry->path();

        if (is_string($path) && is_file($path)) {
            $data = YAML::file($path)->parse();

            return is_array($data) ? $data : [];
        }

        return $entry->getOriginal();
    }

    /**
     * The same decision, whatever shape it was stored in: a users field with
     * one item is a string in a file and a one-item list from the form; a
     * toggle is a boolean in a file and may be "true" or 1 from an import,
     * and absent means not exempt.
     */
    private static function same(string $handle, mixed $a, mixed $b): bool
    {
        if ($handle === LifecycleFields::REVIEW_EXEMPT) {
            return LifecycleFields::isExemptValue($a) === LifecycleFields::isExemptValue($b);
        }

        return self::ids($a) === self::ids($b);
    }

    /** @return list<string> */
    private static function ids(mixed $value): array
    {
        $ids = array_map(
            fn (mixed $id): string => is_scalar($id) ? (string) $id : '',
            Arr::wrap($value),
        );

        return array_values(array_filter($ids, fn (string $id): bool => $id !== ''));
    }

    private static function message(string $handle, string $permission): string
    {
        return match ($handle) {
            LifecycleFields::CONTENT_OWNER => __('Choosing who owns a page takes the ":permission" permission, which you do not have. Leave the owner as it was.', ['permission' => $permission]),
            default => __('Taking a page out of the review cycle, or putting it back, takes the ":permission" permission, which you do not have. Leave the toggle as it was.', ['permission' => $permission]),
        };
    }
}
