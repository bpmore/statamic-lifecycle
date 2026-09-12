<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Owners;

use Bpmore\StatamicLifecycle\Settings\SettingsBlueprint;
use Statamic\Facades\Addon;
use Statamic\Facades\Site;
use Statamic\Facades\User;

/**
 * Who owns what nobody else does, per site — and whether that person is
 * still here.
 *
 * Two places can name them: config/lifecycle.php (`owners.fallback`, a map
 * of site to user ID, or one ID for every site) for sites that keep such
 * things in code, and the settings screen, which wins per site. Whichever
 * named them, a person who has since left is not an owner: the policy only
 * ever sees people who exist, and the ones who do not are reported.
 */
final class FallbackOwners
{
    public const PACKAGE = 'bpmore/statamic-lifecycle';

    /** @return array<string, string> site => user ID, as configured, existing or not */
    public function configured(): array
    {
        $sites = Site::all()->map->handle()->values()->all();
        $config = config('lifecycle.owners.fallback');
        $owners = [];

        if (is_string($config) && $config !== '') {
            $owners = array_fill_keys($sites, $config);
        } elseif (is_array($config)) {
            foreach ($config as $site => $id) {
                if (is_string($id) && $id !== '' && in_array($site, $sites, true)) {
                    $owners[$site] = $id;
                }
            }
        }

        $settings = Addon::get(self::PACKAGE)?->settings()->all() ?? [];

        foreach ($sites as $site) {
            $id = $settings[SettingsBlueprint::handleForSite($site)] ?? null;

            if (is_array($id)) {
                $id = $id[0] ?? null;
            }

            if (is_string($id) && $id !== '') {
                $owners[$site] = $id;
            }
        }

        return $owners;
    }

    /** @return array<string, string> site => user ID, people who exist only */
    public function resolved(): array
    {
        return array_filter($this->configured(), fn (string $id): bool => User::find($id) !== null);
    }

    /** @return array<string, string> site => user ID, for people who have gone */
    public function missing(): array
    {
        return array_filter($this->configured(), fn (string $id): bool => User::find($id) === null);
    }
}
