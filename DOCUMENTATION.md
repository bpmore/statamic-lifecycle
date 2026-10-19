# Lifecycle

Content review dates, ownership, attestation and expiry for Statamic 6.

Lifecycle governs content that is already published: who owns each page, how often a person should confirm it is still accurate, when that last happened, and what to do when it stops happening. Drafts, approvals and editorial sign-off before publish are Statamic's own territory, and this addon stays out of it.

## Installing

Require the package, create the tables, and spread the first reviews out:

- `composer require bpmore/statamic-lifecycle`
- `php please lifecycle:install`
- `php please lifecycle:baseline --dry-run`, then without `--dry-run`

`lifecycle:install` creates the tables the addon keeps its state and history in. By default they go in a SQLite file the addon creates for itself under `storage/lifecycle/`, so a flat-file site needs nothing else. To put them in a database the site already runs, set `LIFECYCLE_CONNECTION` to one of its connection names (or `connection` in the config) and run the command again; it asks before creating tables on a connection the site administers. The review history is the compliance artifact, so a site that backs up its database and not its storage directory should do that.

Run `lifecycle:install` again after upgrading the package. It reports "already installed" when there is nothing to do, and applies any table changes a newer version needs.

**The scheduler has to be running.** Three commands are scheduled for you: `lifecycle:recompute` nightly, `lifecycle:notify` at 07:00 (`LIFECYCLE_SEND_AT`), and `lifecycle:expire` at 06:30 (`LIFECYCLE_EXPIRE_AT`). None runs until `lifecycle:install` has. The recompute is queued as a batch of jobs; with the `sync` queue driver it simply runs inline.

### Day one on a large site

Install Lifecycle on a real institutional site and every page would instantly be *never reviewed*, *no owner*, *overdue*. A dashboard of forty thousand red rows is not actionable. So the baseline pass, run once at install, does three things:

- Gives every existing entry a first review date **spread across a horizon** — twelve months by default, `--horizon=` to change it — ordered by last-modified date so the oldest content surfaces first. Nothing is overdue on day one, and the spread is even to the day.
- Seeds each page's owner from its last author where one exists, flagged as **inferred** rather than assigned, so every screen can tell "we guessed" from "a person confirmed it". The command warns when one account would be inferred for most of the site — an import account, an administrator who re-saved everything during a migration — and `owners.never_infer` names the accounts it must not guess.
- Leaves the spread alone from then on. The nightly recompute keeps each page's baseline date until a person reviews it.

Run it with `--dry-run` first: it prints the distribution and changes nothing.

## The four fields

Four fields appear in the sidebar of every entry blueprint on their own; nobody has to edit sixty blueprints. A site that wants them somewhere else declares a field with the same handle wherever it likes, and the addon leaves it there.

- **Content owner** (`content_owner`) — the person who knows whether this page is still accurate. They get the reminders.
- **Review interval** (`review_interval`) — monthly, quarterly, every six months, yearly, every two years, every three years. Empty means the default for the collection.
- **Review by** (`review_by`) — a hard deadline that overrides the interval, for when something outside the page sets the date: an accreditation visit, a board meeting, a rule that changes.
- **Exempt from review** (`review_exempt`) — a toggle that takes the page out of the cycle: no review date, no reminders, no expiry action. For content fixed at a point in time: a published report, a minuted decision, an archive. The owner still owns it.

All four are localizable: a translation can be stale while the original is current, so its owner and cadence are its own; a translation inherits its origin's exemption until it says otherwise.

**Authored intent lives in the entry; operational state lives in the tables.** An owner is an editorial decision, it belongs in content, it shows in git and travels with revisions. Everything computed from the fields — the next review date, the status, the attestation record — lives in the addon's tables and never touches the entry. That split is the most important decision in the product, and the reason marking a page reviewed never creates a revision.

### How the date is worked out

The effective interval is the most specific of: the entry's `review_interval`, then `intervals.blueprints` (keyed `collection/blueprint` or just `blueprint`), then `intervals.collections`, then `intervals.default` (yearly). A `review_by` date wins over any computed date. The next review is the last attestation plus the interval, or the baseline date for a page nobody has reviewed yet.

The owner is the most specific of: the `content_owner` on the entry (*assigned*), the last author (*inferred*, unless `owners.infer_from_last_author` is off or the account is in `never_infer`), then the site's owner of last resort (*fallback*). Nothing is ever ownerless.

