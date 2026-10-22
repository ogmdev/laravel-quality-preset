<?php

declare(strict_types=1);

/**
 * Writes a fake composer whose `licenses --format=json` prints the given dependencies, without
 * the dev ones when called with --no-dev.
 *
 * @param  array<string, array{license: list<string>, dev?: bool}>  $dependencies
 */
function fakeComposer(string $directory, array $dependencies): string
{
    $all = [];
    $production = [];

    foreach ($dependencies as $name => $package) {
        $entry = ['version' => '1.0.0', 'license' => $package['license']];
        $all[$name] = $entry;

        if (! ($package['dev'] ?? false)) {
            $production[$name] = $entry;
        }
    }

    $path = $directory.'/fake-composer';
    writeFile($path, sprintf(
        "#!/usr/bin/env php\n<?php\necho json_encode(['dependencies' => in_array('--no-dev', \$argv, true) ? %s : %s]);\n",
        var_export($production, true),
        var_export($all, true),
    ));
    chmod($path, 0755);

    return $path;
}

/**
 * @param  list<string>  $arguments
 * @return array{exitCode: int, stdout: string, stderr: string}
 */
function checkLicenses(string $directory, string $composer, array $arguments = []): array
{
    return run([PHP_BINARY, packagePath('bin/check-licenses'), ...$arguments], $directory, ['COMPOSER_BINARY' => $composer]);
}

beforeEach(function (): void {
    $this->directory = temporaryDirectory('licenses');
});

it('passes when every dependency has an allowed license', function (): void {
    $composer = fakeComposer($this->directory, [
        'acme/http' => ['license' => ['MIT']],
        'acme/dual' => ['license' => ['GPL-3.0-only', 'BSD-3-Clause']],
    ]);
    writeJson($this->directory.'/package-lock.json', ['packages' => [
        '' => ['name' => 'app'],
        'node_modules/left-pad' => ['version' => '1.3.0', 'license' => 'ISC'],
        'node_modules/@acme/ui/node_modules/tiny' => ['version' => '2.0.0', 'license' => '(MIT OR GPL-2.0)'],
    ]]);

    $result = checkLicenses($this->directory, $composer);

    expect($result['exitCode'])->toBe(0)
        ->and($result['stdout'])->toBe('4 packages checked.'.PHP_EOL);
});

it('lists the production dependencies outside the allowlist and fails', function (): void {
    $composer = fakeComposer($this->directory, [
        'acme/http' => ['license' => ['MIT']],
        'acme/copyleft' => ['license' => ['GPL-3.0-only']],
        'acme/unlicensed' => ['license' => []],
    ]);

    $result = checkLicenses($this->directory, $composer, ['--no-npm']);

    expect($result['exitCode'])->toBe(1)
        ->and($result['stderr'])->toContain('Licenses outside the allowlist:')
        ->toContain('composer acme/copyleft@1.0.0: GPL-3.0-only (prod)')
        ->toContain('composer acme/unlicensed@1.0.0: unknown (prod)')
        ->not->toContain('acme/http');
});

it('allows the dev-only licenses for dev dependencies only', function (): void {
    $composer = fakeComposer($this->directory, [
        'acme/dev-tool' => ['license' => ['MPL-2.0'], 'dev' => true],
        'acme/runtime' => ['license' => ['MPL-2.0']],
    ]);
    writeJson($this->directory.'/package-lock.json', ['packages' => [
        'node_modules/bundler' => ['version' => '3.0.0', 'license' => 'MPL-2.0', 'dev' => true],
    ]]);

    $result = checkLicenses($this->directory, $composer);

    expect($result['exitCode'])->toBe(1)
        ->and($result['stderr'])->toContain('composer acme/runtime@1.0.0: MPL-2.0 (prod)')
        ->not->toContain('acme/dev-tool')
        ->not->toContain('bundler');
});

