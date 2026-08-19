<?php

namespace Spawn\Laravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The same model without the trait, so that a test can show what the trait is worth.
 */
class PlainRelationOwner extends Model
{
    public $timestamps = false;

    protected $table = 'relation_owners';

    public function items(): HasMany
    {
        return $this->hasMany(RelationItem::class, 'owner_id');
    }
}
