<?php

declare(strict_types=1);

/**
 * @param  list<string>  $ignoreErrors
 * @return array{exitCode: int, stdout: string, stderr: string}
 */
function phpstanAnalyse(string $project, array $ignoreErrors = [], ?string $workingDirectory = null): array
{
    // Installed where Composer puts it, as tmpDir is relative to the config file.
    writeFile($project.'/vendor/ogmdev/laravel-quality-preset/config/phpstan.neon', (string) file_get_contents(packagePath('config/phpstan.neon')));
    $ignored = implode('', array_map(static fn (string $pattern): string => sprintf("        - '#%s#'\n", $pattern), $ignoreErrors));

    writeFile($project.'/phpstan.neon', sprintf(
        "includes:\n    - vendor/ogmdev/laravel-quality-preset/config/phpstan.neon\n\nparameters:\n    paths:\n        - app\n%s",
        $ignored === '' ? '' : "    ignoreErrors:\n".$ignored,
    ));

    return run(
        [PHP_BINARY, packagePath('vendor/bin/phpstan'), 'analyse', '--configuration='.$project.'/phpstan.neon', '--no-progress', '--error-format=raw', '--memory-limit=512M'],
        $workingDirectory ?? $project,
    );
}

beforeEach(function (): void {
    $this->project = temporaryDirectory('phpstan');
    writeFile($this->project.'/app/Invoice.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App;

        final class Invoice
        {
            public function cents(int $amount): int
            {
                return $amount * 100;
            }

            public function total(int|string $amount): int
            {
                return $this->cents($amount);
            }

            public function due(?\DateTimeImmutable $date): string
            {
                return $date->format('Y-m-d');
            }
        }
        PHP);
});

it('analyses at level 7', function (): void {
    $result = phpstanAnalyse($this->project);

    expect($result['exitCode'])->toBe(1)
        ->and($result['stdout'])->toContain('expects int, int|string given')
        ->not->toContain('format() on DateTimeImmutable|null');
});

it('reports an ignored error that no longer matches', function (): void {
    $result = phpstanAnalyse($this->project, ['expects int, int\|string given', 'Error fixed long ago']);

    expect($result['exitCode'])->toBe(1)
        ->and(output($result))->toContain('Ignored error pattern #Error fixed long ago# was not matched in reported errors.')
        ->not->toContain('expects int, int|string given');
});

it('keeps its cache in the project storage, whatever directory it runs from', function (): void {
    mkdir($this->project.'/app/Http', 0777, true);

    $result = phpstanAnalyse($this->project, workingDirectory: $this->project.'/app/Http');

    expect($result['stdout'])->toContain('expects int, int|string given')
        ->and($this->project.'/storage/larastanCache')->toBeDirectory()
        ->and($this->project.'/app/Http/storage')->not->toBeDirectory();
});
