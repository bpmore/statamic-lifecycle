# Changelog

What changed in each release, and what you have to do about it.

Versions are `MAJOR.MINOR.PATCH`.

## 1.1.0 — 2026-10-19

Run `php please lifecycle:install` after updating: one new migration (a
`source` column on the review record). The new field appears on its own,
like the others.

- **Exemptions.** An *Exempt from review* toggle on every entry, and two
  entry actions, *Exempt from review* and *Clear exemption*, for one page or
  many from the listing. An exempt page has no review date, gets no
  reminders, is never expired, and is counted as exempt. The gate asks an
  exempt page for an owner only. A new permission, *Manage exemptions*.
- **Set review interval** as an entry action in the listing, for one page or
  many: an interval, or the default for the collection. For whoever may edit
  the pages.
- **Editing can count as a review**, if a site says so: `reviews.editing_counts`,
  off by default. A save of a published page is then recorded as a review by
  that person, outcome *I updated it*, marked *from an edit* so an attestation
  stays an attestation. Once per person per page per day; never a draft, a
  save with nothing changed, an import, or Lifecycle's own writes.
- **`lifecycle:doctor`**: one run that says whether the addon is installed
  and up to date, whether the config resolves and names real collections and
  people, whether every published entry is tracked and baselined, whether
  the nightly recompute has been running, whether rows exist for pages that
  are gone, and whether a browser is there for the PDF. Reads only, says what
  to run, exits 1 when something stops it working.
- The Lifecycle screen carries a notice until the baseline has been run over
  the overdue backlog: what is happening, what the baseline would do, and how
  to adjust it. Afterwards, one line says what the baseline did.

## 1.0.1 — 2026-09-17

Nothing to do on upgrade.

- The nightly recompute, the queued recompute job and bulk owner assignment
  now read the clock the same way as every other part of the addon, so a
  site (or a test) that fakes the time gets the same answer from all of
  them. Behaviour on a real clock is unchanged.
- The Site Weather development dependency comes from Packagist rather than
  a sibling folder, so a fresh clone installs and CI runs.
- A support section in the README: GitHub issues.
- Files that only matter for developing the addon (tests, tools, the build
  notes) are left out of the Composer download.

## 1.0.0 — 2026-09-12

The first release.

- Owner, review interval and review-by fields on every entry blueprint,
  added without editing a blueprint; the state computation that turns them
  into a next review date and a status.
- `lifecycle:install`, with the addon's own SQLite file or the site's
  database; `lifecycle:baseline` with the staggered horizon and inferred
  owners; `lifecycle:recompute` as a nightly queued batch that also sweeps
  rows for deleted entries.
- *Mark reviewed*: the append-only attestation record, proven never to write
  the entry; the review history; the sidebar panel.
- Bulk owner assignment by branch, collection, blueprint, author, taxonomy
  term and CSV, the *Assign owner* listing action, and the owner of last
  resort with a settings screen.
- Digest reminders with escalation contacts, a quiet window, and a
  per-person frequency preference; `lifecycle:notify --dry-run`.
- Expiry actions — banner, noindex, unpublish — off by default and
  per collection, behind the circuit breaker, daily cap, notice period,
  never-touch list, navigation check, and a log with *Put it back*;
  `lifecycle:expire --dry-run`.
- Dashboards by collection, site and owner; "My content"; the dashboard
  widget with the thirty-day trend and its daily snapshots.
- The publish gate: a confirmed owner and a review date before publishing,
  off by default, never the backlog unless asked.
- Antlers tags `lifecycle:last_reviewed`, `lifecycle:next_review`,
  `lifecycle:is_stale`, `lifecycle:status`, and the `{{ lifecycle }}` pair.
- `lifecycle:report` as console tables, JSON, CSV, accessible HTML and a
  tagged PDF validated against PDF/UA-1.
- Four permissions: view lifecycle, attest reviews, assign owners,
  configure expiry actions.
- A Freshness band for Site Weather.

Not in this release: exempting a page from the cycle from the control panel,
and a bulk *set interval* action. The `exempt` status exists in the schema for
when they arrive.
