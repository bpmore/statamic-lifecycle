<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Models;

use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use Illuminate\Database\Eloquent\Model;

/**
 * The moment this addon saw an entry created.
 *
 * @property string $entry_id
 * @property string $site
 * @property string $collection
 * @property \DateTimeImmutable $created_at
 */
class Creation extends Model
{
    protected $table = 'lifecycle_creations';

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
            'created_at' => 'immutable_datetime',
        ];
    }
}
