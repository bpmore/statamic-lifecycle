<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Expiry;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Statamic\Facades\User;

/**
 * What happens when the circuit breaker trips: it is written to the log and
 * an admin hears, by email — the two halves of "log it, notify an admin"
 * (spec §6). Taking no action is the breaker's own doing.
 *
 * Who hears: the addresses in `expiry.alert_to`, or every super user with
 * an email when none are named. A tripped breaker that nobody hears about
 * is a site that quietly stops expiring anything.
 */
final class TripAlert
{
    /** @param  list<Trip>  $trips */
    public function raise(array $trips): void
    {
        if ($trips === []) {
            return;
        }

        foreach ($trips as $trip) {
            Log::warning('Lifecycle circuit breaker tripped: '.$trip->reason(), [
                'collection' => $trip->collection,
                'affected' => $trip->affected,
                'size' => $trip->size,
                'limit' => $trip->limit,
            ]);
        }

        $to = $this->recipients();

        if ($to === []) {
            Log::warning('Lifecycle circuit breaker tripped and nobody could be told: no expiry.alert_to addresses and no super user with an email.');

            return;
        }

        Mail::to($to)->send(new TripAlertMail($trips));
    }

    /** @return list<string> */
    public function recipients(): array
    {
        $configured = array_values(array_filter(
            array_map('trim', (array) config('lifecycle.expiry.alert_to', [])),
            fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        ));

        if ($configured !== []) {
            return $configured;
        }

        return User::all()
            ->filter(fn ($user): bool => $user->isSuper() && (string) $user->email() !== '')
            ->map(fn ($user): string => (string) $user->email())
            ->values()
            ->all();
    }
}
