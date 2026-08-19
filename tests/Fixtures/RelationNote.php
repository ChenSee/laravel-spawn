<?php

namespace Spawn\Laravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * The far side of a has-many-through, reached over the items of an owner.
 */
class RelationNote extends Model
{
    public $timestamps = false;

    protected $table = 'relation_notes';
}
