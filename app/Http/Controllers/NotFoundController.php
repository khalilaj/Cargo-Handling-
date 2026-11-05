<?php

namespace App\Http\Controllers;

/** Answers a route we do not use with a plain 404. A controller class (not a closure) so `route:cache` can store it. */
class NotFoundController
{
    public function __invoke(): never
    {
        abort(404);
    }
}
