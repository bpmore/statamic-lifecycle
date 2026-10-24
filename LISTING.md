# Marketplace listing copy

Copy for the Statamic marketplace listing.

---

## Name

**Lifecycle**

> **Lifecycle: Content Review Dates, Owners and Attestation for Statamic**

No "A11y" prefix, on purpose. Lifecycle is content governance, not
accessibility, and forcing it into that family would misrepresent it. It anchors
the second family — governance — beside the accessibility line.

The subtitle carries "attestation" deliberately. Review dates and reminders are
free-addon material; the attestation record is what makes this worth $99, and it
is the word an auditor, a records officer or an accreditation reviewer will
search for.

## Price

$99 per site.

## One-liner

Know who owns every page, when a person last confirmed it was accurate, and what
is overdue.

## Summary (marketplace card)

Gives every page an owner and a review date, records who confirmed it and when,
and retires what goes stale.

104 characters.

Not "keeps your content accurate", which it cannot do — a review records that a
person confirmed a page on a date, and the licence is explicit that this is not
a claim about the content.

## Long description

Every page on an institutional site has someone who is supposed to know whether
it is still true, and almost none of them have a record of when that person last
checked. That record is the first thing an audit, a records request or an
accreditation review asks for.

Lifecycle keeps it.

```
41% of 31,598 entries have a confirmed owner. 78% of the 30,102 entries under
review were reviewed in the last 12 months. 412 are overdue.
```

**Every page gets an owner, a review interval and a next review date.** Four
fields in the sidebar of every entry — owner, interval, a review-by deadline,
and an exemption for pages fixed at a point in time — added on their own;
nobody edits sixty blueprints. An owner is content: it lives in the entry,
shows in git and travels with revisions.

**Marking a page reviewed never touches the entry.** Who, when, an outcome and a
note go on an append-only record in the addon's own tables. No revision, no git
noise, no re-triggered checks. That split is the most important decision in the
product, and it is why the record can be trusted.

**Installing it on a 40,000-page site does not produce 40,000 red rows.** A
baseline pass spreads first review dates across a horizon, oldest content
first, and seeds owners from the last author — flagged as a guess until a
person confirms it. Nothing is overdue on day one.

**Owners are assigned in bulk** — by structure branch, collection, blueprint,
author, taxonomy term, from the spreadsheet a department sends back, or from the
entry listing — with an owner of last resort so nothing is ever ownerless.

**Reminders are digests.** One email per person listing everything they owe,
with escalation to a department contact and then the site's owner of last
resort, a quiet window for weekends and holidays, and each person's own
frequency. Never three hundred emails for three hundred pages.

**Retiring stale content is opt-in and guarded.** Notify-only until a collection
opts in; then a banner variable, a noindex, or unpublishing — behind a circuit
breaker on the share of a collection one run may touch, a daily cap, a notice
period, a never-touch list, a navigation check, and a log with one-click
revert. `lifecycle:expire --dry-run` prints exactly what would happen and
changes nothing.

**Reports say what they are.** Dashboards by collection, site and owner; "My
content" for each owner; a dashboard widget with the thirty-day trend; and a
governance report as console tables, JSON, CSV, accessible HTML or a tagged PDF
that passes PDF/UA validation.

**A visible "Last reviewed: March 2026" on the front end**, in three Antlers
tags. Good for readers, good for search, and it shows the product's work to
people who never open the control panel.

### What it does not do

It does not approve, route or sign off content before it is published. Drafts,
approvals and editorial workflows are Statamic's own territory, and this addon
stays out of it. It does not verify content, either: a review records that a
named person confirmed a page on a date, and nothing here claims a page is
accurate.

## Features list

- Owner, review interval, review-by date and an exemption on every entry,
  added without editing a blueprint
- *Mark reviewed*: an append-only attestation record that never writes to the
  entry
- A baseline pass that spreads first reviews across twelve months and seeds
  inferred owners
- Bulk owner assignment by branch, collection, blueprint, author, taxonomy
  term, CSV, or from the entry listing; an owner of last resort per site
- *Set review interval*, *Exempt from review* and *Clear exemption* as
  listing actions, for one page or many
- Digest reminders with escalation, a quiet window, and per-person frequency
- Expiry actions — banner variable, noindex, unpublish — off by default,
  per collection, behind six rails and a dry run
- A publish gate requiring an owner and a review date, off by default, never
  the backlog unless asked
- Dashboards by collection, site and owner; "My content"; a dashboard widget
- `lifecycle:report` as tables, JSON, CSV, accessible HTML and tagged PDF
  (PDF/UA-1 validated); `lifecycle:doctor` to check install, config,
  content and scheduler in one run
- `{{ lifecycle:last_reviewed }}`, `{{ lifecycle:next_review }}`,
  `{{ lifecycle:is_stale }}`
- Five permissions; expiry configuration stays admin-only
- No database required: the addon brings its own SQLite file, or uses yours
- Reports a Freshness band to Site Weather

## Requirements

Statamic 6, PHP 8.2+. A database is optional; a browser is optional for the
PDF export.

## Support

GitHub issues: https://github.com/bpmore/statamic-lifecycle/issues. The
repository is public from the day the listing is, so the link works for
customers and the source can be read before buying.

## Suite positioning

First of the governance family, beside the accessibility line:

- **A11y Gate** — free. Blocks entries with accessibility problems.
- **A11y Report** — proves the site, with a conformance report.
- **A11y Docs** — covers the asset library.
- **Lifecycle** — this one. Who owns every page, and when a person last
  confirmed it.

Same plain-words voice throughout: a customer who owns two of these never
learns two vocabularies.

## Categories

In order; the marketplace treats the first as primary.

| | |
|---|---|
| **Utility** | Primary, matching the accessibility line, so browsing Utility shows the suite together. |
| **CLI** | Eight commands — install, baseline, recompute, assign, notify, expire, report, doctor — most of them with a dry run. |
| **Widget** | The dashboard widget: my overdue count, the site's, the thirty-day trend. |
| **Tag** | Three Antlers tags and a pair. |

Not **Fieldtype**: the addon registers one, but only for its own sidebar panel;
nobody adds it by hand. Not **Analytics**: on a CMS marketplace that means
traffic tools.

## Keywords

governance, content review, content ownership, attestation, audit, records,
accreditation, expiry, stale content, lifecycle
