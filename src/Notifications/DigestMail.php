<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The email a digest becomes. Plain words, one list per reason. */
class DigestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Digest $digest) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->digest->subject());
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'lifecycle::mail.digest',
            with: [
                'digest' => $this->digest,
                'escalated' => $this->digest->of(ReminderType::Escalation),
                'overdue' => $this->digest->of(ReminderType::Overdue),
                'dueToday' => $this->digest->of(ReminderType::Due),
                'dueSoon' => $this->digest->of(ReminderType::DueSoon),
                'siteName' => config('app.name'),
                'preferencesUrl' => cp_route('preferences.user.edit'),
            ],
        );
    }
}
