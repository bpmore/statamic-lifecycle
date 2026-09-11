# Lifecycle — Build Spec

**For:** handoff to Claude Code
**Date:** September 8, 2026
**Product:** `bpmore/statamic-lifecycle` — content review dates, ownership, and expiry for Statamic 6
**Price:** $99/site
**Decided:** notify-only by default · attestation and review history included · publish gating opt-in and off by default

---

## 0. Amendments — September 11, 2026

Written before A11y Docs shipped. Four things to carry in, none of which change the design:

- **Workflows is still "Planned"** on Statamic's roadmap, re-checked September 11. Nothing has
  moved to in progress. The boundary in §1 holds.
- **Adopt the suite's shared vocabulary.** A11y Gate, Report and Docs now use one set of severity
  levels and one set of terms across three products. Lifecycle sits in a different family
  (governance, not accessibility) but should speak the same language — same severity names, same
  finding shape, same plain-words voice in the gate messages. A customer who owns two of these
  should never have to learn two vocabularies.
- **The tagged-PDF export pipeline is now proven in two shipped products.** The governance report
  in §4 was speculative when this was written; it is now a known quantity. Reuse it rather than
  re-solving it.
- **PHP floor `^8.2`**, matching the suite-wide decision. Not `^8.4`.

---

## 1. Positioning and the boundary with core

**Lifecycle governs content that is already published and going stale.** Statamic's roadmap still lists **Workflows** — approvals and editorial flows with notifications — as *planned*, not in progress, so there's runway. But the boundary has to be deliberate from day one:

| | Owns |
|---|---|
| **Statamic Workflows** (future core) | Content going *in*. Drafts, approvals, editorial sign-off before publish. |
| **Lifecycle** (this) | Content already *in*. Ownership, review cadence, attestation, staleness, retirement. |

**Do not build:** approval chains, draft states, editorial assignment for new content, or anything resembling a publishing pipeline. When core ships Workflows, Lifecycle should slot alongside it, not compete. Anything you build in that territory is throwaway.

**Name.** Lifecycle sits outside the A11y family on purpose — it's content governance, not accessibility, and forcing an `A11y` prefix would misrepresent it. Keep "Lifecycle." It's descriptive, it's searchable, and it's the anchor for the second brand family: **A11y Gate / A11y Report / A11y Docs** for accessibility, **Lifecycle / Inventory** for governance, bundled as the bpmore Governance Suite.

---

## 2. The two hard problems

Everything else in this spec is straightforward CRUD. These two decide whether the product is adopted or uninstalled in week one.

### Problem 1 — day one on a 40,000-page site

Install Lifecycle on a real institutional site and every page is instantly *never reviewed*, *no owner*, *overdue*. A dashboard showing 40,000 red rows is not actionable; it's despair, and it gets uninstalled.

**Solution: a baseline onboarding pass, run once at install.**

- Assign each existing entry an initial `next_review_at` **staggered across a configurable horizon** (default 12 months), ordered by last-modified date so the oldest content surfaces first
- Nothing is overdue on day one. The backlog is spread, not dumped.
- Seed `content_owner` from the entry's last author where one exists, flagged as *inferred* rather than *assigned*, so the dashboard can distinguish "we guessed" from "a human confirmed"
- A visible banner until the site completes baselining, explaining what happened and how to adjust

This is the same shape as A11y Docs' grandfathering rule, and it exists for the same reason: **the tool protects the future, the dashboard describes the past.** Consistency across the line matters here.

### Problem 2 — bulk ownership assignment

Nobody is assigning owners to 40,000 pages one at a time. Without bulk assignment the product is theoretically useful and practically unusable.

Ship all of these:

- Assign by **structure branch** — everything under `/cardiology` belongs to the Cardiology team
- Assign by collection, by blueprint, by current author, by taxonomy term
- CSV import: URL or entry ID → owner
- Bulk action in the CP listing with a filtered selection
- An **owner-of-last-resort** per site, so nothing is ever ownerless

---

## 3. Storage — hybrid, and here's the line

**Authored intent lives in the blueprint. Operational state lives in tables.**