Five statuses: **fresh**, **due soon** (within `due_soon_days`, 30 by default), **overdue**, **expired** (overdue for `expired_after_days`, 30 by default — the state in which expiry actions, where a collection has turned them on, apply), and **exempt** — the page's *Exempt from review* is on, and none of the rest applies.

### Owner of last resort and escalation contacts

Under **Addons → Lifecycle → Settings** in the control panel, or in the config: an **owner of last resort** per site, and an **escalation contact** per collection — the head of the department the collection belongs to. A person named in either who has since left is not used.

## Marking a page reviewed

This is the record people pay for. Dates and reminders are free-addon material; the attestation history is what answers the question every audit, records request and accreditation review asks: *when did a human last confirm this page was accurate, and who were they?*

**Mark reviewed** is in the sidebar panel of every entry and in the entry listing's action menu, for one entry or many. It asks for an outcome — *Still current*, *I updated it*, *Needs a rewrite, and one is planned*, *Retire it* — and an optional note, and writes who, when, the outcome and the note to an append-only record. Withdrawing nothing, editing nothing: the record only grows.

**It does not write to the entry.** Not a field, not a timestamp, not a revision — proven by the test suite with revisions turned on. The entry file is byte-for-byte what it was.

Editing a page does not count as reviewing it. Somebody fixing a typo has not confirmed that the medical content is still accurate, and conflating the two would destroy the value of the record.

A site that decides otherwise can set `reviews.editing_counts` to `true`. Saving a published page in the control panel is then recorded as that person reviewing it — outcome *I updated it*, once per person per page per day — and the record marks it *from an edit*, so an attestation is still an attestation. Drafts, saves that change nothing, saves with nobody signed in, and Lifecycle's own writes are never counted.

### The sidebar panel

Under the four fields, a **Review** panel shows the page's status and next review date and why it is that date, the owner and how they came to be the owner, who last reviewed the page and when, the full history, the *Mark reviewed* button, and — when Lifecycle has changed the page — what it did and a button to put it back.

## Assigning owners

Nobody is assigning owners to forty thousand pages one at a time. `lifecycle:assign` does it by the batch, says exactly what it is about to do, asks, and offers `--dry-run`:

```
php please lifecycle:assign --branch=/cardiology --owner=cardiology-lead@example.edu
php please lifecycle:assign --collection=policies --owner=records@example.edu
php please lifecycle:assign --collection=news --blueprint=press_release --owner=…
php please lifecycle:assign --author=the-import-account --owner=…
php please lifecycle:assign --term=departments::cardiology --owner=…
php please lifecycle:assign --csv=owners.csv
```

`--branch` takes everything at and under a URI in a structured collection, read from the site named by `--site` (the default site otherwise). The selectors combine: `--collection` with `--blueprint`, either with `--site`.

The CSV is the file a department sends back after being asked "who owns these pages?", so it forgives what such files have: a header row or none, a BOM, blank lines, whitespace, full URLs pasted from a browser. One row per entry — the entry as a URL, a URI or an ID; the owner as a user ID or an email — and `--owner=` for rows that name none. What it cannot resolve it reports by line number, and carries on.

In the control panel, **Assign owner** is a bulk action on the entry listing: filter, select, assign. **Set review interval** sits beside it — pick the interval, or *the default for the collection* — for whoever may edit the pages; it is the same edit the sidebar field makes, done for a selection at once.

Assigning writes to the entry, and on purpose: an owner is content. Each saved entry is a change like any other; it shows in git.

## Exempting a page from review

Some pages are fixed at a point in time and will never need a person to confirm them again: the 2023 annual report, the minutes of a meeting, an archive kept for the record. Turn on **Exempt from review** in the sidebar, or select pages in the entry listing and run **Exempt from review**; **Clear exemption** puts them back. An exempt page has no review date, gets no reminders, is never expired, and is counted as exempt rather than as never reviewed. It still has an owner.

Clearing an exemption works the date out as for any other page — from its last review, its baseline date, or its last modification — so a page that was exempt for years may come back overdue. That is the honest reading.

Like the owner, the exemption is written into the entry: it is a decision about the content, and an auditor should find it in the page's history.

## The screens

