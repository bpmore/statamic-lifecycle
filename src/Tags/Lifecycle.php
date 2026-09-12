<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Tags;

use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\Panel\SidebarPanel;
use Bpmore\StatamicLifecycle\State\Status;
use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use Carbon\Carbon;
use DateTimeImmutable;
use Statamic\Facades\Blink;
use Statamic\Facades\User;
use Statamic\Fields\Value;
use Statamic\Tags\Tags;
use Throwable;

/**
 * The front end's view of the review record (spec §8):
 *
 *   {{ lifecycle:last_reviewed format="F Y" }}     March 2026, or nothing
 *   {{ lifecycle:next_review format="F Y" }}
 *   {{ if {lifecycle:is_stale} }} … {{ /if }}     overdue or expired
 *   {{ lifecycle:status }}                         fresh, due_soon, overdue, expired, exempt
 *
 * and as a pair, everything at once:
 *
 *   {{ lifecycle }}
 *     {{ if stale }}<p class="notice">This page may be out of date.</p>{{ /if }}
 *     Last reviewed {{ last_reviewed format="F Y" }} by {{ last_reviewed_by }}.
 *   {{ /lifecycle }}
 *
 * A visible "Last reviewed: March 2026" is a small line with outsized value:
 * good for readers, good for search, and it makes the product's work visible
 * to people who never open the control panel.
 *
 * The tags read the current page's state row — or the entry named in
 * `entry="…"` — and nothing else: no computation, one query per page, and
 * never an error on the front end. Not installed, not tracked, a draft:
 * every value is empty and `stale` is false. Lifecycle does not decide what
 * a template shows; it only says what it knows.
 */
class Lifecycle extends Tags
{
    protected static $handle = 'lifecycle';

    /**
     * {{ lifecycle }} … {{ /lifecycle }}
     *
     * @return array<string, mixed>
     */
    public function index(): array
    {
        $row = $this->row();
        $status = $row === null ? null : Status::from($row->status);

        return [
            'tracked' => $row !== null,
            'stale' => $status !== null && self::isLate($status),
            'status' => $status?->value,
            'status_label' => $status === null ? null : SidebarPanel::statusLabel($status),
            'last_reviewed' => self::carbon($row?->last_reviewed_at),
            'last_reviewed_by' => $row?->last_reviewed_by === null ? null : self::name($row->last_reviewed_by),
            'next_review' => self::carbon($row?->next_review_at),
            'owner' => $row?->owner_id === null ? null : self::name($row->owner_id),
        ];
    }

    /** {{ lifecycle:last_reviewed format="F Y" }} */
    public function lastReviewed(): ?string
    {
        return $this->date($this->row()?->last_reviewed_at);
    }

    /** {{ lifecycle:next_review format="F Y" }} */
    public function nextReview(): ?string
    {
        return $this->date($this->row()?->next_review_at);
    }

    /** {{ if {lifecycle:is_stale} }}: overdue, or expired. */
    public function isStale(): bool
    {
        $row = $this->row();

        return $row !== null && self::isLate(Status::from($row->status));
    }

    /** {{ lifecycle:status }} */
    public function status(): ?string
    {
        return $this->row()?->status;
    }

    /**
     * The page's state row, read once per request however many tags ask.
     * The entry is the one on the page unless the tag names another.
     */
    private function row(): ?EntryState
    {
        $id = $this->params->get('entry') ?? $this->context->get('id');

        if ($id instanceof Value) {
            $id = $id->value();
        }

        if ($id === null || $id === '') {
            return null;
        }

        $id = (string) $id;

        return Blink::once("lifecycle::state::{$id}", function () use ($id): ?EntryState {
            // The front end must never break because the addon's tables are
            // not there, or its database is unreachable.
            try {
                if (! app(LifecycleDatabase::class)->isInstalled()) {
                    return null;
                }

                return EntryState::query()->find($id);
            } catch (Throwable $e) {
                report($e);

                return null;
            }
        });
    }

    private function date(?DateTimeImmutable $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return $date->format((string) $this->params->get('format', config('statamic.system.date_format', 'F jS, Y')));
    }

    private static function isLate(Status $status): bool
    {
        return $status === Status::Overdue || $status === Status::Expired;
    }

    /** A Carbon, so the template can `{{ last_reviewed format="F Y" }}` itself. */
    private static function carbon(?DateTimeImmutable $date): ?Carbon
    {
        return $date === null ? null : Carbon::instance($date);
    }

    private static function name(string $userId): string
    {
        $user = User::find($userId);

        return $user === null ? $userId : ($user->name() ?: (string) $user->email());
    }
}