| Field | Where | Why |
|---|---|---|
| `content_owner` | Blueprint field (user or group) | It's an editorial decision, belongs in content, should be visible in git and carried by revisions |
| `review_interval` (optional override) | Blueprint field | Same reasoning — an author saying "this needs review quarterly" is content |
| `review_by` (optional hard date) | Blueprint field | An explicit "review before the March board meeting" wins over any computed date |
| `last_reviewed_at`, `next_review_at`, status | Table | Computed and frequently updated |
| Attestation history | Table | Append-only audit trail |
| Nudge and action history | Table | Operational log |

**The reason this split matters:** marking a page reviewed must **not** write to the entry. If it did, every attestation would create a content revision, potentially retrigger A11y Gate's checks, and pollute the git history with noise that isn't content change. Attestation writes to the table only. This is the single most important architectural decision in the product.

```
lifecycle_states
  entry_id(pk), site, collection
  owner_id, owner_type(user|group), owner_source(assigned|inferred|fallback)
  interval_days, last_reviewed_at, last_reviewed_by
  next_review_at(indexed), status(fresh|due_soon|overdue|expired|exempt)
  baselined(bool), computed_at

lifecycle_reviews            ← append-only. This is the compliance artifact.
  id, entry_id, reviewed_by, reviewed_at
  outcome(current|updated|rewrite_scheduled|retired)
  note, content_changed(bool), entry_revision_ref

lifecycle_notifications
  id, entry_id, type(due_soon|due|overdue|escalation), recipient, sent_at

lifecycle_actions            ← every automated change to the live site
  id, entry_id, action(banner|noindex|unpublish|restore)
  applied_at, triggered_by, previous_state(json), reverted_at
```

---

## 4. Attestation — the thing people actually pay for

Dates and reminders are free-addon material. **The review history is what makes this $99.**

A "Mark reviewed" action in the publish-form sidebar and in the dashboard, recording who, when, an optional note, and an outcome: *still current*, *updated it*, *rewrite scheduled*, *retire it*.

That produces a per-page answer to the question every audit, records request and accreditation review asks: **"When did a human last confirm this page was accurate, and who were they?"** Same logic as A11y Report — nobody pays for the reminder, they pay for the evidence.

**Governance report**, exportable, parallel to the conformance report:

- Percentage of content with a confirmed (not inferred) owner
- Percentage reviewed within the last 12 months
- Overdue by collection, by site, by owner
- Review activity over time
- Content never reviewed since baselining

Reuse A11y Report's accessible-HTML-to-tagged-PDF pipeline for the export.

---

## 5. Reminders

- Configurable schedule, default 30 / 7 / 0 days and then overdue escalation
- **Digest by default, per recipient.** An owner with 300 stale pages must receive one email listing 300 pages, never 300 emails. Getting this wrong gets your sending domain classified as spam and your addon uninstalled the same morning.
- Escalation to a fallback owner after N days overdue, then to the site's owner-of-last-resort
- Per-user opt-out of frequency, not of the record — someone can mute email without the dashboard forgetting
- Respect a configurable quiet window (no sends on weekends by default)

---

## 6. Expiry actions — the dangerous part

**Default is notify only. Nothing on the live site changes unless a site opts in, per collection.**

