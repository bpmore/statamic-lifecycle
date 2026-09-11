<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Models;

use Bpmore\StatamicLifecycle\Reviews\Outcome;
use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One attestation: a person said, on a date, that a page was reviewed and
 * what they decided. Append-only — rows are created and read, never
 * updated or deleted by the addon. This is the compliance artifact.
 *
 * @property int $id
 * @property string $entry_id
 * @property string $reviewed_by
 * @property \DateTimeImmutable $reviewed_at
 * @property Outcome $outcome
 * @property string|null $note
 * @property bool $content_changed
 * @property string|null $entry_revision_ref
 * @property \DateTimeImmutable $created_at
 */
class Review extends Model
{
    protected $table = 'lifecycle_reviews';

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
            'reviewed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'outcome' => Outcome::class,
            'content_changed' => 'boolean',
        ];
    }

    public function scopeForEntry(Builder $query, string $entryId): Builder
    {
        return $query->where('entry_id', $entryId);
    }

    /** Newest attestation first: the per-entry timeline. */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('reviewed_at')->orderByDesc('id');
    }
}
