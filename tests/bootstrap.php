<?php

declare(strict_types=1);

$loader = require __DIR__.'/../vendor/autoload.php';

$loader->addPsr4('LaravelNecromancer\\', __DIR__.'/../src');
$loader->addPsr4('LaravelNecromancer\\Tests\\', __DIR__);

return $loader;
