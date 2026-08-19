<?php

namespace Spawn\Laravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * The far side of a many-to-many, reached over a pivot table.
 */
class RelationTag extends Model
{
    public $timestamps = false;

    protected $table = 'relation_tags';
}
