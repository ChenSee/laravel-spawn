<?php

use Spawn\Laravel\Database\Eloquent\EloquentOverrides;

// Composer includes this while it is still loading files, which is the last moment early
// enough: the copies are put in front of Laravel's classes through the class map, and a
// class already loaded cannot be replaced. Files of packages this one depends on — Laravel's
// own among them — run before it, so an entry there that touches Eloquent wins.
EloquentOverrides::install();
