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
