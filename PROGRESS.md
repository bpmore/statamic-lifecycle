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
