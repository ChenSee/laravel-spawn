<?php

namespace Spawn\Laravel\Tests\Fixtures;

/**
 * The same shape holding a collaborator of its own, which belongs to the worker and is
 * not a finding.
 */
class SelfContainedMiddleware
{
    public function __construct(public object $ownCollaborator)
    {
    }
}