Optional actions, each independently enabled: **banner** (exposes a variable the template chooses to render — Lifecycle never injects markup into someone's front end), **noindex**, **unpublish**.

Because a config change can reclassify thousands of entries at once, every destructive action passes through these rails:

1. **Percentage circuit breaker.** Refuse to act if more than N% of a collection (default 10%) would be affected in one run. Log it, notify an admin, take no action. This is the rule that prevents the catastrophe.
2. **Daily cap** on total automated actions (default 25).
3. **Mandatory notice period** — the owner is notified, then the action happens N days later, never in the same run.
4. **Never-touch list** by collection, URL pattern, or entry.
5. **Navigation check** — refuse to unpublish an entry referenced in a navigation tree without an explicit override, or you silently break someone's menu.
6. **Every action logged with `previous_state` and one-click revert.**
7. **`lifecycle:expire --dry-run`** prints exactly what would happen and changes nothing. Make this the documented first step in the docs.

Write the circuit breaker before you write the actions themselves. An addon that quietly unpublishes 400 pages is not a bug report, it's the end of the product's reputation, and it would contaminate the A11y line by association.

---

## 7. Gate — opt-in, off by default

A site can require a confirmed owner and a review date before an entry publishes. Same machinery and same plain-words voice as A11y Gate.

- Off on install, enabled per collection
- Never applies to entries created before the gate was enabled unless explicitly asked
- The error names the missing thing and links to where to set it

---

## 8. Control panel and templating

- **Publish-form sidebar panel**: owner, next review date, last reviewed by/when, Mark reviewed button, review history
- **Utility screen**: dashboards by collection, site and owner; filters for overdue / due soon / never reviewed / no owner / inferred owner
- **"My content" view** filtered to the current user — the screen an owner actually lives in
- **Dashboard widget**: my overdue count, site overdue count, 30-day trend
- **Bulk actions**: assign owner, set interval, mark reviewed, exempt, clear exemption
- **Permissions**: `view lifecycle`, `assign owners`, `attest reviews`, `manage exemptions`, `configure expiry actions` — keep expiry configuration separate and admin-only
- **Antlers**: `{{ lifecycle:last_reviewed }}`, `{{ lifecycle:is_stale }}`, `{{ lifecycle:next_review }}`, and a `stale` boolean the template can use to render its own banner

A visible "Last reviewed: March 2026" line on the front end is a small feature with outsized value — it's good for readers, Google likes it, and it makes the product's work visible to people who never open the CP.

---

## 9. Commands

```
php please lifecycle:baseline   [--horizon=12] [--dry-run]
php please lifecycle:recompute  [--site=] [--collection=]
php please lifecycle:notify     [--dry-run]
php please lifecycle:expire     [--dry-run]        # dry-run is the default in docs
php please lifecycle:assign     --branch=/cardiology --owner=…
php please lifecycle:report     [--format=html|pdf|csv]
```

Scheduled: `recompute` daily, `notify` daily, `expire` daily (inert unless opted in).

---

## 10. First session for Code

1. **Migrations and models** for the four tables, with `next_review_at` indexed — every dashboard query hangs off it.
2. **The state computation service**: given an entry, resolve effective interval (entry override → blueprint → collection → global), resolve owner (assigned → inferred → fallback), compute `next_review_at` and status. Pure, unit-tested, no CP.
3. **`lifecycle:baseline`** with `--dry-run`, including the staggering algorithm. Run it against a large fixture set and confirm the distribution is actually spread and not clustered.
4. **`lifecycle:recompute`** as a queued batch, reusing A11y Report's job pattern.
5. **Attestation**: the write path plus the review history model. Confirm it does not touch the entry or create a revision.
6. Stop. No expiry actions, no notifications, no CP work until baseline and recompute are trustworthy on real data.

**Do not implement expiry actions in the first session.** Build the circuit breaker and the dry-run harness before anything that can change a live site.

---

## 11. Open questions

- **Owner as user or group?** Groups are what institutions actually need — people leave. Recommend supporting both from the schema (`owner_type`), even if the v1 UI only exposes users.
- **What counts as "reviewed"?** Explicit attestation only, or does editing the entry count? Recommend: editing does *not* count. Someone fixing a typo has not confirmed the medical content is still accurate, and conflating the two destroys the value of the record. Offer it as a config option, default off.
- **Multisite**: owner and interval per site, or shared across localizations? Recommend per site — a translation can be stale while the original is current.
- **Entry deletion**: orphaned `lifecycle_states` rows. Add cleanup to `lifecycle:recompute` and surface orphans in a doctor command.
- **Interaction with core Workflows when it ships** — revisit the boundary in §1 at that point; do not pre-build against a spec that doesn't exist yet.
- **PHP floor**: no reason for anything above 8.2 here.

---

## Sources

- [Statamic Roadmap](https://statamic.com/roadmap) — Workflows still *planned*, not in progress, as of September 8, 2026
- [Statamic Addons marketplace](https://statamic.com/addons) — no content-lifecycle or review-scheduling addon among the 488 listings
