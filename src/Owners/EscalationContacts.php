<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Owners;

use Bpmore\StatamicLifecycle\Settings\SettingsBlueprint;
use Statamic\Facades\Addon;
use Statamic\Facades\Collection;
use Statamic\Facades\User;

/**
 * Who hears about a page when its owner has had long enough: the
 * collection's escalation contact — the communications director for news,
 * the compliance office for policies.
 *
 * Named the same two ways as the owner of last resort: `escalation.contacts`
 * in config (collection → user ID) and the settings screen, which wins per
 * collection. A person who has left is not a contact.
 */
final class EscalationContacts
{
    /** @return array<string, string> collection => user ID, as configured, existing or not */
    public function configured(): array
    {
        $collections = Collection::all()->map->handle()->values()->all();
        $config = config('lifecycle.escalation.contacts');
        $contacts = [];

        if (is_array($config)) {
            foreach ($config as $collection => $id) {
                if (is_string($id) && $id !== '' && in_array($collection, $collections, true)) {
                    $contacts[$collection] = $id;
                }
            }
        }

        $settings = Addon::get(FallbackOwners::PACKAGE)?->settings()->all() ?? [];

        foreach ($collections as $collection) {
            $id = $settings[SettingsBlueprint::handleForCollection($collection)] ?? null;

            if (is_array($id)) {
                $id = $id[0] ?? null;
            }

            if (is_string($id) && $id !== '') {
                $contacts[$collection] = $id;
            }
        }

        return $contacts;
    }

    /** @return array<string, string> collection => user ID, people who exist only */
    public function resolved(): array
    {
        return array_filter($this->configured(), fn (string $id): bool => User::find($id) !== null);
    }

    /** @return array<string, string> collection => user ID, for people who have gone */
    public function missing(): array
    {
        return array_filter($this->configured(), fn (string $id): bool => User::find($id) === null);
    }
}
