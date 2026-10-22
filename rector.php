<?php

declare(strict_types=1);

use Ogmdev\LaravelQualityPreset\RectorPreset;
use RectorLaravel\Rector\FuncCall\UnlinkFuncCallToFileFacadeDeleteRector;

return RectorPreset::configure(__DIR__)
    ->withCache(cacheDirectory: __DIR__.'/.rector.cache')
    // The package has no Laravel container: its facades are out of reach.
    ->withSkip([UnlinkFuncCallToFileFacadeDeleteRector::class])
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
        __DIR__.'/rector.php',
    ]);
