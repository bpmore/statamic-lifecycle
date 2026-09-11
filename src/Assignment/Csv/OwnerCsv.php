<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Assignment\Csv;

use InvalidArgumentException;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;

/**
 * Reads a spreadsheet of who owns what: one row per entry, the entry as a
 * URL, a URI or an ID, the owner as a user ID or an email.
 *
 * Built for the file a department sends back after being asked "who owns
 * these pages?" — so it forgives what such files have: a header row or
 * none, a BOM, blank lines, whitespace, full URLs pasted from a browser.
 * What it does not forgive it reports by line number, and carries on.
 */
final class OwnerCsv
{
    private const ENTRY_HEADERS = ['entry', 'url', 'uri', 'id', 'path', 'page'];

    private const OWNER_HEADERS = ['owner', 'email', 'user', 'content_owner'];

    /** @var array<string, UserContract|null> */
    private array $users = [];

    /**
     * @param  ?string  $site  resolve URLs and URIs in this site only; otherwise the first site that has the page
     * @param  ?string  $defaultOwner  for rows with no owner cell, by user ID or email
     */
    public function read(string $path, ?string $site = null, ?string $defaultOwner = null): CsvPlan
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("Cannot read [{$path}].");
        }

        $default = $defaultOwner === null ? null : $this->user($defaultOwner);

        if ($defaultOwner !== null && $default === null) {
            throw new InvalidArgumentException("There is no user with the ID or email [{$defaultOwner}].");
        }

        $handle = fopen($path, 'r');
        $rows = 0;
        $byOwner = [];
        $names = [];
        $problems = [];
        $columns = null;   // [entry index, owner index], settled by the first row
        $line = 0;

        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            $line++;

            // A BOM is not whitespace: it goes before the trim, or a leading
            // space behind it survives.
            if ($line === 1 && isset($cells[0])) {
                $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cells[0]);
            }

            $cells = array_map(fn ($cell): string => trim((string) $cell), $cells);

            if (implode('', $cells) === '') {
                continue;
            }

            if ($columns === null) {
                $columns = $this->headerColumns($cells);

                if ($columns !== null) {
                    continue;   // it was a header
                }

                $columns = [0, 1];
            }

            $rows++;
            $entryCell = $cells[$columns[0]] ?? '';
            $ownerCell = $cells[$columns[1]] ?? '';

            if ($entryCell === '') {
                $problems[] = new CsvProblem($line, 'no entry');

                continue;
            }

            $entry = $this->entry($entryCell, $site);

            if ($entry === null) {
                $problems[] = new CsvProblem($line, "no entry at [{$entryCell}]");

                continue;
            }

            $owner = $ownerCell === '' ? $default : $this->user($ownerCell);

            if ($owner === null) {
                $problems[] = new CsvProblem($line, $ownerCell === '' ? 'no owner, and no --owner to fall back on' : "no user with the ID or email [{$ownerCell}]");

                continue;
            }

            $ownerId = (string) $owner->id();
            $byOwner[$ownerId] ??= collect();
            $byOwner[$ownerId]->push($entry);
            $names[$ownerId] = $owner->name() ?: (string) $owner->email();
        }

        fclose($handle);

        // An entry named twice gets the last word, so a corrected row lower
        // down wins — but it should not be assigned twice.
        foreach ($byOwner as $ownerId => $entries) {
            $byOwner[$ownerId] = $entries->reverse()->unique(fn (EntryContract $entry): string => (string) $entry->id())->reverse()->values();
        }

        return new CsvPlan($rows, $byOwner, $names, $problems);
    }

    /** @return array{int, int}|null the entry and owner column indexes, when the row is a header */
    private function headerColumns(array $cells): ?array
    {
        $lower = array_map(fn (string $cell): string => strtolower($cell), $cells);
        $entry = null;
        $owner = null;

        foreach ($lower as $index => $cell) {
            if ($entry === null && in_array($cell, self::ENTRY_HEADERS, true)) {
                $entry = $index;
            } elseif ($owner === null && in_array($cell, self::OWNER_HEADERS, true)) {
                $owner = $index;
            }
        }

        return $entry !== null && $owner !== null ? [$entry, $owner] : null;
    }

    /**
     * A URL, a URI, or an ID.
     *
     * A URL pasted from a browser carries the site's own path — /fr/cardiologie
     * for a page whose URI, within the French site, is /cardiologie. Each
     * site is tried with the path as given and with its own prefix removed.
     */
    private function entry(string $cell, ?string $site): ?EntryContract
    {
        if (preg_match('#^https?://#i', $cell)) {
            $cell = (string) (parse_url($cell, PHP_URL_PATH) ?: '/');
        }

        if (! str_starts_with($cell, '/')) {
            return Entry::find($cell);
        }

        $path = '/'.trim($cell, '/');
        $sites = $site === null ? Site::all() : collect([Site::get($site)]);

        foreach ($sites as $candidate) {
            foreach ($this->urisWithin($candidate, $path) as $uri) {
                if ($entry = Entry::findByUri($uri, $candidate->handle())) {
                    return $entry;
                }
            }
        }

        return null;
    }

    /** @return list<string> the path as given, and without the site's own prefix where it has one */
    private function urisWithin($site, string $path): array
    {
        $uris = [$path];
        $prefix = '/'.trim((string) parse_url($site->url(), PHP_URL_PATH), '/');

        if ($prefix !== '/' && ($path === $prefix || str_starts_with($path, $prefix.'/'))) {
            $uris[] = '/'.trim(substr($path, strlen($prefix)), '/');
        }

        return $uris;
    }

    private function user(string $handle): ?UserContract
    {
        return $this->users[$handle] ??= User::find($handle) ?? (str_contains($handle, '@') ? User::findByEmail($handle) : null);
    }
}
