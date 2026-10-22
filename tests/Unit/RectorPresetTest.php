<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\FuncCall\StrictArraySearchRector;
use Rector\CodingStyle\Rector\FuncCall\StrictInArrayRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodRector;

/**
 * @return array{exitCode: int, stdout: string, stderr: string}
 */
function rectorDryRun(string $project): array
{
    return run(
        [PHP_BINARY, packagePath('vendor/bin/rector'), 'process', '--dry-run', '--output-format=json', '--config='.$project.'/rector.php'],
        $project,
    );
}

/**
 * @param  array{exitCode: int, stdout: string, stderr: string}  $result
 * @return array<string, array{diff: string, applied_rectors: list<string>}>
 */
function rectorChanges(array $result): array
{
    $report = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
    $changes = [];

    foreach ($report['file_diffs'] ?? [] as $fileDiff) {
        $changes[$fileDiff['file']] = $fileDiff;
    }

    return $changes;
}

/**
 * @param  list<string>  $projectSkips
 */
function writeRectorConfig(string $project, array $projectSkips): void
{
    writeFile($project.'/rector.php', sprintf(<<<'PHP'
        <?php

        declare(strict_types=1);

        require_once %s;

        use Ogmdev\LaravelQualityPreset\RectorPreset;

        return RectorPreset::configure(__DIR__, parallelProcesses: 1)
            ->withSkip(%s)
            ->withPaths([__DIR__.'/app', __DIR__.'/bootstrap']);
        PHP, var_export(packagePath('vendor/autoload.php'), true), var_export($projectSkips, true)));
}

beforeEach(function (): void {
    $this->project = temporaryDirectory('rector');
    writeJson($this->project.'/composer.json', ['require' => ['php' => '^8.4']]);
    // withComposerBased() reads the installed packages of the project.
    writeJson($this->project.'/vendor/composer/installed.json', ['packages' => [], 'dev' => true, 'dev-package-names' => []]);
    writeRectorConfig($this->project, [StrictInArrayRector::class]);

    $catalog = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App;

        final class Catalog
        {
            public function position(string $status): int|false
            {
                return array_search($status, ['draft', 'sent']);
            }

            public function isKnown(string $status): bool
            {
                return in_array($status, ['draft', 'sent']);
            }

            public function clock(): \Psr\Clock\ClockInterface
            {
                return new \Acme\Clock\SystemClock();
            }

            private function unused(): void
            {
            }
        }
        PHP;

    writeFile($this->project.'/app/Catalog.php', $catalog);
    writeFile($this->project.'/bootstrap/cache/Catalog.php', $catalog);
});

it('applies the prepared sets and imports the names', function (): void {
    $result = rectorDryRun($this->project);

    $changes = rectorChanges($result);

    expect($result['exitCode'])->toBe(2, output($result))
        ->and($changes)->toHaveKey('app/Catalog.php')
        ->and($changes['app/Catalog.php']['applied_rectors'])->toContain(RemoveUnusedPrivateMethodRector::class)
        ->and($changes['app/Catalog.php']['diff'])->toContain('+use Psr\Clock\ClockInterface;')
        ->toContain('+use Acme\Clock\SystemClock;');
});

it('skips the preset rules and the project rules together', function (): void {
    $result = rectorDryRun($this->project);

    $catalog = rectorChanges($result)['app/Catalog.php'];

    expect($catalog['applied_rectors'])->not->toContain(StrictInArrayRector::class)
        ->not->toContain(StrictArraySearchRector::class)
        ->and($catalog['diff'])->not->toContain("in_array(\$status, ['draft', 'sent'], true)")
        ->not->toContain("array_search(\$status, ['draft', 'sent'], true)");
});

it('keeps the rules that only the project skipped', function (): void {
    writeRectorConfig($this->project, []);

    $result = rectorDryRun($this->project);

    expect(rectorChanges($result)['app/Catalog.php']['applied_rectors'])->toContain(StrictInArrayRector::class);
});

it('leaves the compiled bootstrap cache alone', function (): void {
    $result = rectorDryRun($this->project);

    expect(rectorChanges($result))->toHaveKey('app/Catalog.php')
        ->not->toHaveKey('bootstrap/cache/Catalog.php');
});
