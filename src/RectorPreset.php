<?php

declare(strict_types=1);

namespace Ogmdev\LaravelQualityPreset;

use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\CodingStyle\Rector\FuncCall\StrictArraySearchRector;
use Rector\Config\RectorConfig;
use Rector\Configuration\RectorConfigBuilder;
use RectorLaravel\Rector\FuncCall\NowFuncWithStartOfDayMethodCallToTodayFuncRector;
use RectorLaravel\Rector\StaticCall\CarbonToDateFacadeRector;
use RectorLaravel\Rector\StaticCall\DispatchToHelperFunctionsRector;
use RectorLaravel\Set\LaravelSetList;
use RectorLaravel\Set\Packages\Livewire\LivewireSetList;

final class RectorPreset
{
    /**
     * The project still declares its own paths, and adds its own skips: withSkip() merges them
     * with the ones below.
     */
    public static function configure(string $projectRoot, int $parallelProcesses = 3): RectorConfigBuilder
    {
        return RectorConfig::configure()
            ->withPhpSets()
            // Laravel upgrade rules follow the installed laravel/framework version.
            ->withComposerBased(
                phpunit: true,
                laravel: true,
            )
            ->withPreparedSets(
                deadCode: true,
                codeQuality: true,
                codingStyle: true,
                typeDeclarations: true,
                phpunitCodeQuality: true,
                symfonyCodeQuality: true,
            )
            ->withSets([
                LaravelSetList::LARAVEL_CODE_QUALITY,
                LaravelSetList::LARAVEL_COLLECTION,
                LaravelSetList::LARAVEL_CONTAINER_STRING_TO_FULLY_QUALIFIED_NAME,
                LaravelSetList::LARAVEL_ELOQUENT_MAGIC_METHOD_TO_QUERY_BUILDER,
                LaravelSetList::LARAVEL_LEGACY_FACTORIES_TO_CLASSES,
                LivewireSetList::LIVEWIRE_30,
                LivewireSetList::LIVEWIRE_40,
            ])
            ->withSkip([
                CarbonToDateFacadeRector::class,
                DispatchToHelperFunctionsRector::class,
                NowFuncWithStartOfDayMethodCallToTodayFuncRector::class,
                StrictArraySearchRector::class,
                $projectRoot.'/bootstrap/cache',
            ])
            ->withCache(
                cacheDirectory: $projectRoot.'/storage/rectorCache',
                cacheClass: FileCacheStorage::class,
            )
            ->withImportNames(
                importDocBlockNames: false,
                importShortClasses: false,
                removeUnusedImports: true,
            )
            ->withParallel(maxNumberOfProcess: $parallelProcesses);
    }
}
