# PROGRESS — Lifecycle

**Spec:** `lifecycle-build-spec.md` · **Package:** `bpmore/statamic-lifecycle` · **$99**
**Rule:** do the next unfinished task only, then update this file, verify the build, and commit.

## Phase 1 — Foundations
- [x] Scaffold the addon package, tests, CI
- [x] Migrations: `lifecycle_states`, `lifecycle_reviews`, `lifecycle_notifications`, `lifecycle_actions` (index `next_review_at`)
- [x] Blueprint fields: `content_owner`, `review_interval`, optional `review_by`
- [x] State computation service: resolve effective interval (entry → blueprint → collection → global) and owner (assigned → inferred → fallback); pure and unit-tested

## Phase 2 — Baseline and recompute
- [x] `lifecycle:baseline --horizon=12 --dry-run` with the staggering algorithm
- [x] Verify the distribution is genuinely spread, not clustered, on a large fixture site
- [x] Owners seeded from last author, flagged `inferred`
- [x] `lifecycle:recompute` as a queued batch
- [x] Orphaned-state cleanup for deleted entries

## Phase 3 — Attestation (the paid value)
- [x] "Mark reviewed" action — records who, when, note, outcome
- [x] **Confirm it does not write to the entry or create a revision**
- [x] Review history model + per-entry timeline
- [x] Publish-form sidebar panel

## Phase 4 — Bulk assignment (adoption depends on this)
- [x] Assign by structure branch
- [x] Assign by collection / blueprint / author / taxonomy term
- [x] CSV import (URL or entry ID → owner)
- [x] Bulk action in the CP listing
- [x] Owner-of-last-resort per site

## Phase 5 — Notifications
- [x] Configurable schedule (30 / 7 / 0 / overdue)
- [x] **Digest by default, one email per recipient** — never one per entry
- [x] Escalation to fallback owner, then owner-of-last-resort
- [x] Quiet window; per-user frequency opt-out

## Phase 6 — Expiry actions (build the rails BEFORE the actions)
- [x] Percentage circuit breaker (default 10% of a collection)
- [x] Daily action cap (default 25)
- [x] Mandatory notice period
- [x] Never-touch list
- [x] Navigation-reference check before unpublish
- [x] Action log with `previous_state` + one-click revert
- [x] `lifecycle:expire --dry-run` (documented as the first step)
- [x] Only then: banner variable, noindex, unpublish — all off by default, opt-in per collection

## Phase 7 — Surfaces + ship
- [x] Utility dashboards by collection / site / owner; "My content" view
- [x] Dashboard widget
- [x] **Report a Freshness band to Site Weather.** One class implementing `Bpmore\SiteWeather\Contracts\WeatherContributor` (`key()` `freshness`, `label()` `Freshness`, `reading()`), tagged by name as `site-weather.contributors` in the provider's `register()` — Site Weather is a `suggest`, plus `require-dev` (path repository `../statamic-site-weather` until it is on Packagist). `reading()` reads stored results only — the share of tracked entries whose `EntryState` status is overdue or expired — never recomputes; asks whether the addon is set up before it reads; returns `Reading::unknown()` with a next-step headline when not set up or nothing measured; a measured state carries the latest `computed_at`; `url` is the lifecycle utility dashboard from the task above. Thresholds are this product's call. Follow `../statamic-a11y-docs/src/Weather/DocumentsContributor.php` and its test. Then drop the fake `freshness` band from `../statamic-dev/app/Providers/SiteWeatherDemoProvider.php` and confirm the real one on the dev-site tile.
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