**Lifecycle**, under Tools, is the utility screen: how much content is in what state, and the same by collection, by site and by owner, with a way into each list. The list filters by status, by collection, by site, by owner, and by what needs attention — never reviewed, no owner, owner inferred rather than confirmed — and each row links to the entry. *Mark reviewed*, *Assign owner*, *Set review interval*, *Exempt from review* and *Clear exemption* are Statamic entry actions: in the sidebar panel, and on the collection's own entry listing for one entry or many.

**My content** is the same list for the person looking at it: the screen an owner actually lives in.

**The dashboard widget** shows the person's own overdue count, the site's, and the thirty-day trend. Add it to `config/statamic/cp.php`:

```php
'widgets' => [
    ['type' => 'lifecycle', 'width' => 50],
],
```

The trend needs history, so the nightly recompute writes one row of counts per day; the sparkline appears after a few nights.

## Reminders

Reminders are **digests**: one email per person listing everything they owe, however long the list. An owner with three hundred stale pages gets one email, never three hundred — getting this wrong is how a sending domain ends up classified as spam and an addon uninstalled the same morning.

The schedule, under `notifications`:

- `schedule` — days before the review date to write, `[30, 7, 0]` by default; 0 is the day itself. Ahead of the date only the latest stage to have begun is sent, once per review date.
- `overdue_every` — once the date has passed, a reminder goes at once and then every so many days (7), or once only when 0.
- `quiet_days` and `quiet_dates` — days nothing is sent (weekends by default, plus any holidays as `Y-m-d`). What is owed waits for the next day that is not quiet; nothing is lost.

**Escalation**, under `escalation`: when an owner has left a page overdue for `after_days` (14), the collection's escalation contact hears about it too, on the same cadence; after `last_resort_after_days` (30), so does the site's owner of last resort. Nobody is told twice over in two roles.

Each person chooses their own frequency under **Preferences → Lifecycle → Review reminders**: daily when there is something, weekly at most, or never by email. The dashboard shows their pages whatever they choose; the preference mutes the mail, not the record.

`php please lifecycle:notify --dry-run` shows who would be reminded about what, and sends nothing.

## Expiry actions

**Off by default. Nothing on the live site changes unless a collection opts in.** Three actions, each enabled independently per collection:

- **banner** — sets a field on the entry (`lifecycle_banner`) to true, and no more. Your template decides whether to show a banner; Lifecycle never injects markup into a front end.
- **noindex** — sets `lifecycle_noindex`, or the field named in `expiry.fields.noindex`, so point it at your SEO addon's own field if it has one.
- **unpublish** — through Statamic's own publish path, which knows about revisions.

```php
'expiry' => [
    'collections' => [
        'news' => ['banner' => true, 'noindex' => true, 'unpublish' => false],
    ],
],
```

Because a config change can reclassify thousands of entries at once, every action passes through six rails:

1. **A circuit breaker.** If one run would touch more than `circuit_breaker.max_share` of a collection (10%), the whole collection is refused: nothing is changed, the refusal is logged, and an administrator is emailed — the addresses in `alert_to`, or every super user when none are named. `min_affected` lets a small collection through when fewer pages than that are involved.
2. **A daily cap** on automated actions site-wide, `daily_cap` (25), every kind but a restore. What it holds back is done on a later day.
3. **A notice period.** The owner is told in their digest, naming the action and the date, and the action happens `notice_days` (7) later — never in the same run, whatever the number.
4. **A never-touch list**, by collection handle, by URL pattern (`/legal/*`) or by entry ID. The home page is on it from the start.
5. **A navigation check.** A page a navigation links to is never unpublished unless it is named in `unpublish_in_navigation`, or allowed for one run with `--allow-nav=`. A menu that quietly leads to a 404 is the failure this prevents.
6. **A log with one-click revert.** Every action records the page's previous state. **Put it back**, in the sidebar panel and the entry listing, undoes the last thing Lifecycle did to a page and records that it was undone, by whom.

And the seventh, which is where to start:

```
php please lifecycle:expire --dry-run
```

It prints exactly what a real run would do — what would happen now, who would be notified, what is waiting, what was kept out and why — and changes nothing. Run it before turning anything on and after every change to the config. If it surprises you, the config is wrong, not the content.

## The gate

A collection can require a confirmed owner and a review date before an entry publishes. Off on install; nothing changes about what you can publish today.

```php
'gate' => [
    'collections' => [
        'policies' => '2026-10-01',   // entries created on or after this date
        'news' => 'all',              // every entry, the backlog included
    ],
],
```

