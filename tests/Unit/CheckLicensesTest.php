<?php

declare(strict_types=1);

/**
 * Writes the vendor/composer/installed.json that Composer leaves after an install.
 *
 * @param  array<string, array{license: list<string>, dev?: bool}>  $dependencies
 */
function installComposerDependencies(string $directory, array $dependencies): void
{
    $packages = [];
    $devPackageNames = [];

    foreach ($dependencies as $name => $package) {
        $packages[] = ['name' => $name, 'version' => '1.0.0', 'license' => $package['license']];

        if ($package['dev'] ?? false) {
            $devPackageNames[] = $name;
        }
    }

    writeJson($directory.'/vendor/composer/installed.json', ['packages' => $packages, 'dev' => true, 'dev-package-names' => $devPackageNames]);
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $environment
 * @return array{exitCode: int, stdout: string, stderr: string}
 */
function checkLicenses(string $directory, array $arguments = [], array $environment = []): array
{
    return run([PHP_BINARY, packagePath('bin/check-licenses'), ...$arguments], $directory, $environment);
}

/**
 * @param  array<string, string>  $licenses
 */
function writePackageLock(string $path, array $licenses): void
{
    writeJson($path, ['lockfileVersion' => 3, 'packages' => array_map(
        static fn (string $license): array => ['version' => '1.0.0', 'license' => $license],
        $licenses,
    )]);
}

beforeEach(function (): void {
    $this->directory = temporaryDirectory('licenses');
});

it('passes when every dependency has an allowed license', function (): void {
    installComposerDependencies($this->directory, [
        'acme/http' => ['license' => ['MIT']],
        'acme/dual' => ['license' => ['GPL-3.0-only', 'BSD-3-Clause']],
    ]);
    writeJson($this->directory.'/package-lock.json', ['packages' => [
        '' => ['name' => 'app'],
        'node_modules/left-pad' => ['version' => '1.3.0', 'license' => 'ISC'],
        'node_modules/@acme/ui/node_modules/tiny' => ['version' => '2.0.0', 'license' => '(MIT OR GPL-2.0)'],
    ]]);

    $result = checkLicenses($this->directory);

    expect($result['exitCode'])->toBe(0)
        ->and($result['stdout'])->toBe('4 packages checked.'.PHP_EOL);
});

it('lists the production dependencies outside the allowlist and fails', function (): void {
    installComposerDependencies($this->directory, [
        'acme/http' => ['license' => ['MIT']],
        'acme/copyleft' => ['license' => ['GPL-3.0-only']],
        'acme/unlicensed' => ['license' => []],
    ]);

    $result = checkLicenses($this->directory, ['--no-npm']);

    expect($result['exitCode'])->toBe(1)
        ->and($result['stderr'])->toContain('Licenses outside the allowlist:')
        ->toContain('composer acme/copyleft@1.0.0: GPL-3.0-only (prod)')
        ->toContain('composer acme/unlicensed@1.0.0: unknown (prod)')
        ->not->toContain('acme/http');
});

it('allows the dev-only licenses for dev dependencies only', function (): void {
    installComposerDependencies($this->directory, [
        'acme/dev-tool' => ['license' => ['MPL-2.0'], 'dev' => true],
        'acme/runtime' => ['license' => ['MPL-2.0']],
    ]);
    writeJson($this->directory.'/package-lock.json', ['packages' => [
        'node_modules/bundler' => ['version' => '3.0.0', 'license' => 'MPL-2.0', 'dev' => true],
    ]]);

    $result = checkLicenses($this->directory);

    expect($result['exitCode'])->toBe(1)
        ->and($result['stderr'])->toContain('composer acme/runtime@1.0.0: MPL-2.0 (prod)')
        ->not->toContain('acme/dev-tool')
        ->not->toContain('bundler');
});

it('replaces the allowlists from options', function (): void {
    installComposerDependencies($this->directory, [
        'acme/http' => ['license' => ['MIT']],
        'acme/weak-copyleft' => ['license' => ['LGPL-3.0-only']],
        'acme/dev-tool' => ['license' => ['MPL-2.0'], 'dev' => true],
    ]);

    $result = checkLicenses($this->directory, ['--no-npm', '--allow=Apache-2.0, LGPL-3.0-only', '--allow-dev=Unlicense']);

    expect($result['exitCode'])->toBe(1)
        ->and($result['stderr'])->toContain('composer acme/http@1.0.0: MIT (prod)')
        ->toContain('composer acme/dev-tool@1.0.0: MPL-2.0 (dev)')
        ->not->toContain('weak-copyleft');
});

it('skips the vendors given as internal', function (): void {
    installComposerDependencies($this->directory, [
        'acme/billing' => ['license' => ['proprietary']],
        'acme-labs/tool' => ['license' => ['proprietary']],
    ]);
    writeJson($this->directory.'/package-lock.json', ['packages' => [
        'node_modules/@acme/ui' => ['version' => '1.0.0', 'license' => 'UNLICENSED'],
    ]]);

    $result = checkLicenses($this->directory, ['--ignore-vendor=acme/', '--ignore-vendor=@acme/']);

    expect($result['exitCode'])->toBe(1)
        ->and($result['stderr'])->toContain('composer acme-labs/tool@1.0.0: proprietary (prod)')
        ->not->toContain('acme/billing')
        ->not->toContain('@acme/ui');
});

it('accepts an exception and prints its reason', function (): void {
    installComposerDependencies($this->directory, ['acme/copyleft' => ['license' => ['GPL-3.0-only']]]);

    $result = checkLicenses($this->directory, ['--no-npm', "--exception=acme/copyleft: linked at arm's length"]);

    expect($result['exitCode'])->toBe(0)
        ->and($result['stdout'])->toContain("Exception for composer acme/copyleft@1.0.0: GPL-3.0-only (prod), linked at arm's length");
});

it('fails on an exception that no longer matches', function (): void {
    installComposerDependencies($this->directory, ['acme/http' => ['license' => ['MIT']]]);

    $result = checkLicenses($this->directory, ['--no-npm', '--exception=acme/gone:former dependency']);

    expect($result['exitCode'])->toBe(1)
        ->and($result['stderr'])->toContain('Unused exception for acme/gone: remove its --exception option');
});

it('requires the npm lockfile unless told the project has none', function (): void {
    installComposerDependencies($this->directory, ['acme/http' => ['license' => ['MIT']]]);

    $result = checkLicenses($this->directory);

    expect($result['exitCode'])->toBe(1)
        ->and($result['stderr'])->toContain('npm lockfile [package-lock.json] not found');
});

it('reads the npm lockfile from an option', function (): void {
    installComposerDependencies($this->directory, []);
    writeJson($this->directory.'/front/package-lock.json', ['packages' => [
        'node_modules/copyleft' => ['version' => '1.0.0', 'license' => 'GPL-3.0-only'],
    ]]);

    $result = checkLicenses($this->directory, ['--npm-lock=front/package-lock.json']);

    expect($result['exitCode'])->toBe(1)
        ->and($result['stderr'])->toContain('npm copyleft@1.0.0: GPL-3.0-only (prod)');
});

it('rejects an option it does not know', function (string $argument, string $message): void {
    installComposerDependencies($this->directory, []);

    $result = checkLicenses($this->directory, [$argument]);

    expect($result['exitCode'])->toBe(1)
        ->and($result['stderr'])->toContain($message);
})->with([
    'unknown option' => ['--ignore-vendors=acme/', 'Unknown option [--ignore-vendors].'],
    'missing value' => ['--allow', 'Unknown option or missing value: [--allow].'],
    'exception without reason' => ['--exception=acme/http', 'needs a package name and a reason'],
]);

it('fails when the Composer dependencies are not installed', function (): void {
    $result = checkLicenses($this->directory, ['--no-npm']);

    expect($result['exitCode'])->toBe(1)
        ->and($result['stderr'])->toContain('[vendor/composer/installed.json] not found: run composer install before this check.');
});

it('reads the vendor directory from composer.json', function (): void {
    writeJson($this->directory.'/composer.json', ['config' => ['vendor-dir' => 'lib']]);
    writeJson($this->directory.'/lib/composer/installed.json', ['packages' => [], 'dev' => true, 'dev-package-names' => []]);

    $result = checkLicenses($this->directory, ['--no-npm']);

    expect($result['exitCode'])->toBe(0, output($result));
});

it('does not need composer on the PATH', function (): void {
    installComposerDependencies($this->directory, ['acme/http' => ['license' => ['MIT']]]);

    $result = checkLicenses($this->directory, ['--no-npm'], ['PATH' => '']);

    expect($result['exitCode'])->toBe(0, output($result))
        ->and($result['stdout'])->toBe('1 packages checked.'.PHP_EOL);
});

it('evaluates SPDX expressions with AND, OR, WITH and parentheses', function (string $expression, bool $allowed): void {
    installComposerDependencies($this->directory, []);
    writePackageLock($this->directory.'/package-lock.json', ['node_modules/subject' => $expression]);

    $result = checkLicenses($this->directory);

    expect($result['exitCode'])->toBe($allowed ? 0 : 1, output($result));
})->with([
    'OR with one allowed side' => ['MIT OR GPL-3.0-only', true],
    'lowercase operator' => ['GPL-3.0-only or MIT', true],
    'AND of allowed licenses' => ['(MIT AND BSD-3-Clause)', true],
    'AND binds tighter than OR' => ['GPL-3.0-only AND Zlib OR MIT', true],
    'OR group ANDed with a copyleft' => ['(MIT OR Apache-2.0) AND GPL-3.0-only', false],
    'copyleft ANDed with an OR group' => ['GPL-3.0-only AND (MIT OR Apache-2.0)', false],
    'exception keeps the license' => ['Apache-2.0 WITH LLVM-exception', true],
    'exception does not make a copyleft allowed' => ['GPL-2.0-only WITH Classpath-exception-2.0', false],
    'unbalanced expression' => ['(MIT OR Apache-2.0', false],
    'dangling operator' => ['MIT OR', false],
]);

it('evaluates each Composer license as an SPDX expression', function (): void {
    installComposerDependencies($this->directory, [
        'acme/dual' => ['license' => ['(MIT OR GPL-3.0-only)']],
        'acme/bound' => ['license' => ['(MIT OR Apache-2.0) AND GPL-3.0-only']],
    ]);

    $result = checkLicenses($this->directory, ['--no-npm']);

    expect($result['exitCode'])->toBe(1)
        ->and($result['stderr'])->toContain('composer acme/bound@1.0.0: (MIT OR Apache-2.0) AND GPL-3.0-only (prod)')
        ->not->toContain('acme/dual');
});

it('rejects a lockfile without packages', function (): void {
    installComposerDependencies($this->directory, []);
    writeJson($this->directory.'/package-lock.json', ['lockfileVersion' => 1, 'dependencies' => ['copyleft' => ['version' => '1.0.0']]]);

    $result = checkLicenses($this->directory);

    expect($result['exitCode'])->toBe(1)
        ->and($result['stderr'])->toContain('has no "packages" key: lockfileVersion 1 is not supported');
});

it('names workspace packages from the lockfile and skips their links', function (): void {
    installComposerDependencies($this->directory, []);
    writeJson($this->directory.'/package-lock.json', ['lockfileVersion' => 3, 'packages' => [
        '' => ['name' => 'app', 'workspaces' => ['packages/*']],
        'node_modules/@acme/ui' => ['resolved' => 'packages/ui', 'link' => true],
        'packages/ui' => ['name' => '@acme/ui', 'version' => '1.0.0', 'license' => 'UNLICENSED'],
        'packages/tools' => ['version' => '1.0.0', 'license' => 'UNLICENSED'],
        'node_modules/legacy' => ['name' => 'left-pad', 'version' => '1.3.0', 'license' => 'WTFPL'],
    ]]);

    $result = checkLicenses($this->directory, ['--ignore-vendor=@acme/']);

    expect($result['exitCode'])->toBe(1)
        ->and($result['stdout'].$result['stderr'])->toContain('3 packages checked.')
        ->and($result['stderr'])->toContain('npm packages/tools@1.0.0: UNLICENSED (prod)')
        ->toContain('npm left-pad@1.3.0: WTFPL (prod)')
        ->not->toContain('@acme/ui')
        ->not->toContain('npm @1.0.0');
});
