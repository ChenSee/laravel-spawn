<?php

use Spawn\Laravel\Database\Eloquent\RelationConstraints;

// Composer includes this while it is still loading files, which is the last moment early
// enough: the patch redirects the class map, and a redirect placed after something has already
// loaded one of the six Eloquent classes does nothing. Files of packages this one depends on —
// Laravel's own among them — run before it, so an entry there that touches Eloquent wins.
RelationConstraints::install();
