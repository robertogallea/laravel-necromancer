<?php

declare(strict_types=1);

$loader = require __DIR__.'/../vendor/autoload.php';

$loader->addPsr4('LaravelNecromancer\\', __DIR__.'/../src');
$loader->addPsr4('LaravelNecromancer\\Tests\\', __DIR__);

require_once __DIR__.'/Stubs/Livewire/Component.php';
require_once __DIR__.'/Stubs/Livewire/Attributes/On.php';

return $loader;
