<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Expiry;

use InvalidArgumentException;
use Statamic\Contracts\Entries\Entry;

/**
 * The three things Lifecycle can do to a page that stayed overdue, once
 * every rail has agreed.
 *
 * Banner and noindex set a field on the entry — `lifecycle_banner`,
 * `lifecycle_noindex`, or whatever `expiry.fields` names — to true. That is
 * all: the template decides whether to render a banner, and whatever
 * writes the page's <meta name="robots"> decides to honour the flag.
 * Lifecycle never injects markup into anyone's front end (spec §6). A
 * field is used rather than a value in this addon's own tables because a
 * field is the one thing every template, and every SEO addon, can read.
 *
 * Unpublish goes through Statamic's own path, so revisions are honoured.
 *
 * Each returns what it changed, as the action log records it and the
 * revert applies it. None makes Lifecycle the page's last author.
 */
final class ActionApplier
{
    public function __construct(
        private readonly string $bannerField = 'lifecycle_banner',
        private readonly string $noindexField = 'lifecycle_noindex',
    ) {}

    /** @param  array<string, mixed>  $config  the `lifecycle.expiry` array */
    public static function fromConfig(array $config): self
    {
        $fields = (array) ($config['fields'] ?? []);

        return new self(
            bannerField: self::handle($fields['banner'] ?? 'lifecycle_banner', 'banner'),
            noindexField: self::handle($fields['noindex'] ?? 'lifecycle_noindex', 'noindex'),
        );
    }

    public function field(ActionType $action): ?string
    {
        return match ($action) {
            ActionType::Banner => $this->bannerField,
            ActionType::Noindex => $this->noindexField,
            default => null,
        };
    }

    /**
     * Do it, and return what it replaced.
     *
     * @return array<string, mixed> previous state
     */
    public function apply(Entry $entry, ActionType $action): array
    {
        return match ($action) {
            ActionType::Banner => $this->flag($entry, $this->bannerField),
            ActionType::Noindex => $this->flag($entry, $this->noindexField),
            ActionType::Unpublish => $this->unpublish($entry),
            ActionType::Restore => throw new InvalidArgumentException('A restore is applied by the action log, from what it recorded.'),
        };
    }

    /** @return array<string, mixed> */
    private function flag(Entry $entry, string $field): array
    {
        $previous = [$field => $entry->get($field)];

        $entry->set($field, true)->save();

        return $previous;
    }

    /** @return array<string, mixed> */
    private function unpublish(Entry $entry): array
    {
        $entry->unpublish(['message' => __('Unpublished by Lifecycle: overdue for review')]);

        return ['published' => true];
    }

    private static function handle(mixed $handle, string $for): string
    {
        $handle = trim((string) $handle);

        if ($handle === '' || preg_match('/^[a-z0-9_]+$/i', $handle) !== 1) {
            throw new InvalidArgumentException("expiry.fields.{$for} must be a field handle; got ".var_export($handle, true).'.');
        }

        return $handle;
    }
}
