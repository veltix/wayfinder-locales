<?php

use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Collection;
use Laravel\Ranger\Components\Route as RangerRoute;
use Veltix\WayfinderLocales\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

function rangerRouteNamed(string $name): RangerRoute
{
    /** @var IlluminateRoute $route */
    $route = app('router')->getRoutes()->getByName($name);

    return new RangerRoute($route, new Collection, null, null);
}
