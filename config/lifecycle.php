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

];
