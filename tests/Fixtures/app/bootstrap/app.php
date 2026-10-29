<?php

use Voyager\Core\VenusianVoyager;

$app = VenusianVoyager::setup(base_path: dirname(__DIR__))->create();

// The fixture ships no .env; it reads an empty one of its own.
$app->loadEnvironmentFrom('testing.env');

return $app;
