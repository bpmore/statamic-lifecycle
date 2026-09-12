# Lifecycle

**Content review dates, ownership, attestation and expiry for Statamic 6.**

Every page on an institutional site has someone who is supposed to know whether
it is still true, and almost none of them have a record of when that person last
checked. Lifecycle gives each entry an owner, a review interval and a next review
date; lets the owner record that they reviewed it; and keeps that record where an
auditor can find it.

Lifecycle governs content that is *already published*. Drafts, approvals and
editorial sign-off before publish are Statamic's own territory, and this addon
stays out of it.

> **Pre-release.** The package scaffold is in place; the product is being built
> phase by phase and is not yet installable for use. This file will be rewritten
> before 1.0.

## Expiry actions: read the dry run first

Lifecycle is notify-only until a collection opts into an action — a banner
variable, a `noindex`, or unpublishing — and every action passes through six
rails first: a circuit breaker on the share of a collection one run may touch,
a daily cap, a mandatory notice period, a never-touch list, a navigation check,
and a log with one-click revert.

**Before turning anything on, and after any change to `config/lifecycle.php`,
run the dry run and read it:**

```
php please lifecycle:expire --dry-run
```

It prints exactly what a real run would do — what would happen now, who would
be notified, what is waiting, what was kept out and why — and changes nothing.
If it surprises you, the config is wrong, not the content.

## The gate: off until a collection asks

A collection can require a confirmed owner and a review date before an entry
publishes. Off on install; nothing changes about what you can publish today.

```php
'gate' => [
    'collections' => [
        'policies' => '2026-10-01',   // entries created on or after this date
        'news' => 'all',              // every entry, the backlog included
    ],
],
```

The date is the day the gate went on. Entries created before it are never
gated — the backlog is spread by `lifecycle:baseline`, not blocked — and
`'all'` is how a site says it means the backlog too. Drafts are never gated.

A refused save says what is missing, under the field where it is set:

> Choose a content owner before publishing. Every entry in Policies needs one.
>
> Set a review-by date or a review interval before publishing. Every entry in
> Policies needs one.

Confirmed means a person named on the page, who still exists: an owner the
addon inferred from the last author does not count, and neither does the owner
of last resort.

## Permissions

Four, in their own group on the Permissions screen:

| | |
|---|---|
| **View lifecycle** | The dashboard, the full list, and the site-wide numbers on the widget. |
| **Attest reviews** | Mark a page reviewed, in one's own name. |
| **Assign owners** | Say who owns a page, on pages the person may edit. |
| **Configure expiry actions** | Put back what an automated expiry action changed. Keep it to administrators. |

Everyone signed in sees their own content — "My content", the sidebar panel,
the widget's "Yours" — without any of them. Turning expiry actions on is done
in `config/lifecycle.php`, by whoever deploys the site, and nowhere in the
control panel.

## The governance report

```
php please lifecycle:report
php please lifecycle:report --format=pdf --out=storage/governance-report.pdf
```

The per-site answer to what an audit, a records request or an accreditation
review asks: how much content has a confirmed owner, how much a person has
reviewed in the last twelve months, where the overdue content is by
collection, site and owner, how much reviewing is happening month by month,
and what has never been reviewed since baselining. Tables on the console;
`--format=json`, `csv`, `html` or `pdf` with `--out=`.

The PDF is printed from accessible HTML by headless Chrome and passes PDF/UA-1
validation — the same pipeline A11y Docs proved. A governance report an
auditor's screen reader cannot read is not one. No browser installed is not
an error: you get the HTML.

## On the front end

A visible "Last reviewed" line is a small thing with outsized value: good for
readers, good for search, and it shows the product's work to people who never
open the control panel.

```antlers
<p>Last reviewed: {{ lifecycle:last_reviewed format="F Y" }}</p>

{{ if {lifecycle:is_stale} }}
  <p class="notice">This page is overdue for review and may be out of date.</p>
{{ /if }}
```

`last_reviewed` and `next_review` take a `format`, and are empty when there is
nothing to say — a page nobody has reviewed yet prints nothing rather than a
date it cannot stand behind. `is_stale` is true when the page is overdue or
expired. The pair gives a template everything at once:

```antlers
{{ lifecycle }}
  {{ if stale }}…{{ /if }}
  {{ status }} · reviewed {{ last_reviewed format="j F Y" }} by {{ last_reviewed_by }} · owner {{ owner }}
{{ /lifecycle }}
```

The tags read; they never compute. A page Lifecycle does not track, or a site
that has not run `lifecycle:install`, renders empty and never errors.

## Development

The addon is developed on its own and linked into a host site through a Composer
path repository, which is the layout Statamic recommends for an addon reused
across projects.

```
composer install
vendor/bin/pest            # tests
vendor/bin/pint            # formatting
npm install && npm run build   # control panel assets (the build is committed)
```

Requires PHP 8.2+ and Statamic 6.

## Licence

Sold, one licence per production site. See [LICENSE.md](LICENSE.md).
