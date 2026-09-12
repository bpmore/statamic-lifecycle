<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Models;

use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use Illuminate\Database\Eloquent\Model;

/**
 * One day's numbers, as they stood when the recompute ran.
 *
 * @property \DateTimeImmutable $day
 * @property int $total
 * @property int $fresh
 * @property int $due_soon
 * @property int $overdue
 * @property int $expired
 * @property int $exempt
 * @property int $never_reviewed
 * @property int $no_owner
 * @property int $reviews
 * @property \DateTimeImmutable $recorded_at
 */
class Snapshot extends Model
{
    protected $table = 'lifecycle_snapshots';

    protected $primaryKey = 'day';

    protected $keyType = 'string';

    public $incrementing = false;

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
            'day' => 'immutable_date',
            'recorded_at' => 'immutable_datetime',
            'total' => 'integer', 'fresh' => 'integer', 'due_soon' => 'integer', 'overdue' => 'integer',
            'expired' => 'integer', 'exempt' => 'integer', 'never_reviewed' => 'integer', 'no_owner' => 'integer',
            'reviews' => 'integer',
        ];
    }

    /** The pages that are late: what the trend follows. */
    public function late(): int
    {
        return $this->overdue + $this->expired;
    }
}
