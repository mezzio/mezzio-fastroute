<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;

return Architecture::define()
    ->withPresets(Preset::PSR4(), Preset::CODEQUALITY())
    ->layer('Exception', 'src/Exception')
    ->layer('Router', [
        'src/FastRouteRouter.php',
        'src/FastRouteRouterFactory.php',
    ])
    ->layer('ConfigProvider', 'src/FastRouteRouter')
    ->ruleset([
        'Exception'      => [],
        'Router'         => ['Exception'],
        'ConfigProvider' => ['+Router'],
    ]);
