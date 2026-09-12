<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Settings;

use Illuminate\Support\Facades\File;
use Statamic\Addons\FileSettingsRepository;
use Statamic\Contracts\Addons\Settings as AddonSettingsContract;
use Statamic\Facades\Addon;
use Statamic\Facades\YAML;

/**
 * Statamic 6.32 saves an addon's settings to resources/addons/<slug>.yaml
 * but looks for them at resources/addons/<package suffix>.yaml — so for an
 * addon whose slug is not its package name's second half (this one:
 * `lifecycle`, package `statamic-lifecycle`), every save is lost on the
 * next read. This finds by the same slug the save used. Statamic's own
 * behaviour is kept for an addon it cannot find.
 *
 * Reported upstream; remove when Statamic reads by slug.
 */
final class SettingsRepository extends FileSettingsRepository
{
    public function find(string $addon): ?AddonSettingsContract
    {
        $found = Addon::all()->first(fn ($candidate): bool => $candidate->id() === $addon);

        if ($found === null) {
            return parent::find($addon);
        }

        $path = resource_path("addons/{$found->slug()}.yaml");

        if (! File::exists($path)) {
            return null;
        }

        return $this->make($found, YAML::file($path)->parse());
    }
}
