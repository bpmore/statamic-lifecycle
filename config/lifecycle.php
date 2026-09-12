<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Where the tables are kept
    |--------------------------------------------------------------------------
    |
    | Null means the addon's own SQLite file under storage/lifecycle/, created
    | by `php please lifecycle:install`. Most Statamic sites are flat-file and
    | have no database, and requiring one to try an addon is a reason not to
    | try it.
    |
    | Name one of the site's own connections to put the tables there instead.
    | The review history is the compliance artifact, so a site that backs up
    | its database and not its storage directory should do that.
    |
    */

    'connection' => env('LIFECYCLE_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Review intervals
    |--------------------------------------------------------------------------
    |
    | How often a page needs a person to confirm it is still accurate, when
    | the entry itself does not say. Resolved most specific first:
    |
    |   the entry's review_interval field
    |   → 'blueprints' (keyed 'collection/blueprint', or just 'blueprint')
    |   → 'collections'
    |   → 'default'
    |
    | Every value may be an interval name — monthly, quarterly, every_6_months,
    | yearly, every_2_years, every_3_years — or a number of days. Names a
    | site adds under 'named' work everywhere a built-in name does.
    |
    */

    'intervals' => [

        'default' => 'yearly',

        'collections' => [
            // 'news' => 'quarterly',
        ],

        'blueprints' => [
            // 'policy' => 'every_2_years',
            // 'news/press_release' => 'monthly',
        ],

        'named' => [
            // 'fortnightly' => 14,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Owners
    |--------------------------------------------------------------------------
    |
    | When an entry has no content_owner, the person who last modified it is
    | treated as the owner and flagged as inferred — a guess the dashboard
    | shows as a guess, never written to the entry. Turn that off to have
    | unowned pages fall straight through to the owner-of-last-resort.
    |
    | 'never_infer' lists people who must not be guessed: the account that ran
    | the import, the administrator who re-saved every page during a migration.
    | Without it, one person "owns" the whole site, and the dashboard is a
    | guess dressed up as a fact. `lifecycle:baseline` warns when one person
    | would be inferred for most of the site.
    |
    | 'fallback' names the owner-of-last-resort per site, by user ID — or one
    | ID for every site — so that nothing is ever ownerless. The settings
    | screen in the control panel (Addons → Lifecycle) can name them too, and
    | wins per site; a person named in either who has since left is not used.
    |
    */

    'owners' => [

        'infer_from_last_author' => true,

        'never_infer' => [
            // 'the-import-account-id',
        ],

        'fallback' => [
            // 'default' => 'a-user-id',   // or just 'a-user-id', for every site
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Thresholds
    |--------------------------------------------------------------------------
    |
    | A page is "due soon" this many days before its review date, and
    | "expired" once it has been overdue this long. Expired is the state in
    | which expiry actions, where a collection has opted into them, apply.
    |
    */

    'due_soon_days' => 30,

    'expired_after_days' => 30,

    /*
    |--------------------------------------------------------------------------
    | Reminders
    |--------------------------------------------------------------------------
    |
    | When an owner hears about a review, as days before the review date;
    | 0 is the day itself. Ahead of the date only the latest stage to have
    | begun is sent, once per review date. Once the date has passed, a
    | reminder goes at once and then every `overdue_every` days — or once
    | only, when that is 0.
    |
    | Reminders are digests: one email per person, whatever it lists.
    |
    */

    'notifications' => [

        'schedule' => [30, 7, 0],

        'overdue_every' => 7,

    ],

    /*
    |--------------------------------------------------------------------------
    | Recompute
    |--------------------------------------------------------------------------
    |
    | `lifecycle:recompute` runs daily and brings every entry's state up to
    | date. It is queued as a batch of jobs, this many entries to a job, on
    | the queue named here (null for the default). With the sync queue driver
    | it simply runs inline.
    |
    */

    'recompute' => [

        'chunk_size' => (int) env('LIFECYCLE_RECOMPUTE_CHUNK', 250),

        'queue' => env('LIFECYCLE_QUEUE'),

    ],

];
