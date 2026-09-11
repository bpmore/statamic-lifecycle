<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\State;

use Bpmore\StatamicLifecycle\Blueprint\LifecycleFields;
use InvalidArgumentException;

/**
 * The site's rules for the computation: the interval ladder, whether to guess
 * owners, who owns what nobody else does, and when "due" becomes "soon".
 *
 * Built from config/lifecycle.php by fromConfig(), or directly in a test.
 * Interval values everywhere in here may be an interval name ('quarterly')
 * or a number of days; both are resolved once, on construction, so the
 * computation only ever sees days.
 */
final readonly class Policy
{
    /** @var array<string, int> interval name => days, built-ins plus the site's own */
    public array $namedIntervals;

    public int $defaultIntervalDays;

    /** @var array<string, int> collection handle => days */
    public array $collectionIntervals;

    /** @var array<string, int> 'collection/blueprint' or 'blueprint' => days */
    public array $blueprintIntervals;

    /**
     * @param  array<string, int>  $namedIntervals  additional names, on top of LifecycleFields::INTERVALS
     * @param  array<string, string>  $fallbackOwners  site handle => user ID, the owner-of-last-resort
     */
    public function __construct(
        mixed $defaultInterval = 'yearly',
        array $collectionIntervals = [],
        array $blueprintIntervals = [],
        array $namedIntervals = [],
        public bool $inferOwnerFromLastAuthor = true,
        public array $fallbackOwners = [],
        public int $dueSoonDays = 30,
        public int $expiredAfterDays = 30,
    ) {
        $this->namedIntervals = array_merge(
            array_map(fn (array $interval): int => $interval['days'], LifecycleFields::INTERVALS),
            $namedIntervals,
        );

        $this->defaultIntervalDays = $this->intervalDays($defaultInterval)
            ?? throw new InvalidArgumentException(
                'The default review interval must be an interval name or a number of days; got '.var_export($defaultInterval, true).'.'
            );

        $this->collectionIntervals = $this->resolveAll($collectionIntervals, 'collection');
        $this->blueprintIntervals = $this->resolveAll($blueprintIntervals, 'blueprint');

        if ($dueSoonDays < 0 || $expiredAfterDays < 0) {
            throw new InvalidArgumentException('due_soon_days and expired_after_days cannot be negative.');
        }
    }

    /**
     * From the `lifecycle` config array. Missing keys take the defaults above,
     * so an empty array is a valid, sensible policy.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $intervals = (array) ($config['intervals'] ?? []);
        $owners = (array) ($config['owners'] ?? []);

        return new self(
            defaultInterval: $intervals['default'] ?? 'yearly',
            collectionIntervals: (array) ($intervals['collections'] ?? []),
            blueprintIntervals: (array) ($intervals['blueprints'] ?? []),
            namedIntervals: (array) ($intervals['named'] ?? []),
            inferOwnerFromLastAuthor: (bool) ($owners['infer_from_last_author'] ?? true),
            fallbackOwners: array_filter((array) ($owners['fallback'] ?? []), fn ($id): bool => is_string($id) && $id !== ''),
            dueSoonDays: (int) ($config['due_soon_days'] ?? 30),
            expiredAfterDays: (int) ($config['expired_after_days'] ?? 30),
        );
    }

    /**
     * Days for an interval value — a name this policy knows, or a positive
     * number — and null for anything else. Null is not an error: an entry
     * holding a name the site removed from the options simply falls through
     * to the next rung.
     */
    public function intervalDays(mixed $value): ?int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int) $value > 0 ? (int) $value : null;
        }

        if (is_string($value)) {
            return $this->namedIntervals[$value] ?? null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $intervals
     * @return array<string, int>
     */
    private function resolveAll(array $intervals, string $what): array
    {
        $resolved = [];

        foreach ($intervals as $handle => $value) {
            $resolved[$handle] = $this->intervalDays($value)
                ?? throw new InvalidArgumentException(
                    "The review interval for {$what} '{$handle}' must be an interval name or a number of days; got ".var_export($value, true).'.'
                );
        }

        return $resolved;
    }
}
