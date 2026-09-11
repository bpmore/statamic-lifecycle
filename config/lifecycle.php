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
    | 'fallback' names the owner-of-last-resort per site, by user ID, so that
    | nothing is ever ownerless.
    |
    */

    'owners' => [

        'infer_from_last_author' => true,

        'never_infer' => [
            // 'the-import-account-id',
        ],

        'fallback' => [
            // 'default' => 'a-user-id',
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

];