The date is the day the gate went on. Entries created before it are never gated — the backlog is spread by the baseline, not blocked — and `'all'` is how a site says it means the backlog too. A bare `true` names no day and is refused: the collection stays ungated and the log says why. Drafts are never gated.

A refused save says what is missing, under the field where it is set, in plain words: *Choose a content owner before publishing. Every entry in Policies needs one.* — *Set a review-by date or a review interval before publishing. Every entry in Policies needs one.*

Confirmed means a person named on the page who still exists: an owner inferred from the last author does not count, the owner of last resort does not count, and a named owner who has since left gets their own line. A review date is either a review-by date or a review interval — both are the author saying when. A page exempt from review has answered "never", and needs only an owner.

Lifecycle's own writes — assigning an owner, an interval, an exemption, an expiry flag, a put-back — go through the gate unchecked. Anything else that saves an entry with events on, an import script included, is refused like a person would be.

## The governance report

```
php please lifecycle:report
php please lifecycle:report --format=json --out=report.json
php please lifecycle:report --format=csv --out=report.csv
php please lifecycle:report --format=html --out=report.html
php please lifecycle:report --format=pdf --out=storage/governance-report.pdf
```

The per-site answer to what an audit asks: the share of content with a **confirmed** owner (not inferred, not the fallback); the share reviewed by a person in the last twelve months, of the content under review; where the overdue content is, by collection, by site and by owner; twelve months of review activity by month and outcome; and what has never been reviewed since it was baselined. Tables on the console by default; the other formats go to `--out`, or to standard output for JSON, CSV and HTML.

The PDF is printed from accessible HTML by headless Chrome, Chromium or Edge — found in the usual places, or named in `report.chrome` — and an XMP packet is added afterwards so the file **passes PDF/UA-1 validation**. A governance report an auditor's screen reader cannot read is not one. No browser installed is not an error: you get a sentence saying so, and the HTML is one option away.

On Ubuntu, Chromium is a snap and cannot write outside your home directory; write the PDF to a path inside the site, such as `storage/governance-report.pdf`.

## On the front end

```antlers
<p>Last reviewed: {{ lifecycle:last_reviewed format="F Y" }}</p>

{{ if {lifecycle:is_stale} }}
  <p class="notice">This page is overdue for review and may be out of date.</p>
{{ /if }}
```

