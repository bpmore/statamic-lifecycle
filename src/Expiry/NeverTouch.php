<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Expiry;

/**
 * Rail 4 (spec §6): pages nothing automated may ever change — named by
 * collection, by URL pattern, or by entry.
 *
 * The home page. The legal pages. The collection the redirects live in.
 * Whatever their review state, they are not candidates, and the refusal
 * says which rule kept them out so the person reading the dry run can find
 * it in the config.
 *
 * URL patterns are site-relative URIs with `*` standing for anything, any
 * length, slashes included: `/legal/*` keeps `/legal/privacy` and
 * `/legal/terms/2024`. Matching ignores case and a trailing slash. `/` on
 * its own is the home page and nothing else.
 *
 * Restores pass: a way back is never refused. Pure.
 */
final readonly class NeverTouch
{
    /** @var list<string> */
    public array $collections;

    /** @var list<string> */
    public array $urls;

    /** @var list<string> */
    public array $entries;

    /**
     * @param  list<string>  $collections  handles
     * @param  list<string>  $urls  patterns
     * @param  list<string>  $entries  IDs
     */
    public function __construct(array $collections = [], array $urls = [], array $entries = [])
    {
        $this->collections = array_values(array_unique(array_filter(array_map(fn ($c): string => trim((string) $c), $collections))));
        $this->urls = array_values(array_unique(array_filter(array_map(fn ($u): string => self::tidy((string) $u), $urls))));
        $this->entries = array_values(array_unique(array_filter(array_map(fn ($e): string => trim((string) $e), $entries))));
    }

    /** @param  array<string, mixed>  $config  the `lifecycle.expiry.never_touch` array */
    public static function fromConfig(array $config): self
    {
        return new self(
            collections: array_values((array) ($config['collections'] ?? [])),
            urls: array_values((array) ($config['urls'] ?? [])),
            entries: array_values((array) ($config['entries'] ?? [])),
        );
    }

    public function isEmpty(): bool
    {
        return $this->collections === [] && $this->urls === [] && $this->entries === [];
    }

    /**
     * @param  list<Candidate>  $candidates
     * @param  array<string, string|null>  $uris  entry ID => site-relative URI, for the URL patterns
     */
    public function check(array $candidates, array $uris = []): TouchVerdict
    {
        $allowed = [];
        $refused = [];

        foreach ($candidates as $candidate) {
            $reason = $candidate->action === ActionType::Restore
                ? null
                : $this->reasonToKeepOut($candidate, $uris[$candidate->entryId] ?? null);

            if ($reason === null) {
                $allowed[] = $candidate;
            } else {
                $refused[] = new Refusal($candidate, $reason);
            }
        }

        return new TouchVerdict($allowed, $refused);
    }

    /** Why this page is on the list, or null when it is not. */
    public function reasonToKeepOut(Candidate $candidate, ?string $uri): ?string
    {
        if (in_array($candidate->entryId, $this->entries, true)) {
            return sprintf('entry [%s] is on the never-touch list', $candidate->entryId);
        }

        if (in_array($candidate->collection, $this->collections, true)) {
            return sprintf('the [%s] collection is on the never-touch list', $candidate->collection);
        }

        if ($uri !== null) {
            foreach ($this->urls as $pattern) {
                if (self::matches($pattern, $uri)) {
                    return sprintf('[%s] matches the never-touch pattern [%s]', $uri, $pattern);
                }
            }
        }

        return null;
    }

    public static function matches(string $pattern, string $uri): bool
    {
        $pattern = self::tidy($pattern);
        $uri = self::tidy($uri);

        if (! str_contains($pattern, '*')) {
            return strcasecmp($pattern, $uri) === 0;
        }

        $regex = '#^'.str_replace('\*', '.*', preg_quote($pattern, '#')).'$#i';

        return preg_match($regex, $uri) === 1;
    }

    /** A leading slash, no trailing one, except for the home page. */
    private static function tidy(string $uri): string
    {
        $uri = trim($uri);

        if ($uri === '') {
            return '';
        }

        $uri = '/'.ltrim($uri, '/');

        return $uri === '/' ? '/' : rtrim($uri, '/');
    }
}
