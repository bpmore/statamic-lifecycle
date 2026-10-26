# Lifecycle

**Know who owns every page, when a person last confirmed it was accurate, and
what is overdue.**

Lifecycle gives every entry on a Statamic 6 site an owner and a review date,
records each time a named person confirms a page is still accurate, and reminds
people when reviews come due. It is built for sites where that record matters:
universities, hospitals, councils, anywhere an audit, a records request or an
accreditation visit asks *when was this page last checked, and by whom?*

Statamic 6.34+ · PHP 8.2+ · no database required · nothing on the live site
changes unless you turn it on.

```
php please lifecycle:report
```

```
41% of 31,598 entries have a confirmed owner. 78% of the 30,102 entries under
review were reviewed in the last 12 months. 412 are overdue.
```

## What it does

- **Day one on a 40,000-page site is a schedule, not a wall of red.** A
  baseline pass spreads first review dates across twelve months, oldest
  content first, and guesses owners from last authors, flagged as guesses.
  Nothing is overdue the morning after you install it.
- **Every page has an owner, assigned in bulk.** By structure branch,
  collection, blueprint, author, taxonomy term, a CSV a department sends back,
  or a selection in the entry listing, with an owner of last resort so nothing
  is ever ownerless. See
  [assigning owners](https://github.com/bpmore/statamic-lifecycle/blob/main/DOCUMENTATION.md#assigning-owners).
- **A review is a record, not an edit.** *Mark reviewed* puts who, when, an
  outcome and a note on an append-only history and **never writes to the
  entry**: no revision, no git noise, no re-triggered checks.
- **One reminder email per person.** A digest of everything they owe, with
  escalation to a department contact, quiet weekends, and each person's own
  frequency. Never 300 emails for 300 pages.
- **Stale content retires safely, if you want it to.** Notify-only by default.
  A collection can opt into a banner, a `noindex` or unpublishing, behind six
  safety rails, a dry run, and one-click undo. See
  [expiry actions](https://github.com/bpmore/statamic-lifecycle/blob/main/DOCUMENTATION.md#expiry-actions).
- **Publishing can require an owner and a review date.** Opt-in per
  collection, and never applied to content that already exists unless you
  say so.
- **Pages fixed in time can be exempted.** Annual reports, minutes, archives:
  out of the cycle, still owned.
- **Proof on demand.** Dashboards by collection, site and owner; "My content"
  for each owner; a dashboard widget with the thirty-day trend; and a
  governance report as CSV, JSON, HTML or a tagged PDF that passes PDF/UA
  validation.
- **"Last reviewed: March 2026" on the front end**, from three Antlers tags,
  so readers can see the work too.

**Lifecycle governs content that is already published.** Drafts, approvals and
editorial sign-off before publish are Statamic's own territory, and this addon
stays out of it.

## Installing

```
composer require bpmore/statamic-lifecycle
php please lifecycle:install
php please lifecycle:baseline --dry-run
php please lifecycle:baseline
```

That is the whole installation. No database is required — the addon keeps its
tables in a SQLite file of its own unless you point it at one of the site's
connections — and the four fields it needs appear in every entry's sidebar on
their own.

Then run the scheduler, if the site does not already: `lifecycle:recompute`
runs nightly, `lifecycle:notify` each morning, and `lifecycle:expire` daily
(inert until a collection opts in).

## Expiry actions: read the dry run first

Lifecycle is notify-only until a collection opts into an action, and every
action passes through six rails first. **Before turning anything on, and after
any change to `config/lifecycle.php`, run the dry run and read it:**

```
php please lifecycle:expire --dry-run
```

It prints exactly what a real run would do — what would happen now, who would
be notified, what is waiting, what was kept out and why — and changes nothing.
If it surprises you, the config is wrong, not the content.

## Documentation

| | |
|---|---|
| [Installing and day one](https://github.com/bpmore/statamic-lifecycle/blob/main/DOCUMENTATION.md#installing) | Tables, the baseline, the scheduler |
| [Owners, intervals and dates](https://github.com/bpmore/statamic-lifecycle/blob/main/DOCUMENTATION.md#the-four-fields) | What lives in the entry and what lives in the tables |
| [Attestation](https://github.com/bpmore/statamic-lifecycle/blob/main/DOCUMENTATION.md#marking-a-page-reviewed) | The record that makes this worth paying for |
| [Assigning owners](https://github.com/bpmore/statamic-lifecycle/blob/main/DOCUMENTATION.md#assigning-owners) | Branch, collection, blueprint, author, term, CSV, listing |
| [Reminders](https://github.com/bpmore/statamic-lifecycle/blob/main/DOCUMENTATION.md#reminders) | Digests, escalation, quiet days, each person's own frequency |
| [Expiry actions](https://github.com/bpmore/statamic-lifecycle/blob/main/DOCUMENTATION.md#expiry-actions) | The six rails, the dry run, putting things back |
| [The gate](https://github.com/bpmore/statamic-lifecycle/blob/main/DOCUMENTATION.md#the-gate) | Requiring an owner and a review date before publishing |
| [The governance report](https://github.com/bpmore/statamic-lifecycle/blob/main/DOCUMENTATION.md#the-governance-report) | Console, JSON, CSV, HTML, tagged PDF |
| [On the front end](https://github.com/bpmore/statamic-lifecycle/blob/main/DOCUMENTATION.md#on-the-front-end) | `{{ lifecycle:last_reviewed }}` and friends |
| [Permissions](https://github.com/bpmore/statamic-lifecycle/blob/main/DOCUMENTATION.md#permissions) | Five, and what each opens |
| [Commands](https://github.com/bpmore/statamic-lifecycle/blob/main/DOCUMENTATION.md#commands) | All of them, with their options |
| [Configuration](https://github.com/bpmore/statamic-lifecycle/blob/main/DOCUMENTATION.md#configuration) | Every setting, with its default |

## The governance suite

Lifecycle sits in the second family of bpmore's Statamic addons: governance,
beside the accessibility line (A11y Gate, A11y Report, A11y Docs). It speaks
the same plain-words voice, and when [Site Weather](https://github.com/bpmore/statamic-site-weather)
is installed it reports a **Freshness** band to the dashboard tile.

## Requirements

PHP 8.2+, Statamic 6.34 or newer. A database is optional. A browser (for the PDF export)
is optional and degrades to a clear message rather than a failure.

## Support

Support is through [GitHub issues](https://github.com/bpmore/statamic-lifecycle/issues).
Open one for a bug, a question, or something the documentation does not
answer. Say which Statamic and Lifecycle versions you are on and, for a
bug, what `php please lifecycle:doctor` printed.

## Development

```
composer install
vendor/bin/pest                # tests
vendor/bin/pint                # formatting
npm install && npm run build   # control panel assets (the build is committed)
```

## Licence

Proprietary. One licence per production site; local development and CI are
free. The source is published so it can be installed with Composer and
inspected by the people who rely on it. See [LICENSE.md](https://github.com/bpmore/statamic-lifecycle/blob/main/LICENSE.md) — in
particular what it says about the difference between "reviewed on 12 March"
and "correct on 12 March".
