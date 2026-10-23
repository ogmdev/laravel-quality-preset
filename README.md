# laravel-quality-preset

Shared quality tooling for Laravel applications, so that several projects stop maintaining diverging copies of the same configs:

- a PHPStan base config: level 7, no baseline;
- a Rector config factory: PHP, Laravel and Livewire sets, common skips, cache, name imports, parallel runs;
- a Pint preset;
- three CI gates: dependency licenses, critical vulnerabilities in an osv-scanner report, and Vite references missing from the build manifest.

## Install

The package is installed from GitHub:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/ogmdev/laravel-quality-preset" }
    ],
    "require-dev": {
        "ogmdev/laravel-quality-preset": "^0.1"
    }
}
```

It brings PHPStan, Rector, rector-laravel and Pint. Add [Larastan](https://github.com/larastan/larastan) and `phpstan/extension-installer` to get the Laravel-aware PHPStan rules loaded without editing the config.

## PHPStan

```neon
includes:
    - vendor/ogmdev/laravel-quality-preset/config/phpstan.neon

parameters:
    paths:
        - app
        - routes
        - tests
```

The base config sets:

- `level: 7`;
- `reportUnmatchedIgnoredErrors: true`, so an ignored error or a baseline entry that no longer matches fails the analysis;
- `tmpDir` to the project's `storage/larastanCache`, whatever directory PHPStan runs from. The path is relative to the installed config, so it expects the default `vendor` directory.

The baseline policy is to keep none: a new error is fixed, not baselined.

## Rector

`RectorPreset::configure()` returns a `RectorConfigBuilder`. The project adds its paths and its own skips:

```php
<?php

declare(strict_types=1);

use Ogmdev\LaravelQualityPreset\RectorPreset;
use Rector\CodingStyle\Rector\FuncCall\StrictInArrayRector;

return RectorPreset::configure(__DIR__)
    ->withSkip([
        StrictInArrayRector::class => [__DIR__.'/app/Legacy/Importer.php'],
    ])
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ]);
```

The preset:

- applies the PHP sets of the `php` constraint in `composer.json`;
- applies the Laravel upgrade rules of the installed `laravel/framework` version and the PHPUnit rules of the installed PHPUnit version (`withComposerBased()`);
- applies the prepared sets `deadCode`, `codeQuality`, `codingStyle`, `typeDeclarations`, `phpunitCodeQuality` and `symfonyCodeQuality`;
- applies the Laravel sets for code quality, collections, container strings, Eloquent magic methods and legacy factories;
- applies the Livewire upgrade sets up to the installed `livewire/livewire` major version: none without Livewire, the Livewire 3 set on Livewire 3, both sets on Livewire 4;
- skips `CarbonToDateFacadeRector`, `DispatchToHelperFunctionsRector`, `NowFuncWithStartOfDayMethodCallToTodayFuncRector`, `StrictArraySearchRector` and `bootstrap/cache`;
- caches in `storage/rectorCache`;
- imports names and removes unused imports, but leaves docblocks and global short classes alone;
- runs 3 processes in parallel. Pass `parallelProcesses:` to change that, or chain `->withoutParallel()`.

`withSkip()` adds to the preset's skips; it does not replace them.

Rector reads `composer.json` and `vendor/composer/installed.json` from the working directory, so the preset refuses to run from anywhere but the project root it was given.

## Pint

```bash
vendor/bin/pint --config vendor/ogmdev/laravel-quality-preset/config/pint.json
```

The preset is Pint's `laravel` one. A project that needs more, such as `notPath`, keeps its own `pint.json`.

## CI gates

Each one runs from the project root, prints what it checked, and exits non-zero when the gate fails.

### check-licenses

```bash
vendor/bin/check-licenses --ignore-vendor=acme/ --ignore-vendor=@acme/
```

It reads the installed Composer packages from `vendor/composer/installed.json`, so it runs after `composer install`, and the npm packages from `package-lock.json` (lockfileVersion 2 or later; workspace members included). It fails on any dependency whose license is outside the allowlist.

Licenses are read as SPDX expressions: `MIT OR GPL-2.0` passes when one side is allowed, `MIT AND Zlib` only when both are, and `Apache-2.0 WITH LLVM-exception` counts as `Apache-2.0`. An expression it cannot parse fails.

| Option | Effect |
|---|---|
| `--allow=<SPDX,...>` | Licenses allowed everywhere. Replaces the default: `MIT`, `BSD-2-Clause`, `BSD-3-Clause`, `Apache-2.0`, `ISC`, `0BSD`, `BlueOak-1.0.0`, `CC0-1.0`, `MIT-0`. |
| `--allow-dev=<SPDX,...>` | Licenses allowed for dev dependencies only. Replaces the default: `MPL-2.0`. |
| `--ignore-vendor=<prefix>` | Skips the packages whose name starts with the prefix, such as your own private packages. Repeatable. |
| `--exception=<name>:<reason>` | Accepts one package outside the allowlist, and prints the reason. An exception that matches nothing fails the gate. Repeatable. |
| `--npm-lock=<path>` | npm lockfile to read. Default: `package-lock.json`. |
| `--no-npm` | Checks the Composer dependencies only. |

### assert-no-critical-vulnerability

```bash
osv-scanner scan source --recursive --format=json --output-file=osv-results.json . || true
vendor/bin/assert-no-critical-vulnerability osv-results.json
```

It lists every vulnerability group of an [osv-scanner](https://github.com/google/osv-scanner) JSON report by CVSS base score, and fails when one reaches the threshold: 9.0, the CVSS "critical" rating. A group without a CVSS score, such as a malicious package advisory, fails too, since the gate cannot rule it out. It also fails when the report is missing or its format changed, rather than stop blocking unnoticed.

| Option | Effect |
|---|---|
| `--threshold=<score>` | Lowest CVSS base score that fails. Default: `9.0`. |
| `--allow-unscored` | Lists the groups without a CVSS score instead of failing on them. |

### assert-vite-references-are-built

```bash
npm run build
vendor/bin/assert-vite-references-are-built
```

A Node.js script. It scans the `.php` files, Blade views included, for `@vite(...)`, `Vite::asset(...)` and `Vite::content(...)`, and fails when one names a file missing from the build manifest. Laravel would otherwise throw "Unable to locate file in Vite manifest" and answer that page with a 500. A call whose argument is not a hard-coded path fails too, since the gate cannot check it. A literal second argument names another build directory, and its references are checked against that build's manifest, next to the default one.

Calls that never run are left out: Blade comments, `@verbatim` blocks, escaped `@@vite` directives and PHP comments.

| Option | Effect |
|---|---|
| `--manifest=<path>` | Vite manifest. Default: `public/build/manifest.json`. |
| `--scan=<dir,...>` | Directories to scan. Default: `app,resources`. |

## Development

```bash
composer install
composer test
composer phpstan
composer pint
composer rector
```

The Vite tests need Node.js, and are skipped without it. `NODE_BINARY` points them at another Node binary.

## License

Apache License 2.0, see [LICENSE](LICENSE).
