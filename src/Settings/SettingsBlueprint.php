<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Settings;

use Statamic\Facades\Site;

/**
 * The addon's settings screen in the control panel (Addons → Lifecycle →
 * Settings), built when asked for so it can list the sites the site has.
 *
 * So far: the owner of last resort, one per site, so that nothing is ever
 * ownerless (spec §2). Statamic stores what is chosen here in
 * resources/addons/lifecycle.yaml — in the repository, where a decision
 * like this belongs.
 */
final class SettingsBlueprint
{
    private const PREFIX = 'owner_of_last_resort_';

    /** @return array<string, mixed> blueprint contents */
    public static function contents(): array
    {
        $fields = [];

        foreach (Site::all() as $site) {
            $fields[] = [
                'handle' => self::handleForSite($site->handle()),
                'field' => [
                    'type' => 'users',
                    'display' => Site::hasMultiple()
                        ? __('Owner of last resort — :site', ['site' => $site->name()])
                        : __('Owner of last resort'),
                    'instructions' => __('Who is responsible for a page when nobody else is. Reminders for unowned pages go here, and escalations end here.'),
                    'max_items' => 1,
                    'mode' => 'select',
                ],
            ];
        }

        return [
            'tabs' => [
                'owners' => [
                    'display' => __('Owners'),
                    'sections' => [
                        [
                            'display' => __('Owner of last resort'),
                            'instructions' => __('Nothing should be ownerless. A page with no content owner, and no last author to guess from, belongs to this person.'),
                            'fields' => $fields,
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function handleForSite(string $site): string
    {
        return self::PREFIX.preg_replace('/[^a-z0-9_]/i', '_', $site);
    }

    /** The site a field handle stands for, or null when it is not one of ours. */
    public static function siteForHandle(string $handle): ?string
    {
        if (! str_starts_with($handle, self::PREFIX)) {
            return null;
        }

        $wanted = substr($handle, strlen(self::PREFIX));

        foreach (Site::all() as $site) {
            if (self::handleForSite($site->handle()) === self::PREFIX.$wanted) {
                return $site->handle();
            }
        }

        return null;
    }
}
