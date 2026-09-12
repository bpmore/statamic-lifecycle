<?php

declare(strict_types=1);

arch('the addon declares strict types')
    ->expect('Bpmore\StatamicLifecycle')
    ->toUseStrictTypes();

arch('nothing ships with a debugging call left in')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();

// The computation is pure: facts in, state out, the clock passed in. It must
// stay that way, or it stops being testable in milliseconds and the rest of
// the addon stops being able to trust it. Statamic is met in Entries\.
//
// Not an arch expectation: Pest resolves a dependency by looking it up among
// the registered PSR-4 prefixes, so a name shallower than any prefix —
// `Illuminate`, where the prefixes are `Illuminate\Support\` and friends —
// matches nothing and the expectation passes without testing anything. It is
// also slow. Reading the tokens does not care how a thing is autoloaded.
it('keeps the state computation free of any framework', function (string $file) {
    $frameworks = ['Statamic', 'Illuminate', 'Laravel', 'Orchestra', 'Carbon'];

    $violations = array_values(array_unique(array_filter(
        referencedNames($file),
        static fn (string $name): bool => in_array(strtok($name, '\\'), $frameworks, true),
    )));

    expect($violations)->toBe([]);
})->with(array_filter(
    phpSources(['src/State', 'src/Expiry']),
    // The rails' rules are pure; what they read and do around them (the log,
    // the mail, the day's count, the navigations, the entries) is not.
    fn (string $path): bool => ! preg_match('#/(TripAlert|TripAlertMail|ActionsToday|NoticesSent|NavigationReferences|ActionLog|ExpiryPlanner|ExpiryPlan|ActionApplier|ExpiryRunner|ExpiryOutcome)\.php$#', $path),
    ARRAY_FILTER_USE_KEY,
));

it('has a state computation to keep pure', function () {
    // Guards the check above: a renamed directory would leave it with no
    // files and nothing to fail on.
    expect(phpSources(['src/State']))->not->toBeEmpty()
        ->and(phpSources(['src/Expiry']))->not->toBeEmpty();
});
