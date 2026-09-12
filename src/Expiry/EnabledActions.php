<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Expiry;

use InvalidArgumentException;

/**
 * Which actions each collection has opted into. All off unless a collection
 * says otherwise (spec §6): nothing on the live site changes by default.
 *
 * From `expiry.collections`: handle => ['banner' => true, 'noindex' => true,
 * 'unpublish' => false], or handle => ['banner', 'noindex'] for short.
 * Restore is not something to opt into.
 */
final readonly class EnabledActions
{
    /** @var array<string, list<ActionType>> collection => actions, in the order they apply */
    public array $byCollection;

    /** @param  array<string, mixed>  $collections */
    public function __construct(array $collections = [])
    {
        $enabled = [];

        foreach ($collections as $collection => $actions) {
            $list = [];

            foreach ((array) $actions as $key => $value) {
                // ['banner' => true] and ['banner'] both mean banner.
                $name = is_int($key) ? (string) $value : (string) $key;
                $on = is_int($key) ? true : (bool) $value;
                $action = ActionType::tryFrom($name);

                if ($action === null || $action === ActionType::Restore) {
                    throw new InvalidArgumentException("[{$name}] is not an expiry action a collection can opt into; banner, noindex and unpublish are.");
                }

                if ($on) {
                    $list[$action->value] = $action;
                }
            }

            if ($list !== []) {
                // Least to most drastic, whatever order the config lists them.
                uksort($list, fn (string $a, string $b): int => self::rank($a) <=> self::rank($b));
                $enabled[(string) $collection] = array_values($list);
            }
        }

        $this->byCollection = $enabled;
    }

    /** @param  array<string, mixed>  $config  the `lifecycle.expiry` array */
    public static function fromConfig(array $config): self
    {
        return new self((array) ($config['collections'] ?? []));
    }

    /** @return list<ActionType> */
    public function for(string $collection): array
    {
        return $this->byCollection[$collection] ?? [];
    }

    public function any(): bool
    {
        return $this->byCollection !== [];
    }

    private static function rank(string $action): int
    {
        return match ($action) {
            ActionType::Banner->value => 0,
            ActionType::Noindex->value => 1,
            default => 2,
        };
    }
}
