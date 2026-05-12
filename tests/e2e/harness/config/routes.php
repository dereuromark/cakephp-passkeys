<?php
declare(strict_types=1);

use Cake\Routing\RouteBuilder;

/** @var \Cake\Routing\RouteBuilder $routes */
$routes->setRouteClass(\Cake\Routing\Route\DashedRoute::class);

$routes->scope('/', function (RouteBuilder $builder): void {
    // No routes needed at the harness layer — the plugin owns /passkeys/*
    // and the two harness pages (/ and /login) are short-circuited by
    // HarnessRenderMiddleware before routing even runs.
});
