<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Expiry;

use InvalidArgumentException;

/**
 * The rule that prevents the catastrophe (spec §6, rail 1).
 *
 * A config change can reclassify thousands of entries at once. So no single
 * run may touch more than a share of any collection — a tenth, unless the
 * site says otherwise. When a run would, the whole collection is refused:
 * nothing in it is changed, the refusal is written down, and an admin
 * hears. Someone has to look before that many pages move.
 *
 * Pure. Counts distinct entries per collection across every kind of action
 * but restore, which is a way back and never refused.
 */
final readonly class CircuitBreaker
{
    /**
     * @param  float  $maxShare  the share of a collection one run may touch, 0–1
     * @param  int  $minAffected  never trip for fewer entries than this, however small the
     *                            collection; 0 to trip on share alone
     */
    public function __construct(
        public float $maxShare = 0.10,
        public int $minAffected = 0,
    ) {
        if ($maxShare < 0 || $maxShare > 1) {
            throw new InvalidArgumentException('The circuit breaker\'s share is a fraction of a collection, between 0 and 1.');
        }

        if ($minAffected < 0) {
            throw new InvalidArgumentException('min_affected cannot be negative.');
        }
    }

    /** @param  array<string, mixed>  $config  the `lifecycle.expiry.circuit_breaker` array */
    public static function fromConfig(array $config): self
    {
        $share = $config['max_share'] ?? 0.10;

        // Accept "10%" and 10 as well as 0.10: the config comment says
        // percent, and a person will write what they read.
        if (is_string($share)) {
            $share = (float) rtrim(trim($share), '%') / 100;
        } elseif (is_numeric($share) && $share > 1) {
            $share = $share / 100;
        }

        return new self(
            maxShare: (float) $share,
            minAffected: max(0, (int) ($config['min_affected'] ?? 0)),
        );
    }

    /**
     * @param  list<Candidate>  $candidates  what a run proposes
     * @param  array<string, int>  $collectionSizes  published entries per collection
     */
    public function check(array $candidates, array $collectionSizes): Verdict
    {
        $perCollection = [];

        foreach ($candidates as $candidate) {
            if ($candidate->action === ActionType::Restore) {
                continue;
            }

            $perCollection[$candidate->collection][$candidate->entryId] = true;
        }

        $trips = [];

        foreach ($perCollection as $collection => $entries) {
            $affected = count($entries);
            $size = max($collectionSizes[$collection] ?? 0, $affected);
            $share = $affected / $size;

            if ($share > $this->maxShare && $affected >= max(1, $this->minAffected)) {
                $trips[$collection] = new Trip($collection, $affected, $size, $this->maxShare);
            }
        }

        $allowed = [];
        $refused = [];

        foreach ($candidates as $candidate) {
            if ($candidate->action !== ActionType::Restore && isset($trips[$candidate->collection])) {
                $refused[] = $candidate;
            } else {
                $allowed[] = $candidate;
            }
        }

        return new Verdict($allowed, $refused, array_values($trips));
    }
}