- `{{ lifecycle:last_reviewed }}` and `{{ lifecycle:next_review }}` take a `format` (the site's date format otherwise) and are empty when there is nothing to say — a page nobody has reviewed prints nothing rather than a date it cannot stand behind.
- `{{ lifecycle:is_stale }}` is true when the page is overdue or expired.
- `{{ lifecycle:status }}` is `fresh`, `due_soon`, `overdue`, `expired` or `exempt`.
- The pair gives a template everything at once:

```antlers
{{ lifecycle }}
  {{ if stale }}…{{ /if }}
  {{ status_label }} · reviewed {{ last_reviewed format="j F Y" }} by {{ last_reviewed_by }} · owner {{ owner }}
{{ /lifecycle }}
```

The tags read the page being rendered, or `entry="…"`, once per request, and never compute. A page Lifecycle does not track, or a site that has not run `lifecycle:install`, renders empty and never errors. The expiry banner is a separate thing: `{{ lifecycle_banner }}` is the field the action sets, and `stale` is the computed status.

## Permissions

Five, in their own group on the Permissions screen:

- **View lifecycle** — the dashboard, the full list, and the site-wide numbers on the widget.
- **Attest reviews** — mark a page reviewed, in one's own name, on a page the person can see.
- **Assign owners** — say who owns a page, on pages the person may edit.
- **Manage exemptions** — take a page out of the review cycle, or put it back, on pages the person may edit.
- **Configure expiry actions** — put back what an automated expiry action changed. Keep it to administrators.

Everyone signed in sees their own content — "My content", the sidebar panel, the widget's "Yours" — without any of them. Turning expiry actions on is done in `config/lifecycle.php`, by whoever deploys the site, and nowhere in the control panel. Super users pass everything, as they do in Statamic.

## Site Weather

When [Site Weather](https://github.com/bpmore/statamic-site-weather) is installed, Lifecycle reports a **Freshness** band to its dashboard tile from the stored share of content that is overdue or expired: clear when nothing is late; fair under a tenth; overcast at a tenth, or when anything has expired; rain at a quarter; storm at half. It links to the Lifecycle dashboard and reads *Not set up yet* rather than clear until the tables exist and something is tracked.

## Multisite

Owners, intervals and review dates are per site. A translation can be stale while the original is current, and a localization that has not set its own owner is owned by whoever owns the original until somebody says otherwise. The owner of last resort is per site.

## Commands

| | |
|---|---|
| `lifecycle:install [--force]` | Create or upgrade the tables. Asks before creating tables on a connection the site administers. |
| `lifecycle:baseline [--horizon=12] [--dry-run]` | Spread first review dates across the horizon and seed inferred owners. Run once. |
| `lifecycle:recompute [--site=] [--collection=] [--sync]` | Bring every published entry's state up to date, and sweep rows for deleted entries. Scheduled nightly. |
| `lifecycle:assign --owner= [--branch=] [--collection=] [--blueprint=] [--author=] [--term=] [--csv=] [--site=] [--dry-run] [--force]` | Assign owners in bulk. |
| `lifecycle:notify [--dry-run]` | Send the day's digests. Scheduled each morning. |
| `lifecycle:expire [--dry-run] [--collection=] [--allow-nav=*]` | Run the expiry actions collections have opted into. Scheduled daily; inert until then. |
| `lifecycle:report [--format=table\|json\|csv\|html\|pdf] [--out=]` | The governance report. |

## Configuration

Publish the config with `php please vendor:publish --tag=lifecycle-config`. Everything has a working default.

| Key | Default | |
|---|---|---|
| `connection` | `LIFECYCLE_CONNECTION` | Null for the addon's own SQLite file; a connection name to use the site's database. |
| `intervals.default` | `yearly` | An interval name or a number of days. |
| `intervals.collections`, `intervals.blueprints`, `intervals.named` | `[]` | Per collection, per blueprint (`collection/blueprint` or `blueprint`), and names a site adds. |
| `owners.infer_from_last_author` | `true` | Guess an owner from the last author, flagged as inferred. |
| `owners.never_infer` | `[]` | Accounts never to guess: the import account, the migrating administrator. |
| `owners.fallback` | `[]` | Owner of last resort, per site or one for all. The settings screen wins per site. |
| `due_soon_days` | `30` | |
| `expired_after_days` | `30` | Overdue this long is expired. |
| `reviews.editing_counts` | `false` | Whether saving a published page counts as that person reviewing it. Recorded as from an edit. |
| `notifications.schedule` | `[30, 7, 0]` | Days before the review date. |
| `notifications.overdue_every` | `7` | Then every so many days; 0 for once only. |
| `notifications.send_at` | `07:00` | `LIFECYCLE_SEND_AT`. |
| `notifications.quiet_days`, `quiet_dates` | weekends, `[]` | |
| `escalation.after_days` | `14` | To the collection's contact. |
| `escalation.last_resort_after_days` | `30` | To the owner of last resort. |
| `escalation.contacts` | `[]` | Per collection. The settings screen wins per collection. |
| `report.chrome`, `report.timeout` | null, `120` | `LIFECYCLE_CHROME`, `LIFECYCLE_CHROME_TIMEOUT`. |
| `gate.collections` | `[]` | A date, or `'all'`, per collection. |
| `expiry.collections` | `[]` | Per collection: `banner`, `noindex`, `unpublish`. |
| `expiry.fields` | `lifecycle_banner`, `lifecycle_noindex` | The fields the flags set. |
| `expiry.run_at` | `06:30` | `LIFECYCLE_EXPIRE_AT`. |
| `expiry.circuit_breaker.max_share`, `min_affected` | `10%`, `0` | |
| `expiry.daily_cap` | `25` | |
| `expiry.notice_days` | `7` | |
| `expiry.never_touch.collections`, `urls`, `entries` | `[]`, `['/']`, `[]` | |
| `expiry.unpublish_in_navigation` | `[]` | Entry IDs that may be unpublished from a navigation. |
| `expiry.alert_to` | `[]` | Who hears when the breaker trips; every super user when empty. |
| `recompute.chunk_size`, `recompute.queue` | `250`, null | `LIFECYCLE_RECOMPUTE_CHUNK`, `LIFECYCLE_QUEUE`. |

## What it does not do

It does not approve, route or sign off content before it is published; Statamic's own workflows are for that. It does not verify content: a review records that a named person confirmed a page on a date, and nothing here claims a page is accurate. An inferred owner is a guess until a person confirms it.
