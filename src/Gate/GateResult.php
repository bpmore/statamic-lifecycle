<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Gate;

/**
 * What the gate decided about one save: let through, and why — or refused,
 * with what is missing.
 */
final readonly class GateResult
{
    public const OWNER = 'owner';

    /** An owner is named, but that person is no longer a user. */
    public const OWNER_GONE = 'owner_gone';

    public const REVIEW_DATE = 'review_date';

    /**
     * @param  list<string>  $missing  of self::OWNER, self::OWNER_GONE, self::REVIEW_DATE
     * @param  string  $reason  why the save was let through, for a log or a test
     */
    private function __construct(
        public array $missing,
        public string $reason,
        public string $collectionTitle = '',
    ) {}

    public static function passed(string $reason): self
    {
        return new self([], $reason);
    }

    /** @param  list<string>  $missing */
    public static function refused(array $missing, string $collectionTitle): self
    {
        return new self($missing, 'missing '.implode(' and ', $missing), $collectionTitle);
    }

    public function shouldRefuse(): bool
    {
        return $this->missing !== [];
    }

    public function isMissing(string $what): bool
    {
        return in_array($what, $this->missing, true);
    }
}
