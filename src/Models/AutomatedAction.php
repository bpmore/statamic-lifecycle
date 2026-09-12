<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Models;

use Bpmore\StatamicLifecycle\Expiry\ActionType;
use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of lifecycle_actions: a change this addon made to the live site —
 * what, to which page, when, at whose behest, what the page looked like
 * before, and whether it has since been put back.
 *
 * An action that is not in here did not happen through this addon.
 *
 * @property int $id
 * @property string $entry_id
 * @property ActionType $action
 * @property \DateTimeImmutable $applied_at
 * @property string $triggered_by
 * @property array|null $previous_state
 * @property \DateTimeImmutable|null $reverted_at
 * @property string|null $reverted_by
 */
class AutomatedAction extends Model
{
    protected $table = 'lifecycle_actions';

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
            'action' => ActionType::class,
            'applied_at' => 'immutable_datetime',
            'previous_state' => 'array',
            'reverted_at' => 'immutable_datetime',
        ];
    }

    /** Applied on this calendar day, in the app's timezone. */
    public function scopeAppliedOn(Builder $query, \DateTimeInterface $day): Builder
    {
        $start = \DateTimeImmutable::createFromInterface($day)->setTime(0, 0);

        return $query
            ->where('applied_at', '>=', $start->format('Y-m-d H:i:s'))
            ->where('applied_at', '<', $start->modify('+1 day')->format('Y-m-d H:i:s'));
    }

    /** The changes that count against the daily cap: everything but a restore. */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->where('action', '!=', ActionType::Restore->value);
    }
}