### 2026-09-11 — State computation service
- **Split into a pure core and a thin adapter.** `State\StateComputer::compute(EntryFacts, PriorState,
  now)` → `LifecycleState`; nothing in `State\` imports a framework (enforced by a token-based test,
  not a Pest arch rule — `toUse('Illuminate')` matches nothing against the registered PSR-4
  prefixes and passes vacuously, and it cost 2.5s). 39 unit cases run in 90ms with no Statamic.
  `Entries\EntryFactsReader` is where Statamic is met: `value()` not `get()` so a localisation
  inherits its origin's owner until it sets its own; `lastModifiedBy()` so a last author who has
  left is not inferred; a date field holding a non-date reads as no deadline.
- **Decisions the spec left open, now made in code (each unit-tested):**
  - *Hard deadline* (`review_by`) wins over any computed date, later or earlier, until a review
    is recorded on or after it; then it is spent and the cadence resumes.
  - *Never reviewed, never baselined* → last modification + interval. On an un-baselined site
    that makes old content overdue at once, which is honest and is exactly what `lifecycle:baseline`
    exists to spread out. Baselined entries keep their baseline date until the first real review,
    even if the interval changes — the spread must not collapse.
  - *Status is day-granular.* Due today is `due_soon`, not overdue; overdue starts the next day;
    `expired` = overdue for ≥ `expired_after_days` (default 30). Phase 6 may refine what
    "expired" gates, but a computed status has to be a function of time, and this is it.
  - *Inferred owners live only in the table.* The spec's "seed `content_owner` from the last
    author" is read as: resolve it on every compute, never write it to the entry. Writing it would
    make a guess indistinguishable from a human's word — and would touch 40,000 files. So Phase 2's
    "owners seeded from last author" is persistence of what the computer already resolves.
  - *Exempt* is an input fact (`EntryFacts::$exempt`); where it is recorded (field or table) is
    still undecided and belongs to whichever task builds the exempt action.
- `State\Policy` is the site's rules, built once from config and bound as a singleton so two screens
  cannot disagree about "due soon". Interval values everywhere may be a name or a number of days;
  a default that does not resolve throws at boot rather than leaving every entry without an
  interval. Blueprint intervals resolve `collection/blueprint` before bare `blueprint`.
- Config keys added: `intervals.{default,collections,blueprints,named}`,
  `owners.{infer_from_last_author,fallback}`, `due_soon_days`, `expired_after_days`. The
  owner-of-last-resort is therefore configurable now; Phase 4's task is the surface for it.
- Result carries *why* as well as *what* (`ownerSource`, `intervalSource`, `nextReviewSource`) so the
  Phase 3 panel can say "1 March 2027, from the deadline you set". `ownerType` is always `user` in
  v1; the column is there for groups.
- Verified on the host: a real entry computes through the container — global yearly, next review a
  year after last modification, fresh.

### 2026-09-11 — lifecycle:baseline
- **The staggering algorithm is pure** (`State\BaselinePlanner`, unit-tested with 10,000 synthetic
  entries in 20ms): group by window = min(horizon, entry's interval); within a group, entry *i* of
  *N* lands on day `1 + floor(i × window / N)`, ordered by last modification (ties by ID, so two
  runs agree). Even, not capped: clamping a quarterly page's date to day 90 would pile every
  quarterly page on day 90, which is the cluster this exists to prevent. Horizon is calendar months
  (`today + N months`), so 12 is a year in any year. A lone entry lands tomorrow, not at the horizon.
- **Which entries are in** (the command's call, not the planner's): `whereStatus('published')`
  only — drafts are Workflows territory and do not go stale. Skipped: anything already baselined or
  with a recorded review. Entries with their own `review_by` are flagged baselined but not spread;
  the deadline wins in the computation anyway. Statamic 6 throws on `where('status', …)`; it is
  `whereStatus()` now.
- **Persistence arrived with this task**, as it had to: `Models\EntryState` (connection read at call
  time) and `Storage\StateStore` — the one place a `LifecycleState` becomes a row and a `PriorState`
  is read from one. Chunked upserts (500/statement: 1,200 rows = 3 statements, tested) and chunked
  prior lookups. Migration 000500 added `baseline_review_at` (the assigned date must survive a hard
  deadline overriding `next_review_at`), `interval_source` and `next_review_source` (so listings can
  explain a row without recomputing). The upgrade path ran for real on the host: "Applied 1 new
  migration."
- **The dry run is the evidence.** It prints the per-month histogram and per-collection windows
  before anything is written, and the same summary after. That is what the next task ("verify the
  distribution is genuinely spread on a large fixture site") should read.
- The rows carry `owner_id`/`owner_source = inferred` because that is what the computer returns
  and a row cannot be half-written — so the "owners seeded from last author" task is now
  verification plus surfacing the count, not new machinery. The entry file is never touched
  (checked on the host by mtime).
- Memory: all facts for the run are held in memory (an `EntryFacts` is a few hundred bytes; 40,000
  is a few tens of MB). Entries stream through `lazy(500)`. Fine for the target; the large-fixture
  task will say for sure.

### 2026-09-11 — Verified the spread at 40,000
- **Fixture site**: `tools/build-fixture-site.php --site=… --count=40000` writes a deliberately lumpy
  population straight to disk in ~2s (`Tests\Support\FixtureSite`, seeded): 25% re-saved in one
  migration week, 45% long tail back to 2016, 20% recent, 10% an exact-tie "audit afternoon";
  80/15/5 across pages (global yearly) / news (`quarterly`) / policies (`every_2_years`); 2% with
  `review_by` (some past); 5% drafts; 60% with a last author. `tools/verify-spread.php` joins the
  manifest to `lifecycle_states` and runs `Tests\Support\SpreadAnalysis`; `tools/remove-fixture-site.php`
  takes it all out again. All three are in the repo; the host is back to its one real entry.
- **Result, 37,982 published entries, horizon 12 months** (`RESULT: spread, not clustered.`):
  - window 90 (news, 5,626): 90 of 90 days used, 62–63 per day, busiest day 1.008× average
  - window 365 (pages + policies, 31,598): 365 of 365 days used, 86–87 per day, busiest 1.005×
  - overdue on day one 0 · past the horizon 0 · scheduled before older content 0 (both windows)
  - every published entry has a row; no draft has one; all 758 hard-dated kept their date
  - 22,775 owners inferred from the last author (pool of one user on the host)
- **Cost**: dry run 14.7s, apply 15.5s, peak RSS 305 MB — almost all of it Statamic's Stache holding
  40,000 entries (`stache:warm` alone took 7.5 min and needs the same memory). The addon's own
  footprint is the facts array, a few tens of MB. No optimisation needed at the target scale.
- **What the combined histogram looks like, and why that is right**: the first three months carry
  ~148/day and the rest ~86/day, because the quarterly group is bounded to 90 days and stacks on
  the yearly spread. That is a plateau, not a pile — each window is even to within one entry per
  day — and it is what "a quarterly page's first review cannot be eleven months out" costs. The dry
  run's per-collection "spread across 90 days" line is where a reader sees it.
- **Durable**: `tests/Feature/BaselineSpreadTest.php` runs the same population at 4,000 through the
  real command (files written straight into the test Stache, `Stache::clear()`, 2.6s) and asserts
  every check above with a tighter bound (max − min per day ≤ 1). Group `scale`, so a fast loop can
  `--exclude-group=scale`; CI runs it. PHPUnit reserves `large` as a group name.
- Nit fixed on the way: PHP 8.4 wants `fputcsv`/`fgetcsv` given `escape: ''` explicitly.

### 2026-09-11 — Owners seeded from last author
- The seeding itself was already in place from the computation and baseline tasks (resolved on
  every compute, written to `lifecycle_states` as `owner_source = inferred`, never to the entry;
  a last author who has left is not inferred). This task made it trustworthy and visible.
- **`owners.never_infer`** (config, `Policy::$neverInfer`, `Policy::mayInfer()`): user IDs never
  guessed as owners. The 40,000 run showed why — with one user in the pool, 22,775 pages were
  "owned" by that person. On a real site that is the import account or the admin who re-saved
  everything in a migration week, and a dashboard built on that guess is worse than one that says
  "nobody". The list rules out a *guess*, not a decision: a human assigning that account still
  counts as assigned (tested).
- **The baseline now reports owners** before writing: chosen by a person / inferred (a guess,
  kept out of the entry) / owner of last resort / nobody — from the very states it is about to
  write, so dry run and real run agree. Two warnings: if anything has nobody and no
  `owners.fallback` is named; and if one person would be inferred for ≥ 50% of the site (only
  when there are 20+ entries — three pages by one author is a small site, not a migration).
- Laravel test quirk, worth remembering: `expectsOutputToContain` satisfies only the *first*
  matching expectation for each line written, so two expectations against one output line can
  never both pass. One contiguous phrase per line.
- Left for later, deliberately: an `author` blueprint field as a second inference source. The
  spec says last author; on an imported site `author` is as likely to be the import account as
  `updated_by` is, and `never_infer` covers both. Revisit if a real site asks.

### 2026-09-11 — lifecycle:recompute
- **The suite's job pattern, as the spec asked**: `StateRecomputer` enumerates published entry IDs
  lazily (`--site`, `--collection`), chunks them (`recompute.chunk_size`, default 250), and
  dispatches a `Bus::batch` of `Jobs\RecomputeStates` named "Lifecycle recompute" with
  `allowFailures` (a chunk that dies takes only itself down), on `recompute.queue` if named.
  `--sync` runs the chunks inline with a progress bar. Same `recompute(ids, now)` unit of work
  either way. Statamic wants the collection clause before the status clause; `entries()` obeys.
- **What recompute does and does not touch**: published entries only. An entry deleted or
  unpublished since enumeration is skipped and its row left alone — removing rows for what is
  gone or no longer live is the next task (orphan cleanup), kept separate so this one stays
  "compute". The baseline date and flag travel with the row through every recompute (tested:
  "so the spread survives the first night"); a recompute that dropped them would undo the baseline
  overnight.
- **Scheduled daily** via `AddonServiceProvider::schedule()`, guarded with `->when(installed)` so
  a site that has not run `lifecycle:install` gets silence rather than a nightly failure in the
  scheduler log. `php please schedule:list` on the host shows `0 0 * * *`.
- **`job_batches`**: Laravel's batch runner needs it on `queue.batching.database` and does not
  create it; a flat-file site usually lacks it. `lifecycle:install` now ensures it — via the site's
  own migration when there is one and the batching connection is the default (so a later
  `migrate` does not trip over a table it has no record of), else by creating it — and does so on
  the "already installed" path too, for installs that predate needing it. The host already had
  it (Laravel's skeleton migrated); tests cover both the missing and the pre-existing case.
- Verified on the host with the sync driver: "Queued 1 entry in 1 job", the batch record finished
  with 0 failed, the row's `computed_at` moved; `--sync` reports "Recomputed 1 entry".

### 2026-09-11 — Orphaned-state cleanup
- **The rule, now complete**: a `lifecycle_states` row exists for an entry exactly while it is
  published. Baseline and recompute only ever write for published entries; this task removes
  rows whose entry is gone *or no longer published*. `lifecycle_reviews` is never touched — a
  page that is gone was still reviewed by somebody on a date, and that record outlives the page.
- **Two mechanisms**, both needed: a listener on `EntryDeleted` removes the row the moment an
  entry goes (wrapped so a missing table or unreachable database can never stop a deletion), and
  `Jobs\PruneStates` runs last in every recompute batch (and after every `--sync` run, even when
  nothing is live) to catch what the event never saw — `deleteQuietly()`, files removed from git,
  and unpublishing, which fires no event of its own.
- `PruneStates` enumerates the live set for itself when it runs, so it does not matter whether
  workers reach it before or after the compute chunks; the worst case is one stale row until the
  next night. `StateStore::ids()` walks the table keyset-paginated in chunks of 500 and
  `remove()` deletes in chunks, so 40,000 rows never become 40,000 models or one giant `IN`.
  Scope (`--site`, `--collection`) applies to the sweep too, by the row's own columns.
- **Not done**: the doctor command spec §11 mentions for surfacing orphans. The recompute output
  reports what it removed, which is the operational need; a `lifecycle:doctor` (install state,
  config validity, orphans, never-baselined count) is a Phase 7 surface, noted there.
- Verified on the host: a hand-inserted ghost row was swept by the queued run; a real entry's row
  vanished on `delete()`; the file was gone and nothing else was.
- **Phase 2 is complete.** Baseline and recompute are trustworthy on real data at 40,000 entries.

### 2026-09-11 — "Mark reviewed"
- **The write path is one class**, `Reviews\RecordReview::record(entry, reviewerId, outcome, note?, at?)`:
  appends a `lifecycle_reviews` row and brings the entry's `lifecycle_states` row up to date through
  the same computer everything else uses, with the review as the new prior. It never writes to the
  entry — tested by hashing the file and pinning its mtime before and after, and checked the same
  way on the host. Task 2 adds the revisions-enabled check.
- **Statamic action** `Actions\MarkReviewed` (handle `mark_reviewed`): offered on entries in the
  listing and the entry's action menu, one or many; a required outcome (select) and an optional
  note; `bypassesDirtyWarning` because nothing it does can lose an unsaved edit. Tested through the
  real CP endpoint (`collections.entries.actions.run`): records for each selection as the current
  user, 422 on a missing or unknown outcome, 403 for a user who cannot edit the entry.
- **Outcomes** (`Reviews\Outcome`) in the reviewer's words: "Still current", "I updated it",
  "Needs a rewrite, and one is planned", "Retire it". Stored as `current | updated |
  rewrite_scheduled | retired`.
- **Decisions**: `content_changed` is a fact about the interval — was the entry modified after
  the *previous* review — not a claim about this one (the reviewer's own edits are what `updated`
  says); false on a first review. `entry_revision_ref` is `revision:<id>` where revisions are on,
  `modified:<timestamp>` where they are not. A backdated `at` is allowed (a review recorded after
  the fact) and `created_at` says when it was recorded. A draft can be reviewed — the record is
  the record — but gets no state row, since state exists only while published.
- **Authorization is provisional**: whoever can edit the entry may attest. Spec §8's dedicated
  `attest reviews` permission arrives with Phase 7's permissions task and replaces it.
- The `--dashboard--` half of spec §4's "in the publish-form sidebar and in the dashboard" is
  Phase 7; the sidebar panel is task 4 of this phase and will call this same action.

### 2026-09-11 — Confirmed: attestation touches nothing
- `tests/Feature/AttestationTouchesNothingTest.php`, with **revisions turned on** (Pro + the
  collection's `revisionsEnabled`) and through the **real CP action endpoint** as well as the
  service. Five proofs, each a different mechanism:
  1. revision count and latest revision ID unchanged, no working copy, exactly one revision file on
     disk, and the record points at the revision that was reviewed;
  2. `Entry::partialMock()->shouldNotReceive('save')` — the repository is never asked to save;
  3. `Event::fake` on `EntryCreating/Created/Saving/Saved`: none dispatched (A11y Gate listens on
     `EntrySaving`, git automation on `EntrySaved`), with a control that a real save *is* heard;
  4. file bytes identical, mtime pinned and unchanged, `lastModified()`/`updated_by`/data unchanged;
  5. the same through the service directly, since that is where the guarantee lives.
- **Sabotaged to check the checks**: with `$entry->save()` added to `RecordReview`, proofs 2–5 fail.
  Proof 1 does not — a bare `Entry::save()` does not create a revision in Statamic; only the CP
  publish flow does. So proof 1 shows the action does not route through that flow, and 2–5 catch
  a bare save. The comment in the test says so.
- Revisions are a Stache store, so `PreventsSavingStacheItemsToDisk` already redirects them into the
  throwaway fixture directory; no `statamic.revisions.path` config needed and nothing left behind.
- The host runs Solo with revisions off (`STATAMIC_PRO_ENABLED=false`); it confirms no working copy
  and an unchanged file. The revisions-on proof is the suite's.

### 2026-09-11 — Review history and the per-entry timeline
- `Models\Review` came with task 1; this task is the read side. `Reviews\ReviewHistory::for(id)`
  and `forMany(ids)` (one query, each reviewer looked up once) return a `Reviews\Timeline`: a list
  of `ReviewRecord`s newest first, with `latest()`, `count()`, `isEmpty()`, `reviewedSince(date)`,
  and a JSON-friendly `toArray()` (ISO dates, outcome value *and* label) for the sidebar panel,
  the dashboard, the Antlers tags and the report to share. One place a row becomes a record.
- **Timeline = reviews only**, the compliance record. The baseline date is state, not history, and
  belongs in the panel's summary next to "next review"; automated actions (Phase 6) will get their
  own list. Keeping the timeline to attestations keeps it the thing an auditor asks for.
- **Reviewer resolution**: existence and display name are two questions. Statamic's `User::name()`
  is null for a user with no name fields, so the first cut marked such a user as "gone"; now
  `reviewerExists` comes from `User::find` and the name falls back to the email, and only a
  reviewer who has actually left keeps their stored ID as the name (never blank — a record that
  named nobody is a weaker record).
- Verified on the host: the two attestations recorded today read back newest first with the
  reviewer's name.

### 2026-09-11 — Publish-form sidebar panel
- **A display-only fieldtype, the Statamic-native way to put a panel in the sidebar** (the
  sibling's `DocumentStatus` pattern). `Fieldtypes\LifecycleStatus` (handle `lifecycle_status`) is
  ensured onto every entry blueprint's sidebar under the three inputs with `visibility: computed`
  — Statamic excludes computed fields from the values a save writes — and its `process()` returns
  null besides. Tested through the real CP update endpoint with a smuggled value: nothing reaches
  the file. `Panel\SidebarPanel::for(entry)` builds the whole payload (status + colour, next review
  and *why*, interval and *why*, owner and how sure, last review, timeline, outcomes, whether the
  user may attest, the two URLs); the fieldtype's `preProcess()` hands it to the form, and
  `GET cp/lifecycle/entries/{entry}/status` hands it back fresh after a review.
- **The button posts to the same action the listing uses** (`mark_reviewed` via
  `collections.entries.actions.run`) and then re-reads the payload from the refresh route, so the
  panel never shows something it worked out for itself. Validation and authorisation are the
  action's; the panel just renders 422 field errors.
- **Verified in a real browser.** The Chrome extension was not connected, so: logged into the
  host CP with curl (Inertia login endpoint + CSRF), confirmed the edit page's Inertia payload
  carries the field and our bundle, then drove headless Google Chrome over the DevTools Protocol
  from a Node script with the session cookie (`scratchpad/render-panel.mjs`, not committed): the
  panel rendered with no console errors, the button opened the form, the submit wrote row #3,
  the panel refreshed with the new line, the toast showed, the entry file stayed untouched.
- **Two things only the browser found**: (1) the UI kit's `Text` renders inline, so labels ran into
  values ("OwnerNobody…") until given `as="div"`; (2) **`Statamic.$axios` does not exist in
  Statamic 6** — the HTTP client is `this.$axios`, a Vue app global property, reachable in
  `<script setup>` via `getCurrentInstance().appContext.config.globalProperties`; and `toast` is a
  named export of `@statamic/cms/api`. The first cut's POST never fired and the catch swallowed it
  into an error toast. Neither is visible from PHP tests; a render check is not optional for CP work.
- Host side effects, all undone: `make:user` flipped `STATAMIC_PRO_ENABLED` to true (Solo allows one
  user) — restored; the temp user removed; its two attestations deleted and the state row put back
  to the real user's last review. Rebuilt assets were `vendor:publish`ed to the host by hand —
  a site gets that on `composer update`.
- **Phase 3 is complete.** The paid value exists end to end: attest from the listing or the entry,
  never touch the entry, keep the record, show it where the editor works.

### 2026-09-11 — Assign by structure branch
- **The shape for all of Phase 4**: a selector produces an `Assignment\Selection` (a description in
  words plus the entries), `Assignment\OwnerAssigner::assign(entries, ownerId, dryRun)` writes
  `content_owner` and returns what changed, and `lifecycle:assign` is the command they meet in.
  The next selectors (collection, blueprint, author, term, CSV) plug into `AssignOwner::selection()`.
- **`Selectors\Branch`**: the page at a URI in one site's tree (`--site`, default the default site)
  plus every entry beneath it via `page()->flattenedPages()`; tree nodes that are not entries are
  left out. A URI in a collection with no structure gets "assign by collection instead".
- **This is the one operation in the addon that writes entries, on purpose** — an owner is content
  (spec §3). So it behaves like an editor: saves only the entries whose owner actually changes
  (`value()`, so a localisation synced to an origin that already has the owner is left synced),
  does **not** stamp `updated_by`/`updated_at` — whoever ran the assignment did not edit the page,
  and making them the last author would make them the inferred owner everywhere — then recomputes
  the rows it touched so the dashboard says "chosen" at once. Each save fires `EntrySaved`, so git
  automation commits it where that is on: that is the point.
- The command previews with a dry pass, prints "N would get X as owner, M already have them",
  asks before writing (`--force` for scripts), and offers `--dry-run`. Owner by user ID or email.
- Not written: a bare `Entry::save()` bumps the file's mtime, and for an entry with no `updated_at`
  in its data and no baseline, `lastModified()` is the mtime — so assigning could nudge such an
  entry's "last modified + interval" date forward. Baselined sites are unaffected (their date is
  kept), and sites tracking last update have `updated_at`. Noted; not worth fighting the file system.
- Verified on the host: `--branch=/` on the structured `pages` collection assigned the home page,
  the file carries `content_owner`, the row says `assigned`.

### 2026-09-11 — Assign by collection, blueprint, author, term
- Four more `Selectors\` on the same `Selection` shape, wired into `lifecycle:assign`:
  `--collection=`, `--blueprint=` (within `--collection` when given, else wherever a blueprint by
  that handle exists), `--author=` (ID or email), `--term=taxonomy::slug`. **More than one narrows**
  (intersection by entry ID), and the description joins with "and": "every entry in news and every
  entry written or last edited by Sam Okafor". `--site=` narrows any of them.
- **Decisions**: selectors include drafts — an owner is content whatever the status, and
  "everything in news" means everything (recompute still writes rows only for published).
  `--author` matches *both* the `author` field (which may hold a list, hence a scan) and
  `updated_by`: "Sam has left" means what Sam wrote and what Sam kept up, and either alone misses
  half. `--blueprint` matches the blueprint an entry actually uses — Statamic's `where('blueprint')`
  resolves the collection default for entries that do not name one — and validates against
  `Collection::entryBlueprints()`, so an entry whose file names a blueprint that does not exist
  (the host's starter-kit home page says `blueprint: pages`; Statamic resolves it to `page`) is
  matched by what Statamic resolves, not by what the file says.
- A term is a relationship only in a collection attached to its taxonomy; `whereTaxonomy` says
  so, and the test fixture had to learn it.
- Test fixtures needing a second real blueprint use `Blueprint::setDirectory()` pointed into the
  throwaway fixture directory; a partial mock of the repository breaks the fallback blueprint for
  every other collection.
- Verified on the host with dry runs of each selector and each error message.

### 2026-09-11 — CSV import
- `lifecycle:assign --csv=file [--owner=default] [--site=] [--dry-run] [--force]`. Each row names its
  own entry (a full URL, a URI or an ID) and its own owner (ID or email), so it is a mode of the
  command rather than a selector — `Selection` carries one owner. `Assignment\Csv\OwnerCsv` reads
  it into a `CsvPlan` (entries grouped by owner, problems by line); the command previews per owner,
  asks, then runs the assigner once per owner.
- **Built for the file a department sends back**: header row or none (columns found by name —
  entry/url/uri/id/path/page and owner/email/user — in any order, other columns ignored), a BOM
  (stripped *before* trimming, or a leading space behind it survives), CRLF, blank lines, whitespace.
  A row that cannot be used is reported by line number and the rest go ahead — one bad URL must not
  hold up four hundred good ones — and the exit code is non-zero when any row was bad, so a script
  notices. A later row for the same entry wins. `--owner` fills rows with no owner cell.
- **A URL pasted from a browser carries the site's path prefix**: `/fr/cardiologie` for a page
  whose URI within the French site is `/cardiologie`. Each site is tried with the path as given
  and with its own prefix removed; `--site` narrows to one.
- **Statamic 6 gates multisite behind `statamic.system.multisite`**: without it a collection
  quietly keeps only the default site and routes nothing elsewhere. Earlier multisite tests passed
  because they filtered on the entry's locale, not on routes. Any test that needs a URI in a second
  site must set the flag; the CSV test does.
- Verified on the host with a CRLF file, a header with extra columns, a full URL, a draft, and two
  deliberately bad rows.

### 2026-09-11 — Bulk action in the CP listing
- `Actions\AssignOwner` (handle `assign_owner`): filter the entries listing, select, "Assign owner",
  pick one person (a `users` field, `max_items: 1`, required). The same `OwnerAssigner` as the
  command, so the same rules: writes only what changes, does not stamp the runner as last author,
  recomputes what it touched. Tested through the real CP action endpoint: writes and recomputes;
  "N already had them" in the message; 422 with no owner; a person who does not exist is refused
  as the action's failure message without writing; 403 for a user who cannot edit.
- Unlike Mark reviewed, this one **does** keep Statamic's unsaved-changes warning and says in its
  confirmation that it writes the entry — because it changes the entry under an open form.
- Authorization is provisional ("may edit"), as with Mark reviewed, until Phase 7's permissions.
- Not built here: "set interval", "exempt" and "clear exemption" from spec §8's bulk list. Set
  interval is the same pattern with a different field and belongs with the Phase 7 surfaces;
  exempt waits on where exemptions are stored (still undecided — see the state computation notes).
- Statamic's CP middleware refuses more than one user without Pro, so any CP test with several
  users sets `statamic.editions.pro`.
- Verified on the host: offered in bulk next to `publish`/`unpublish`/`mark_reviewed`; ran; idempotent.

### 2026-09-11 — Owner of last resort per site
- **The addon's first settings screen** (Addons → Lifecycle → Settings), via Statamic 6's
  `registerSettingsBlueprint`: `Settings\SettingsBlueprint` builds one `users` field per site when
  asked (labelled with the site's name only when there are several). Statamic stores the choice in
  `resources/addons/lifecycle.yaml` — in the site's repository, next to config, which is where a
  decision like this belongs. Phase 5–7 configuration can join this screen.
- **Two sources, one answer**: `Owners\FallbackOwners::configured()` merges `owners.fallback` from
  config (a site → user map, or one ID for every site) with the settings, settings winning per
  site; `resolved()` keeps only people who still exist and `missing()` names the ones who have
  left. The pure `Policy` is built from `resolved()`, so the computer never resolves a page to
  someone who is gone; the baseline warns "[id] is no longer a user" per site. Saving settings
  forgets the Policy/StateComputer/StateRecomputer singletons so a long-running worker sees the
  change without a restart (tested: the policy reflects a save at once).
- **Statamic bug, worked around**: `FileSettings::path()` saves to `resources/addons/<slug>.yaml`
  but `FileSettingsRepository::find()` reads `resources/addons/<package suffix>.yaml`, so for any
  addon whose `extra.statamic.slug` is not its package name's second half — ours: `lifecycle` vs
  `statamic-lifecycle` — every save is lost on the next read. `Settings\SettingsRepository` finds by
  slug and is bound over Statamic's in `bootAddon()`. **To report upstream** (statamic/cms 6.32,
  `src/Addons/FileSettingsRepository.php:14`); remove the override when fixed. The sibling would
  hit it too if it used settings.
- Verified on the host: the settings screen renders (HTTP 200 as the real user through the kernel),
  a save writes the file, the policy picks it up, and an ownerless page computes to the owner of
  last resort with source `fallback`.
- Seen on the way, not ours: the sibling A11y Docs addon's `EntrySaving` listener throws when its
  own tables are not installed on the host, which blocks any entry save there. Worth telling it.
- **Phase 4 is complete.** Owners can be assigned by branch, collection, blueprint, author, term,
  CSV, and from the listing; and nothing is ownerless.

### 2026-09-11 — Reminder schedule
- **Pure again**: `Notifications\Schedule` (from `notifications.schedule`, a list of days before the
  review date, 0 = the day itself; `overdue_every`, 0 = once) and `Notifications\ReminderPlanner`
  — entries with due dates and recipients in, what has been sent in, today in, reminders out. 16
  unit cases. `Notifications\Reminders` reads `lifecycle_states` (inside the horizon or past,
  owner set, user-owned, not exempt) and `lifecycle_notifications` and hands over.
- **The rules, decided here**: ahead of the date only the *latest stage to have begun* is owed — a
  page baselined five days out gets the 7-day reminder, not the 30-day one 25 days late; each
  stage once per cycle; once overdue a reminder at once, then every `overdue_every` days (default
  7) so an owner is not told once and then never again — the digest keeps that to one email.
- **A cycle is the review date the reminder was about.** Migration 000600 adds `due_at` and
  `days_before` to `lifecycle_notifications`: the type alone cannot tell 30 days from 7, and
  reminders about last year's date must not silence this year's. Read on the host through the
  upgrade path ("Applied 1 new migration").
- `lifecycle:notify --dry-run` prints the schedule and, per person, how many of each kind (`-v`
  lists the entries) — the shape the digest will take. Without `--dry-run` it refuses, with exit 1
  and "Sending is not built yet", rather than pretend; the digest task replaces that line and
  schedules the command daily. Groups as owners are skipped for now (schema supports them, v1
  offers users), as are pages with nobody to tell — the dashboard is where those show.
- Verified on the host: nothing owed for a page due next year; with its date moved to five days
  out, one `due_soon` (7-day stage) reminder to the owner.

### 2026-09-11 — The digest
- **Structurally one email per person.** `Notifications\Notifier::digests(owed)` groups every
  reminder by recipient into a `Digest` (items with title, collection, due date, CP edit link;
  overdue first, then due today, then due soon); `send()` mails each as `DigestMail` — a markdown
  mailable, `lifecycle::mail.digest`, plain words, one list per reason, "Review it" links — and
  **records the reminders only after the send returned**, so a failed send is owed again tomorrow
  and a successful one is never repeated. Tested: an owner of 300 overdue pages gets exactly one
  email listing 300, and 300 rows with one `sent_at`.
- **Who cannot be reached** is said, not swallowed: a recipient who has left or has no email is
  listed by `lifecycle:notify` ("cannot be reminded: no email address") and nothing is recorded
  for them — their pages stay owed until someone fixes the owner. A page deleted since its row was
  computed is left out of the digest (the sweep takes the row).
- Sending is synchronous inside the command; the command itself runs from the scheduler, daily at
  `notifications.send_at` (default 07:00, after the midnight recompute — an hour people read
  email), guarded on installed like the recompute. A site with a queue can `runInBackground()` it.
- The subject names the count and, when everything listed is overdue, says so.
- Verified on the host with the log mailer: the digest for a page due in five days, both text and
  HTML parts, the edit link resolving to the CP; recorded; the next run owed nothing.

### 2026-09-11 — Escalation
- **The spec's "fallback owner" is defined here as the collection's escalation contact** — the
  head of the department the collection belongs to — since the spec names the role but not its
  home. `Owners\EscalationContacts` mirrors `FallbackOwners`: `escalation.contacts` in config
  (collection → user) plus a field per collection on the settings screen (settings win), only
  people who exist, the missing named. A collection with nobody escalates straight to the owner
  of last resort.
- **Two thresholds, both from the review date**: after `escalation.after_days` (14) overdue the
  contact hears; after `last_resort_after_days` (30) the site's owner of last resort does. Each
  hears on the same cadence as the owner (`overdue_every`), each once per cycle per person, and
  **nobody is told twice over in two roles** — the owner who is also the contact, the contact who
  is also the last resort. The owner keeps hearing throughout; an escalation is an addition, not a
  hand-off. All of it in the pure planner (7 more unit cases); `Reminders` resolves the two people
  per row from collection and site.
- The sent log's `recipient` now feeds dedup for escalations (the level *is* the person), so no
  further migration. `Reminder` carries `ownerId` so the contact's email can say "owned by Alice
  Ahmed"; `DigestItem` carries the name. The digest gets an "Escalated to you" section ahead of
  the rest, its own subject when that is all there is, and a mixed one ("N pages need your
  attention") when a contact also owns pages.
- Verified on the host: the settings screen lists a contact per collection; a page 40 days overdue
  owned by someone who has left is escalated to the owner of last resort, and the missing owner is
  named as unreachable.

### 2026-09-11 — Quiet window and the opt-out
- **Quiet window**: `Notifications\QuietWindow` from `notifications.quiet_days` (default
  Saturday and Sunday) and `quiet_dates` (Y-m-d, for holidays). On a quiet day `lifecycle:notify`
  prints the plan and "Quiet day: it is Saturday", and sends nothing. Nothing is lost: what is
  owed is decided by the log, not the calendar, so Monday sends what Saturday would have.
- **The opt-out is a preference on the person, never on the record**: `lifecycle_reminders` —
  daily (default) / weekly at most / never — registered with `Preference::extend()` as a
  "Lifecycle" tab on each user's own preferences screen, read with `$user->getPreference()`.
  *Never* holds the digest and records nothing, so the reminder stays owed and the dashboard is
  untouched; unmute and it all still arrives. *Weekly* holds unless the person's last digest is
  seven or more days old, and the one that then goes lists everything owed by then. The command
  says who was held and why. The email's footer says where to change it.
- QuietWindow is a plain `bind`, not a singleton — cheap, and read fresh so a config change is seen
  in the same process. `Preference::tabs()` is built by `Preference::boot()`, which the CP calls
  when it draws the screen; a test has to call it too.
- Verified on the host: the preferences screen (HTTP 200 as the real user) carries the field under
  a Lifecycle tab; the quiet window names Saturday and Sunday and lets Monday through.
- **Phase 5 is complete.** Reminders go out on a schedule, as one digest per person, escalate when
  an owner has had long enough, stay quiet at weekends, and respect each person's say in how often.

### 2026-09-11 — The circuit breaker
- **Built before any action exists, as the spec insists.** `Expiry\CircuitBreaker::check(candidates,
  collectionSizes)` is pure: it counts distinct entries per collection across every kind of
  action but `restore` (a way back is never refused), and when a run would touch more than
  `max_share` (default 10%) of a collection it refuses the *whole* collection — `Verdict` says
  what was allowed, what was refused, and a `Trip` per collection with the numbers and a reason in
  words. Exact at the line (10% allowed, 10% and one page not). A collection the sizes do not
  mention is treated as entirely the run and refused: fail closed. 12 unit cases.
- The vocabulary the actions will share is here too: `ActionType` (banner, noindex, unpublish,
  restore — the schema's set) and `Candidate` (one proposed change to one page).
- **"Log it, notify an admin"**: `Expiry\TripAlert::raise(trips)` writes each refusal to the log
  with its numbers and emails `TripAlertMail` — the refused collections and what to do — to the
  addresses in `expiry.alert_to`, or every super user with an email when none are named. If there
  is nobody to tell, it says so in the log rather than fall silent: a tripped breaker nobody hears
  about is a site that quietly stops expiring anything.
- Config: `expiry.circuit_breaker.max_share` accepts `'10%'`, `10`, or `0.10`; `min_affected`
  (default 0) lets a small collection through when fewer pages than that are involved, for a
  site that decides two of ten is not a catastrophe. The spec's rule is the default.
- The purity test now covers `src/Expiry` too, bar `TripAlert*`, which is the impure half.
- Verified on the host: 12 of 100 refused, the warning in the log with its numbers, the alert to
  the super user through the log mailer.

### 2026-09-11 — The daily cap
- **Rail 2, pure**: `Expiry\DailyCap::check(candidates, usedToday)` lets the first candidates through
  in the run's own order until the day's `expiry.daily_cap` (25) is spent, and *defers* the rest —
  deferred, not refused: a page the cap holds back is still due its action and gets it on a later
  day; only the pace is limited. Restores are never counted or held. A cap of 0 stops everything
  but a way back. `CapVerdict::reason()` says what was put off and why, in words.
- The breaker guards a run that is too big for one collection; the cap guards a day that is too
  big for the site. Together a backlog of a thousand expired pages becomes a month of small,
  watchable steps rather than an afternoon nobody can undo.
- `Models\AutomatedAction` (`lifecycle_actions`, casts incl. `previous_state` as array) arrives
  with `appliedOn(day)` and `counted()` scopes; `Expiry\ActionsToday` reads how much of the day is
  spent from the log, in the app's timezone, restores excluded. The purity check skips it.
- Verified on the host: 25 a day, none used, 30 wanted → 25 allowed and 5 put off.

### 2026-09-11 — The notice period
- **Rail 3, pure**: `Expiry\NoticePeriod::check(candidates, notices, today)` sorts a run three ways
  — never noticed for this review date and this action → *to notify* (and nothing else happens
  today); noticed fewer than `expiry.notice_days` (7) ago → *waiting*, with the day it may happen
  from; noticed long enough ago → *allowed*. Counted from the *first* notice, per action (notice of
  a banner is not notice of an unpublish) and per cycle (a review starts over — the page is no
  longer overdue). Never fewer than one day: a period of 0 still means the next day, so notice and
  action are never the same run, whatever the number. Restores need no notice. 10 unit cases.
- **The notice is a reminder**: `ReminderType::Notice`, carried in the owner's one daily digest —
  first in it, with its own subject ("A page you own will change unless you review it"), naming
  the action and the day ("Lifecycle will unpublish it on or after 19 Sep 2026") and saying that a
  review, even to say the page is still current, stops it. Migration 000700 adds `action` to
  `lifecycle_notifications` so the row says which action was noticed; `Expiry\NoticesSent` reads
  them back for the period. The reminder planner ignores notice rows (tested), so a notice never
  silences an overdue reminder.
- `Candidate` now carries `dueAt`, the review date the action is about — the cycle a notice is
  counted in. `ActionType::label()` is a verb phrase with its object ("unpublish it", "show a banner
  on it") so any sentence built from it reads right; the first cut said "will show a banner it".
- The expire command (task 7) will feed `toNotify` into the Notifier and act only on `allowed`.
- Verified on the host: the migration applied; a notice went through the real digest with the
  right sentence; the verdict is *waiting until the 19th* today and in six days, *allowed* in seven.

### 2026-09-11 — The never-touch list
- **Rail 4, pure**: `Expiry\NeverTouch::check(candidates, uris)` keeps out pages named by entry ID,
  by collection handle, or by URL pattern — `*` stands for anything, any length, slashes included,
  so `/legal/*` keeps `/legal/privacy` and `/legal/terms/2024`; matching ignores case and a
  trailing slash; `/` on its own is the home page and nothing else. Each `Refusal` names the rule
  that applied, so the person reading a dry run can find it in the config. Restores pass. The
  command supplies site-relative URIs; a page with none is judged on the other two rules.
- **The home page is on the list by default** (`expiry.never_touch.urls => ['/']`): the one page no
  site wants unpublished by a scheduler. A site that means it can remove it.
- Verified on the host: the home page is kept out by the default pattern; listing the `pages`
  collection keeps it out by that rule instead, and the reason says which.

### 2026-09-11 — The navigation check
- **Rail 5, pure**: `Expiry\NavigationCheck::check(candidates, references, allowedThisRun)` refuses
  to *unpublish* a page any navigation links to, naming every navigation that does ("linked from
  the [Main navigation] navigation and the [Footer] navigation; unpublishing it would break the
  menu. Allow it explicitly to go ahead."). Banner and noindex pass — the link still works — and
  so does a restore. The override is by entry: `expiry.unpublish_in_navigation` in config, or an
  allowance for one run that the expire command will take on the command line.
- `Expiry\NavigationReferences::for(ids)` reads every navigation's tree in every site in one pass
  (a menu is small; a run is not), at any depth, ignoring nodes that are not entries, and names the
  site when a navigation has more than one tree. In Statamic 6 a nav has no configured site list —
  it is in a site by having a tree there; `sites()` is derived, not set.
- The rails' verdict shape is now one class, `RailVerdict` (allowed + refusals with reasons); the
  never-touch list uses it too.
- Not covered, deliberately: a page's place in its own *collection's* tree. Unpublishing a parent
  there does not restructure anything or break links the way a menu does; children keep rendering.
- The host has no navigations, so it can only confirm "none → nothing linked"; the real-nav cases
  (depth, two navs, a tree per site) are in the suite.

### 2026-09-11 — The action log and one-click revert
- **Rail 6**: `Expiry\ActionLog::record(entry, action, previousState, triggeredBy)` writes every
  automated change with what the page looked like before — `previous_state` is a map: `published`
  for an unpublish, otherwise the entry's own data keys with their old values, null for a key that
  was not there. `outstanding(id)` is the latest change not yet put back; `history(id)` the lot.
- **`revert(action, byUser)`** applies the map — `published` through Statamic's own
  `publish()`/`unpublish()` with a user and a message, so revisions are honoured where they are
  on; data keys set or removed and saved — marks the row `reverted_at`/`reverted_by`, and writes a
  `restore` row of its own carrying what *it* undid. So the log says both what happened and that it
  was undone. Refuses to put back twice, to put back a restore, or a page that is gone; never makes
  the person the last author.
- **One click, in both places the editor looks**: `Actions\PutBack` (`put_back`) in the listing and
  the entry's action menu, offered only where there is something to undo; and the sidebar panel
  now says "Lifecycle changed this page — it chose to unpublish it on 11 Sep, because the page
  stayed overdue" with a *Put it back* button that posts the same action and refreshes.
- Verified in headless Chrome against the host: the panel showed the change, the click put the
  page back, the toast showed, the block disappeared, the log shows the unpublish reverted by the
  clicker and the restore row. Host side effects undone (temp user, Pro flag, test rows).
- `ActionLog` is the impure half of the rail; the purity check's exclusion list is now a pattern.

### 2026-09-12 — lifecycle:expire --dry-run
- **The opt-in**: `expiry.collections` — `'news' => ['banner' => true, 'noindex' => true,
  'unpublish' => false]` or a plain list — read by `Expiry\EnabledActions`, all off by default,
  ordered least to most drastic, `restore` not something to opt into. With nothing opted in the
  command says so and that Lifecycle is notify-only until a collection does.
- **`Expiry\ExpiryPlanner`** builds candidates (an `expired` row in an opted-in collection, one per
  enabled action not already applied *this cycle* — applied or since put back: a change a person
  undid is not made again until a review starts a new cycle) and runs the rails **in an order that
  matters**: never-touch and navigation first (not candidates, so they count toward nothing), then
  the breaker (before anyone is warned, so 400 owners are not told of an unpublish the breaker
  would refuse), then the notice period (now / waiting / to notify), then the cap on what may
  happen now. A page with nobody to notify can never pass the notice period and is reported, not
  skipped. `ExpiryPlan` holds every bucket with its reasons.
- **`lifecycle:expire --dry-run [--collection=] [--allow-nav=id…]`** prints the opt-ins, the count
  of expired entries, and every bucket — would happen now, would notify (with the date it may act
  from), waiting, held by the cap, kept out and why, refused by the breaker, nobody to notify —
  naming each page by title and URI, and changes nothing and notifies nobody. Without `--dry-run`
  it refuses with exit 1 until the actions exist (next task). README now leads its expiry section
  with the dry run as the first step, and the config comment says the same.
- Seen while testing: three-entry test collections trip the breaker at once (33% > 10%), which is
  the rule working; the fixture stands it down except in the breaker's own test.
- Host noise, not ours: the `pages` tree in the Stache has the draft handbook at the root and home
  at `/home`, disagreeing with the tree file — a stale cache from another session's edits. It is
  why the `/` never-touch pattern did not apply to home in the host dry run.

### 2026-09-12 — The actions
- **Only now, behind all six rails.** `Expiry\ActionApplier`: banner and noindex set a field on
  the entry to true — `expiry.fields.banner` / `.noindex`, default `lifecycle_banner` /
  `lifecycle_noindex`, so a site with an SEO addon can point noindex at that addon's own field —
  and nothing more: the template decides whether to render a banner, whatever writes
  `<meta name="robots">` decides to honour the flag. Lifecycle never injects markup. Unpublish
  goes through Statamic's own `unpublish()` with a message, so revisions are honoured. None makes
  Lifecycle the last author. **Decided**: a field, not a value in the addon's tables, because a
  field is the one thing every template and every SEO addon can read; unpublish writes the entry
  anyway; and `previous_state`/revert is built around entry state. The Phase 7 tags can expose it too.
- **`Expiry\ExpiryRunner::run(plan)`**: sends the notices, takes the allowed actions, writes each to
  the log *before moving on* (a run that dies halfway leaves an exact record), raises the breaker
  alert. **Notices go past the frequency preference** — "your page will change next week" is not a
  reminder anyone has muted, and silence would let a change happen unannounced — but respect a
  quiet day (held, planned again tomorrow, nothing recorded).
- `lifecycle:expire` now runs for real without `--dry-run`, prints what it did, exits non-zero if
  any action failed, and is scheduled daily at `expiry.run_at` (06:30, before the digests), guarded
  on installed, inert until a collection opts in. An owner announced a change and also owed
  reminders gets two emails that day — an announcement is worth its own.
- Tested end to end in the suite: notify → wait → act after the period → both actions logged with
  what they replaced → put back from the log; a change a person put back is not made again this
  cycle; the breaker alert; one page failing does not stop the rest and is named.
- **Verified on the host**: `pages` opted in at runtime, the home page expired → run 1 sent the
  notice (the real email, "Lifecycle will show a banner on it on or after 19 Sep 2026"); notice
  back-dated → run 2 wrote `lifecycle_banner: true` into `home.md` and logged it with
  `{"lifecycle_banner": null}`; `revert()` took it out again. Host tidied.
- Host noise: the site became multisite yesterday evening (another session); entries now live under
  `content/collections/<collection>/default/`. Entry paths come from `$entry->path()`, so nothing
  of ours cared, but a hand-typed path did.
- **Phase 6 is complete.** Nothing on the live site changes unless a collection opts in; when it
  does, every change is announced, capped, bounded, logged, and one click from undone.

### 2026-09-12 — The utility screens
- **Three screens, one nav item** (Tools → Lifecycle, with *My content* beneath): the overview
  (`lifecycle.dashboard`) — a headline, the status counts and the three attention filters (never
  reviewed / no owner / owner is a guess) each a way into the list, and tables by collection, by
  site (shown only with more than one) and by owner, most overdue owner first and *Nobody* last;
  the list (`lifecycle.entries`) — most overdue first, filtered by state, attention, collection,
  site and owner from the query string, each page with its owner and how sure, its state and date,
  its last review in words, and a link into the editor; and *My content* (`lifecycle.mine`) — the
  same list pinned to the person looking, whatever the query string says. Empty states say what
  to run; an uninstalled addon says so rather than failing.
- `Reporting\Overview` and `Reporting\EntryListing` read `lifecycle_states` in grouped queries and
  one paginated query (50 a page, titles resolved per row), never by walking entries. The listing
  keeps a page whose entry is gone (named by ID, no link) rather than dropping it: the sweep
  removes it tonight, and until then hiding it would be lying about the count.
- The nav registration is tested against a real `Nav` (the harness mocks `Nav::build()`), with
  the route-exists guard the sibling learned the hard way.
- **Not gated yet**: the screens need a signed-in CP user and nothing more. Phase 7's permissions
  task adds `view lifecycle`.
- Verified in headless Chrome against the host: all three screens render with no console errors,
  the nav shows both items, the list filters from the URL. Host side effects undone.

### 2026-09-12 — Dashboard widget
- `Widgets\Lifecycle`, registered through the provider's `$widgets` and enabled by the site with
  `['type' => 'lifecycle', 'width' => 50]` in `config/statamic/cp.php` — Statamic 6 widgets are
  a `VueComponent::render()` of a component the addon registers in `cp.js` (`lifecycle-widget`),
  not a Blade view. Three numbers, each a way in: **Yours** (overdue + expired owned by the
  current user, with a due-soon badge) → My content; **Whole site** → the listing filtered to
  overdue; the title → the dashboard.
- **The trend needs history the state table doesn't keep**, so a fifth table:
  `lifecycle_snapshots`, one row per day (`day` is the key), holding the day's status counts,
  the attention counts and that day's review count. `Reporting\Snapshots::record()` upserts it
  at the end of `StateRecomputer::prune()` — the end of every recompute, sync or batched — so the
  nightly run leaves a mark and a second run the same day overwrites rather than duplicates.
  `last(30)` reads the window back, oldest first. Migration 000800; `lifecycle:install` applies it.
- The sparkline is an inline SVG polyline drawn in the component — no chart library rides
  along for 48 pixels. Points sit where their day falls in the window, so a night the recompute
  skipped shows as a gap rather than a straight line pretending it ran.
- **"30 days ago" is only true once there are 30 snapshots**, and today's own snapshot is not a
  trend: the change is measured against the oldest *earlier* day in the window and the copy
  says how many days that actually was ("↓ 3 fewer overdue than 23 days ago"); with only today
  recorded it says the trend appears after a few nightly recomputes. The first cut said
  "No change over 30 days" on day one, which the render caught.
- There is no `Statamic\Facades\Widget`; widgets register as `handle => class` in
  `app('statamic.widgets')` and `Statamic\Widgets\Loader::load()` instantiates them, which is what
  the registration test asserts.
- The chunked-prune test counted every query and broke when the snapshot added its own at the
  end of `prune()`; it now counts the deletes (3) and keyset selects (≤4) by statement.
- Verified in the user's own Chrome (signed in as the host's super user, so no temp user and no
  Pro flag to restore): widget renders beside Site Weather and the sibling Readability widget,
  sparkline and change line correct against three seeded older snapshots, all three links resolve,
  no console errors. Host side effects undone: seeded rows deleted, my `cp.php` line removed
  (another session added a `readability` widget to the same file meanwhile — left as found);
  the host's `STATAMIC_PRO_ENABLED=true` was already set by someone else and was not touched.
  Migration 000800 stays applied on the host, as any site gets on `lifecycle:install`.

### 2026-09-12 — Report a Freshness band to Site Weather
- `Weather\FreshnessContributor`, tagged by class name as `site-weather.contributors` in
  `register()`. A tag is a string, so without Site Weather the class is never loaded and the
  interface it implements is never looked for; Site Weather is a `suggest`, and a `require-dev`
  through the `../statamic-site-weather` path repository so the tests run against the real
  contract (`Contributors`, `Forecast`) rather than a stand-in.
- **It reads and never recomputes**: `Overview::statuses()` (one grouped query) and the newest
  `computed_at` in `lifecycle_states`. Not installed → unknown with "run php please
  lifecycle:install"; nothing tracked → unknown with "run php please lifecycle:baseline";
  everything exempt → unknown, because nothing is being measured.
- **Thresholds, this product's call.** "Late" is overdue or expired; expired is past the expiry
  line the site itself drew, so it weighs more: clear = nothing late; fair = under a tenth late
  and nothing expired; overcast = a tenth late, or anything expired; rain = a quarter late, or
  expired content on a site a tenth late; storm = half late, or expired across a tenth.
- **Proportions are of content under review — tracked minus exempt.** A large archive the site
  deliberately exempted would otherwise dilute the reading; the dashboard widget's "of N tracked"
  still counts everything, which is the right number for that sentence. Headline is plain
  words with the count: "41 of 1,240 entries overdue, 12 past expiry"; clear reads "Nothing
  overdue across 1,240 entries".
- Dev site: the fake `freshness` band is gone from `SiteWeatherDemoProvider`; the real one
  reads "Freshness: clear — Nothing overdue across 1 entry" on the tile, dated to the last
  recompute, linking to `/cp/lifecycle`. The tile's overall is still rain because of the
  demo Structure band, which is another addon's to replace.
- The user's Chrome had lost its CP session by this task, so the render fell back to the
  temp-user + curl + headless Chrome pattern: DOM text and the Freshness link confirmed, no
  console errors (a Licensing Alert modal covers the screenshot on a fresh session). Temp user
  removed; the host's `STATAMIC_PRO_ENABLED=true` was set by someone else and left alone.
