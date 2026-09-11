# PROGRESS — Lifecycle

**Spec:** `lifecycle-build-spec.md` · **Package:** `bpmore/statamic-lifecycle` · **$99**
**Rule:** do the next unfinished task only, then update this file, verify the build, and commit.

## Phase 1 — Foundations
- [x] Scaffold the addon package, tests, CI
- [ ] Migrations: `lifecycle_states`, `lifecycle_reviews`, `lifecycle_notifications`, `lifecycle_actions` (index `next_review_at`)
- [ ] Blueprint fields: `content_owner`, `review_interval`, optional `review_by`
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
