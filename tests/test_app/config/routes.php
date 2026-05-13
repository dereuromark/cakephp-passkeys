<?php
declare(strict_types=1);

use Cake\Routing\RouteBuilder;

/** @var \Cake\Routing\RouteBuilder $routes */
$routes->setRouteClass(\Cake\Routing\Route\DashedRoute::class);

$routes->scope('/', function (RouteBuilder $builder): void {
    // The Passkeys plugin owns its own routes via CakePasskeysPlugin::routes().
});
