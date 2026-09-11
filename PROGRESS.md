# PROGRESS — Lifecycle

**Spec:** `lifecycle-build-spec.md` · **Package:** `bpmore/statamic-lifecycle` · **$99**
**Rule:** do the next unfinished task only, then update this file, verify the build, and commit.

## Phase 1 — Foundations
- [x] Scaffold the addon package, tests, CI
- [x] Migrations: `lifecycle_states`, `lifecycle_reviews`, `lifecycle_notifications`, `lifecycle_actions` (index `next_review_at`)
- [x] Blueprint fields: `content_owner`, `review_interval`, optional `review_by`
- [ ] State computation service: resolve effective interval (entry → blueprint → collection → global) and owner (assigned → inferred → fallback); pure and unit-tested

## Phase 2 — Baseline and recompute
- [ ] `lifecycle:baseline --horizon=12 --dry-run` with the staggering algorithm
- [ ] Verify the distribution is genuinely spread, not clustered, on a large fixture site
- [ ] Owners seeded from last author, flagged `inferred`
- [ ] `lifecycle:recompute` as a queued batch
- [ ] Orphaned-state cleanup for deleted entries

## Phase 3 — Attestation (the paid value)
- [ ] "Mark reviewed" action — records who, when, note, outcome
- [ ] **Confirm it does not write to the entry or create a revision**
- [ ] Review history model + per-entry timeline
- [ ] Publish-form sidebar panel

## Phase 4 — Bulk assignment (adoption depends on this)
- [ ] Assign by structure branch
- [ ] Assign by collection / blueprint / author / taxonomy term
- [ ] CSV import (URL or entry ID → owner)
- [ ] Bulk action in the CP listing
- [ ] Owner-of-last-resort per site

## Phase 5 — Notifications
- [ ] Configurable schedule (30 / 7 / 0 / overdue)
- [ ] **Digest by default, one email per recipient** — never one per entry
- [ ] Escalation to fallback owner, then owner-of-last-resort
- [ ] Quiet window; per-user frequency opt-out

## Phase 6 — Expiry actions (build the rails BEFORE the actions)
- [ ] Percentage circuit breaker (default 10% of a collection)
- [ ] Daily action cap (default 25)
- [ ] Mandatory notice period
- [ ] Never-touch list
- [ ] Navigation-reference check before unpublish
- [ ] Action log with `previous_state` + one-click revert
- [ ] `lifecycle:expire --dry-run` (documented as the first step)
- [ ] Only then: banner variable, noindex, unpublish — all off by default, opt-in per collection

## Phase 7 — Surfaces + ship
- [ ] Utility dashboards by collection / site / owner; "My content" view
- [ ] Dashboard widget
- [ ] Gate: require owner + review date, opt-in and off by default
- [ ] Antlers tags: `last_reviewed`, `is_stale`, `next_review`
- [ ] Governance report export (reuse the tagged-PDF pipeline)
- [ ] Permissions, with expiry configuration admin-only
- [ ] README, listing copy, tag 1.0

## Notes
<!-- Record surprises, decisions and blockers here. If a task is wrong or blocked, write why and stop. -->

### 2026-09-11 — Scaffold
- Layout follows the sibling `bpmore/statamic-a11y-docs` so the suite reads as one codebase:
  Pest on `Statamic\Testing\AddonTestCase` with `PreventsSavingStacheItemsToDisk`, Pint (laravel
  preset), Vite via `@statamic/cms/vite-plugin` with the built bundle committed under
  `resources/dist/build`, `composer.lock` and `package-lock.json` committed.
- Resolved `statamic/cms` 6.32.0 here (host site is on 6.31.0 — both 6.x). Laravel 13 needs PHP 8.3,
  so on PHP 8.2 Composer falls back to Laravel 12 / Testbench 10; the `^10.8 || ^11.0` constraint
  covers that and CI runs the 8.2 / 8.3 / 8.4 matrix with `composer update`, not `install`.
- CI also asserts Statamic 6 resolved (the CLAUDE.md rule, encoded) and that the committed asset
  build matches a fresh `npm ci && npm run build` (verified deterministic locally).
- The host site's `composer.json` did **not** yet have the path repository CLAUDE.md describes;
  added `bpmore-lifecycle -> ../statamic-lifecycle` and required `bpmore/statamic-lifecycle:@dev`.
  Statamic discovers the addon as **Lifecycle** / slug `lifecycle` and publishes its assets to
  `public/vendor/statamic-lifecycle/build`; `/cp/auth/login` renders 200 with the bundle and no
  manifest error.
- `resources/js/cp.js` is an empty module on purpose — the `$vite` wiring is live so the publish
  pipeline is proven before anything depends on it. No `config/lifecycle.php` yet: Statamic
  auto-merges `config/<slug>.php` when it exists, and the first task that consumes a setting adds it.
- `.gitignore` excludes `.claude/settings.local.json` and `build-log.jsonl` (the unattended-run log);
  `.claude/settings.json`, `CLAUDE.md`, `PROGRESS.md`, `SPEC.md` and `run-build.sh` are committed,
  matching the sibling.

