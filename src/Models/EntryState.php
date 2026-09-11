<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Models;

use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of lifecycle_states: where an entry is in its review cycle.
 *
 * Computed, and rewritten by every recompute. Read through Storage\StateStore
 * rather than directly, so the mapping to and from State\LifecycleState lives
 * in one place.
 *
 * @property string $entry_id
 * @property string $site
 * @property string $collection
 * @property string|null $owner_id
 * @property string|null $owner_type
 * @property string|null $owner_source
 * @property int|null $interval_days
 * @property string|null $interval_source
 * @property \DateTimeImmutable|null $last_reviewed_at
 * @property string|null $last_reviewed_by
 * @property \DateTimeImmutable|null $next_review_at
 * @property string|null $next_review_source
 * @property string $status
 * @property bool $baselined
 * @property \DateTimeImmutable|null $baseline_review_at
 * @property \DateTimeImmutable $computed_at
 */
class EntryState extends Model
{
    protected $table = 'lifecycle_states';

    protected $primaryKey = 'entry_id';

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
            'interval_days' => 'integer',
            'baselined' => 'boolean',
            'last_reviewed_at' => 'immutable_datetime',
            'next_review_at' => 'immutable_datetime',
            'baseline_review_at' => 'immutable_datetime',
            'computed_at' => 'immutable_datetime',
        ];
    }
}
