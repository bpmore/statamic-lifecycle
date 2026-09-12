<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Expiry;

/** The circuit breaker refusing a collection: how many, of how many, against what limit. */
final readonly class Trip
{
    public function __construct(
        public string $collection,
        public int $affected,
        public int $size,
        public float $limit,
    ) {}

    public function share(): float
    {
        return $this->size === 0 ? 1.0 : $this->affected / $this->size;
    }

    /** In words a person can act on. */
    public function reason(): string
    {
        return sprintf(
            'Refused to act on %s of the %s entries in [%s] (%d%%): more than the %d%% a single run may touch. Nothing was changed.',
            number_format($this->affected),
            number_format($this->size),
            $this->collection,
            (int) round($this->share() * 100),
            (int) round($this->limit * 100),
        );
    }
}
