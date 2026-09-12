<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Gate;

use DateTimeImmutable;

/**
 * What the site asked of one collection: gate entries created from a date
 * on, or every entry there is.
 */
final readonly class GateRule
{
    /** @param  ?DateTimeImmutable  $since  null means every entry, the backlog included */
    public function __construct(public ?DateTimeImmutable $since) {}

    public function coversEverything(): bool
    {
        return $this->since === null;
    }

    /**
     * Whether an entry created at the given moment is gated. An entry whose
     * creation the addon never saw has no moment, and is treated as older
     * than the gate — the backlog is never gated by accident.
     */
    public function covers(?DateTimeImmutable $createdAt): bool
    {
        if ($this->since === null) {
            return true;
        }

        return $createdAt !== null && $createdAt >= $this->since;
    }
}
