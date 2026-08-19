<?php

namespace Spawn\Laravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

use function Async\delay;

/**
 * The parent side of the relation the constraints flag decides about. An ordinary model:
 * the package reaches it through Eloquent itself, so there is nothing for it to opt into.
 */
class RelationOwner extends Model
{
    public $timestamps = false;

    protected $table = 'relation_owners';

    public function items(): HasMany
    {
        return $this->hasMany(RelationItem::class, 'owner_id');
    }

    /**
     * The one-of-many form, which calls addConstraints() a second time — on the subquery,
     * outside any constructor.
     */
    public function latestItem(): HasOne
    {
        return $this->hasOne(RelationItem::class, 'owner_id')->latestOfMany();
    }

    /**
     * Keyed on a column whose accessor suspends, so that addConstraints() itself yields.
     * A cast or accessor that reads a cache is the realistic form of this.
     */
    public function slowItems(): HasMany
    {
        return $this->hasMany(RelationItem::class, 'owner_id', 'owner_key');
    }

    /**
     * Reached over a pivot table, which Eloquent joins whether constraints are on or off.
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(RelationTag::class, 'relation_owner_tag', 'owner_id', 'tag_id');
    }

    /**
     * Reached over the owner's items, which Eloquent joins the same way.
     */
    public function notes(): HasManyThrough
    {
        return $this->hasManyThrough(RelationNote::class, RelationItem::class, 'owner_id', 'item_id', 'id', 'id');
    }

    protected function ownerKey(): Attribute
    {
        return Attribute::get(function ($value) {
            delay(1);

            return $value;
        });
    }
}
