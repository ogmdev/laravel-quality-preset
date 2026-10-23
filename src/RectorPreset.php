<?php

declare(strict_types=1);

namespace Ogmdev\LaravelQualityPreset;

use InvalidArgumentException;
use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\CodingStyle\Rector\FuncCall\StrictArraySearchRector;
use Rector\Composer\InstalledPackageResolver;
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
     *
     * Rector reads composer.json and vendor/composer/installed.json from the working directory,
     * so it has to run from $projectRoot.
     */
    public static function configure(string $projectRoot, int $parallelProcesses = 3): RectorConfigBuilder
    {
        if (realpath((string) getcwd()) !== realpath($projectRoot)) {
            throw new InvalidArgumentException(sprintf(
                'Run Rector from the project root [%s], not from [%s]: it reads composer.json and vendor/composer/installed.json from the working directory.',
                $projectRoot,
                getcwd(),
            ));
        }

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
                ...self::livewireSets($projectRoot),
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

    /**
     * The Livewire upgrade sets up to the installed major version, as the Laravel ones: the
     * Livewire 4 set would move a Livewire 3 project's Volt components to Livewire\Component.
     *
     * @return list<string>
     */
    private static function livewireSets(string $projectRoot): array
    {
        $version = new InstalledPackageResolver($projectRoot)->resolvePackageVersion('livewire/livewire');

        if ($version === null) {
            return [];
        }

        return array_keys(array_filter([
            LivewireSetList::LIVEWIRE_30 => version_compare($version, '3.0.0.0-dev', '>='),
            LivewireSetList::LIVEWIRE_40 => version_compare($version, '4.0.0.0-dev', '>='),
        ]));
    }
}
