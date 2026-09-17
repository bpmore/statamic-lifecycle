# Lifecycle

**Content review dates, ownership, attestation and expiry for Statamic 6.**

Every page on an institutional site has someone who is supposed to know whether
it is still true, and almost none of them have a record of when that person last
checked. Lifecycle gives each entry an owner, a review interval and a next review
date; lets the owner record that they reviewed it; and keeps that record where an
auditor can find it.

```
php please lifecycle:report
```

```
41% of 31,598 entries have a confirmed owner. 78% of the 30,102 entries under
review were reviewed in the last 12 months. 412 are overdue.
```

**Lifecycle governs content that is already published.** Drafts, approvals and
editorial sign-off before publish are Statamic's own territory, and this addon
stays out of it.

## What it does

- **Baselines a 40,000-page site without despair.** Existing content is given
  first review dates spread across a horizon (twelve months by default), oldest
  first. Nothing is overdue on day one.
- **Assigns owners in bulk** — by structure branch, collection, blueprint,
  author, taxonomy term, from a CSV, or from the entry listing — with an
  owner of last resort so nothing is ever ownerless. See
  [bulk assignment](DOCUMENTATION.md#assigning-owners).
- **Records attestations.** *Mark reviewed* puts who, when, an outcome and a
  note on an append-only record, and **never writes to the entry**: no
  revision, no git noise, no re-triggered checks. This is the record an
  audit, a records request or an accreditation review asks for.
- **Reminds people, in digests.** One email per person listing everything
  they owe, on a schedule, with escalation and a quiet window. Never 300
  emails for 300 pages.
- **Retires stale content, if you ask it to.** Notify-only until a
  collection opts in; then a banner variable, a `noindex`, or unpublishing —
  behind a circuit breaker, a daily cap, a notice period, a never-touch list,
  a navigation check, and a log with one-click revert. See
  [expiry actions](DOCUMENTATION.md#expiry-actions).
- **Reports.** Dashboards by collection, site and owner; "My content" for
  each owner; a dashboard widget; and a governance report as console tables,
  JSON, CSV, accessible HTML or a tagged PDF that passes PDF/UA validation.

## Installing

```
composer require bpmore/statamic-lifecycle
php please lifecycle:install
php please lifecycle:baseline --dry-run
php please lifecycle:baseline
```

That is the whole installation. No database is required — the addon keeps its
tables in a SQLite file of its own unless you point it at one of the site's
connections — and the three fields it needs appear in every entry's sidebar on
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
| [Installing and day one](DOCUMENTATION.md#installing) | Tables, the baseline, the scheduler |
| [Owners, intervals and dates](DOCUMENTATION.md#the-three-fields) | What lives in the entry and what lives in the tables |
| [Attestation](DOCUMENTATION.md#marking-a-page-reviewed) | The record that makes this worth paying for |
| [Assigning owners](DOCUMENTATION.md#assigning-owners) | Branch, collection, blueprint, author, term, CSV, listing |
| [Reminders](DOCUMENTATION.md#reminders) | Digests, escalation, quiet days, each person's own frequency |
| [Expiry actions](DOCUMENTATION.md#expiry-actions) | The six rails, the dry run, putting things back |
| [The gate](DOCUMENTATION.md#the-gate) | Requiring an owner and a review date before publishing |
| [The governance report](DOCUMENTATION.md#the-governance-report) | Console, JSON, CSV, HTML, tagged PDF |
| [On the front end](DOCUMENTATION.md#on-the-front-end) | `{{ lifecycle:last_reviewed }}` and friends |
| [Permissions](DOCUMENTATION.md#permissions) | Four, and what each opens |
| [Commands](DOCUMENTATION.md#commands) | All of them, with their options |
| [Configuration](DOCUMENTATION.md#configuration) | Every setting, with its default |

## The governance suite

Lifecycle sits in the second family of bpmore's Statamic addons: governance,
beside the accessibility line (A11y Gate, A11y Report, A11y Docs). It speaks
the same plain-words voice, and when [Site Weather](https://github.com/bpmore/statamic-site-weather)
is installed it reports a **Freshness** band to the dashboard tile.

## Requirements

PHP 8.2+, Statamic 6. A database is optional. A browser (for the PDF export)
is optional and degrades to a clear message rather than a failure.

## Support

Support is through [GitHub issues](https://github.com/bpmore/statamic-lifecycle/issues).
Open one for a bug, a question, or something the documentation does not
answer. Say which Statamic and Lifecycle versions you are on and, for a
bug, what `lifecycle:report` or the dry run printed.

## Development

The addon is developed on its own and linked into a host site through a Composer
path repository, which is the layout Statamic recommends for an addon reused
across projects.

```
composer install
vendor/bin/pest                # tests
vendor/bin/pint                # formatting
npm install && npm run build   # control panel assets (the build is committed)
```

## Licence

Proprietary. One licence per production site; local development and CI are
free. The source is published so it can be installed with Composer and
inspected by the people who rely on it. See [LICENSE.md](LICENSE.md) — in
particular what it says about the difference between "reviewed on 12 March"
and "correct on 12 March".