it('replaces the allowlists from options', function (): void {
    $composer = fakeComposer($this->directory, [
        'acme/http' => ['license' => ['MIT']],
        'acme/weak-copyleft' => ['license' => ['LGPL-3.0-only']],
        'acme/dev-tool' => ['license' => ['MPL-2.0'], 'dev' => true],
    ]);

    $result = checkLicenses($this->directory, $composer, ['--no-npm', '--allow=Apache-2.0, LGPL-3.0-only', '--allow-dev=Unlicense']);

    expect($result['exitCode'])->toBe(1)
        ->and($result['stderr'])->toContain('composer acme/http@1.0.0: MIT (prod)')
        ->toContain('composer acme/dev-tool@1.0.0: MPL-2.0 (dev)')
        ->not->toContain('weak-copyleft');
});

it('skips the vendors given as internal', function (): void {
    $composer = fakeComposer($this->directory, [
        'acme/billing' => ['license' => ['proprietary']],
        'acme-labs/tool' => ['license' => ['proprietary']],
    ]);
    writeJson($this->directory.'/package-lock.json', ['packages' => [
        'node_modules/@acme/ui' => ['version' => '1.0.0', 'license' => 'UNLICENSED'],
    ]]);

    $result = checkLicenses($this->directory, $composer, ['--ignore-vendor=acme/', '--ignore-vendor=@acme/']);

    expect($result['exitCode'])->toBe(1)
        ->and($result['stderr'])->toContain('composer acme-labs/tool@1.0.0: proprietary (prod)')
        ->not->toContain('acme/billing')
        ->not->toContain('@acme/ui');
});

it('accepts an exception and prints its reason', function (): void {
    $composer = fakeComposer($this->directory, ['acme/copyleft' => ['license' => ['GPL-3.0-only']]]);

    $result = checkLicenses($this->directory, $composer, ['--no-npm', "--exception=acme/copyleft: linked at arm's length"]);

    expect($result['exitCode'])->toBe(0)
        ->and($result['stdout'])->toContain("Exception for composer acme/copyleft@1.0.0: GPL-3.0-only (prod), linked at arm's length");
});

it('fails on an exception that no longer matches', function (): void {
    $composer = fakeComposer($this->directory, ['acme/http' => ['license' => ['MIT']]]);

    $result = checkLicenses($this->directory, $composer, ['--no-npm', '--exception=acme/gone:former dependency']);

    expect($result['exitCode'])->toBe(1)
        ->and($result['stderr'])->toContain('Unused exception for acme/gone: remove its --exception option');
});

it('requires the npm lockfile unless told the project has none', function (): void {
    $composer = fakeComposer($this->directory, ['acme/http' => ['license' => ['MIT']]]);

    $result = checkLicenses($this->directory, $composer);

    expect($result['exitCode'])->toBe(1)
        ->and($result['stderr'])->toContain('npm lockfile [package-lock.json] not found');
});

it('reads the npm lockfile from an option', function (): void {
    $composer = fakeComposer($this->directory, []);
    writeJson($this->directory.'/front/package-lock.json', ['packages' => [
        'node_modules/copyleft' => ['version' => '1.0.0', 'license' => 'GPL-3.0-only'],
    ]]);

    $result = checkLicenses($this->directory, $composer, ['--npm-lock=front/package-lock.json']);

    expect($result['exitCode'])->toBe(1)
        ->and($result['stderr'])->toContain('npm copyleft@1.0.0: GPL-3.0-only (prod)');
});

it('rejects an option it does not know', function (string $argument, string $message): void {
    $composer = fakeComposer($this->directory, []);

    $result = checkLicenses($this->directory, $composer, [$argument]);

    expect($result['exitCode'])->toBe(1)
        ->and($result['stderr'])->toContain($message);
})->with([
    'unknown option' => ['--ignore-vendors=acme/', 'Unknown option [--ignore-vendors].'],
    'missing value' => ['--allow', 'Unknown option or missing value: [--allow].'],
    'exception without reason' => ['--exception=acme/http', 'needs a package name and a reason'],
]);

it('fails when composer fails', function (): void {
    $composer = $this->directory.'/failing-composer';
    writeFile($composer, "#!/bin/sh\nexit 3\n");
    chmod($composer, 0755);

    $result = checkLicenses($this->directory, $composer, ['--no-npm']);

    expect($result['exitCode'])->toBe(1)
        ->and($result['stderr'])->toContain('exited with code 3.');
});
