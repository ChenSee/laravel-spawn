<?php

namespace Spawn\Laravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * The child side: a row of it belongs to one owner, and a query that forgets the
 * foreign key returns every owner's rows instead of one owner's.
 */
class RelationItem extends Model
{
    public $timestamps = false;

    protected $table = 'relation_items';
}
