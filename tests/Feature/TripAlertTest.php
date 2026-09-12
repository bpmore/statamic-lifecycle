<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Expiry\ActionType;
use Bpmore\StatamicLifecycle\Expiry\Candidate;
use Bpmore\StatamicLifecycle\Expiry\CircuitBreaker;
use Bpmore\StatamicLifecycle\Expiry\Trip;
use Bpmore\StatamicLifecycle\Expiry\TripAlert;
use Bpmore\StatamicLifecycle\Expiry\TripAlertMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Statamic\Facades\User;

beforeEach(function () {
    User::make()->id('root')->email('root@example.edu')->makeSuper()->save();
    User::make()->id('root2')->email('root2@example.edu')->makeSuper()->save();
    User::make()->id('editor')->email('editor@example.edu')->save();
    User::make()->id('quiet-super')->makeSuper()->save();   // super, no email
});

it('is built from config', function () {
    config(['lifecycle.expiry.circuit_breaker' => ['max_share' => '25%', 'min_affected' => 3]]);

    $breaker = app(CircuitBreaker::class);

    expect($breaker->maxShare)->toBe(0.25)->and($breaker->minAffected)->toBe(3);
});

it('writes the refusal to the log and emails every super user with an address', function () {
    Mail::fake();
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'Refused to act on 120 of the 1,000 entries in [news] (12%)'));

    app(TripAlert::class)->raise([new Trip('news', 120, 1000, 0.10)]);

    Mail::assertSentCount(1);
    Mail::assertSent(TripAlertMail::class, fn (TripAlertMail $mail): bool => $mail->hasTo('root@example.edu')
        && $mail->hasTo('root2@example.edu')
        && ! $mail->hasTo('editor@example.edu'));
});

it('emails the addresses the site named instead, when it named any', function () {
    Mail::fake();
    Log::shouldReceive('warning');
    config(['lifecycle.expiry.alert_to' => [' web@example.edu ', 'not-an-email']]);

    app(TripAlert::class)->raise([new Trip('news', 120, 1000, 0.10)]);

    Mail::assertSent(TripAlertMail::class, fn (TripAlertMail $mail): bool => $mail->hasTo('web@example.edu') && ! $mail->hasTo('root@example.edu'));
});

it('says in the email what was refused, and what to do', function () {
    $html = (new TripAlertMail([new Trip('news', 120, 1000, 0.10), new Trip('policies', 3, 20, 0.10)]))->render();

    expect($html)->toContain('Lifecycle refused to act')
        ->and($html)->toContain('news')
        ->and($html)->toContain('120 of 1,000 entries (12%)')
        ->and($html)->toContain('policies')
        ->and($html)->toContain('3 of 20 entries (15%)')
        ->and($html)->toContain('expiry.circuit_breaker.max_share')
        ->and((new TripAlertMail([new Trip('news', 120, 1000, 0.10)]))->envelope()->subject)->toBe('Lifecycle refused to change news — nothing was touched');
});

it('logs, loudly, when there is nobody to tell', function () {
    Mail::fake();
    User::all()->each->delete();
    Log::shouldReceive('warning')->twice();   // the trip, then "nobody could be told"

    app(TripAlert::class)->raise([new Trip('news', 120, 1000, 0.10)]);

    Mail::assertNothingSent();
});

it('does nothing at all when nothing tripped', function () {
    Mail::fake();
    Log::shouldReceive('warning')->never();

    app(TripAlert::class)->raise([]);
    app(TripAlert::class)->raise((new CircuitBreaker)->check([new Candidate('e', 'default', 'pages', ActionType::Banner)], ['pages' => 100])->trips);

    Mail::assertNothingSent();
});