### 2026-09-11 — Migrations
- **Storage follows the sibling's pattern, deliberately.** Tables live on an addon-owned SQLite
  file (`storage/lifecycle/lifecycle.sqlite`, connection `lifecycle_sqlite`) unless
  `lifecycle.connection` names one of the site's own. `php please lifecycle:install` creates the
  file and runs the migrations there; it asks first on a site-administered connection (`--force`
  skips the question) and applies only pending migrations on an existing install. Migrations are
  **not** `loadMigrationsFrom`-registered — that would let `php please migrate` run them on the
  default connection while the install ran them on the configured one, with two records of what
  ran. A site that owns A11y Docs too gets the same install story. `config/lifecycle.php` now
  exists with the one key this consumes.
- `lifecycle:install` is not in the spec's §9 command list; it is the prerequisite for all of them.
- Schema deviations from the spec §3 sketch, each small: `lifecycle_actions.reverted_by` added
  (the sibling shipped without `revoked_by` on its exemption log and had to add it — a revert
  without attribution is the thing the table exists to rule out); date columns are `dateTime`,
  not `timestamp`, so a hard `review_by` past 2038 on MySQL does not throw; `owner_*` and
  `interval_days` / `next_review_at` nullable (no owner with no owner-of-last-resort, and exempt
  entries, are legitimate states). `lifecycle_states` has no Laravel timestamps — `computed_at`
  is the meaningful one.
- Indexes beyond the mandated `next_review_at`: `status`, `collection`, `site`,
  `(owner_type, owner_id)` on states; `(entry_id, reviewed_at)`, `reviewed_at`, `reviewed_by` on
  reviews; `(entry_id, type)`, `(recipient, sent_at)` on notifications; `(entry_id, applied_at)`,
  `applied_at` (the daily cap counts against it) on actions. All from spec §5/§6/§8 queries.
- **Open question for the attestation task:** `lifecycle_reviews` has no `site`/`collection`. The
  review record must outlive the entry (no FK to states, on purpose), but the governance report
  wants activity by collection; once the entry is gone the join is gone. Add the two denormalised
  columns then, in a new migration, rather than guess now.
- No Eloquent models yet. Spec §10 groups "migrations and models"; this task line says migrations,
  and nothing reads or writes rows until Phase 2. The first task that does adds them, reading the
  connection at call time (not a `$connection` property) so a config change is honoured.
- Verified on the host site: install creates `storage/lifecycle/{lifecycle.sqlite,.gitignore}`,
  four tables plus `migrations` on that file, the site's `database.sqlite` untouched, a second run
  says "Already installed", CP still renders. The host file is left in place for the next tasks.

### 2026-09-11 — Blueprint fields
- **Injected, not imported.** A listener on `EntryBlueprintFound` (the hook Statamic's own
  `AddSiteColumnToBlueprint` uses) calls `ensureFieldsInTab(..., 'sidebar')`, so every entry
  blueprint gets the three fields with no setup — core adds `slug`/`date`/taxonomies the same way.
  A site that wants a field elsewhere declares the handle in its blueprint: `ensureField` keeps the
  site's position and lets the site's config win, ours fills only what was left unsaid (tested).
  No shipped fieldset, on purpose — it would be a second copy of the definition to keep in step.
- `Blueprint\LifecycleFields` is the one source of truth: handle constants (`CONTENT_OWNER`,
  `REVIEW_INTERVAL`, `REVIEW_BY`) for later tasks to read, the field configs, and the interval table.
- **Intervals are stored by name, not by number**: `review_interval: quarterly`, not `90`. Reads as
  intent in a diff (spec §3's "an author saying quarterly is content"), and sidesteps the select
  fieldtype's strict-equality label lookup on numeric keys. `LifecycleFields::intervalDays(name)`
  maps to days (30 / 90 / 180 / 365 / 730 / 1095) and returns null for an unknown name — a site
  that swaps in its own options is the computation service's problem to resolve, not an error here.
- Owner is a `users` field, `max_items: 1` (spec §11: schema supports groups, v1 UI offers users).
  `review_by` is a date without a time. All three `localizable: true` (per-site owner and cadence)
  and `listable: 'hidden'` — available as a column and a filter for Phase 4's filtered bulk
  selection, without redesigning every entry listing.
- **Not done, deliberately:** no config for which collections get the fields. That is the
  "which collections does Lifecycle govern" question, and the baseline/recompute tasks are its
  other consumers; better designed once with all three in view. Until then every collection gets
  them. Also: a blueprint-level *default* interval is not the field's `default:` (that would write
  an explicit value into every new entry rather than inherit) — the computation task decides how
  blueprint and collection defaults are expressed.
- Noticed for later: Statamic 6's `AddonServiceProvider::registerSettingsBlueprint` gives addons a
  settings screen in the CP. Worth using for the Phase 5–7 configuration instead of config-only.
- Verified on the host site: a real `pages` entry's blueprint has the three fields in the sidebar
  after `slug`, with the six interval options reaching the fieldtype's preload.
