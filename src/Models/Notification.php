<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Models;

use Bpmore\StatamicLifecycle\Expiry\ActionType;
use Bpmore\StatamicLifecycle\Notifications\ReminderType;
use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of lifecycle_notifications: a reminder that went out — about
 * this entry, at this stage, for this review date, to this person, then.
 *
 * What the planner reads to avoid saying the same thing twice, and what
 * the frequency opt-out is measured against.
 *
 * @property int $id
 * @property string $entry_id
 * @property ReminderType $type
 * @property ActionType|null $action
 * @property int|null $days_before
 * @property \DateTimeImmutable|null $due_at
 * @property string $recipient
 * @property \DateTimeImmutable $sent_at
 */
class Notification extends Model
{
    protected $table = 'lifecycle_notifications';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * Read at call time, not set as a property: the connection is configurable
     * and a property would freeze whatever it was when the class was loaded.
     */
    public function getConnectionName(): string
    {
        return LifecycleDatabase::connectionName();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ReminderType::class,
            'action' => ActionType::class,
            'days_before' => 'integer',
            'due_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }
}
