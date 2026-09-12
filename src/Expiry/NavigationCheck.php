<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Expiry;

/**
 * Rail 5 (spec §6): refuse to unpublish a page a navigation links to,
 * unless somebody has said so explicitly — or a menu breaks and nobody
 * notices until a visitor does.
 *
 * Only unpublishing is refused; a banner or a noindex leaves the link
 * working. The override is by entry: `expiry.unpublish_in_navigation`
 * names pages that may come down from a menu, and the expire command can
 * allow one for a run. Pure: the references are handed in.
 */
final readonly class NavigationCheck
{
    /** @var list<string> */
    public array $overrides;

    /** @param  list<string>  $overrides  entry IDs that may be unpublished from a navigation */
    public function __construct(array $overrides = [])
    {
        $this->overrides = array_values(array_unique(array_filter(array_map(fn ($id): string => trim((string) $id), $overrides))));
    }

    /** @param  array<string, mixed>  $config  the `lifecycle.expiry` array */
    public static function fromConfig(array $config): self
    {
        return new self(array_values((array) ($config['unpublish_in_navigation'] ?? [])));
    }

    /**
     * @param  list<Candidate>  $candidates
     * @param  array<string, list<string>>  $references  entry ID => the navigations that link to it, in words
     * @param  list<string>  $allowedThisRun  entry IDs allowed explicitly for this run
     */
    public function check(array $candidates, array $references, array $allowedThisRun = []): RailVerdict
    {
        $allowed = [];
        $refused = [];

        foreach ($candidates as $candidate) {
            $reason = $candidate->action === ActionType::Unpublish
                ? $this->reasonToRefuse($candidate, $references[$candidate->entryId] ?? [], $allowedThisRun)
                : null;

            if ($reason === null) {
                $allowed[] = $candidate;
            } else {
                $refused[] = new Refusal($candidate, $reason);
            }
        }

        return new RailVerdict($allowed, $refused);
    }

    /** @param  list<string>  $linkedFrom  @param  list<string>  $allowedThisRun */
    public function reasonToRefuse(Candidate $candidate, array $linkedFrom, array $allowedThisRun = []): ?string
    {
        if ($linkedFrom === []) {
            return null;
        }

        if (in_array($candidate->entryId, $this->overrides, true) || in_array($candidate->entryId, $allowedThisRun, true)) {
            return null;
        }

        return sprintf(
            'linked from %s; unpublishing it would break the menu. Allow it explicitly to go ahead.',
            implode(' and ', array_map(fn (string $nav): string => "the [{$nav}] navigation", array_values(array_unique($linkedFrom)))),
        );
    }
}
