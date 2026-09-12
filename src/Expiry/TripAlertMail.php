<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Expiry;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The email an admin gets when the circuit breaker refuses a run. */
class TripAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param  list<Trip>  $trips */
    public function __construct(public readonly array $trips) {}

    public function envelope(): Envelope
    {
        $collections = implode(', ', array_map(fn (Trip $trip): string => $trip->collection, $this->trips));

        return new Envelope(subject: __('Lifecycle refused to change :collections — nothing was touched', ['collections' => $collections]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'lifecycle::mail.trip', with: [
            'trips' => $this->trips,
            'siteName' => config('app.name'),
        ]);
    }
}
