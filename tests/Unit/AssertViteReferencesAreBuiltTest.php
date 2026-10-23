<?php

declare(strict_types=1);

function nodeBinary(): ?string
{
    $configured = getenv('NODE_BINARY');

    if (is_string($configured) && $configured !== '') {
        return $configured;
    }

    exec('command -v node', $output, $exitCode);

    return $exitCode === 0 ? trim($output[0] ?? '') : null;
}

/**
 * @param  list<string>  $arguments
 * @return array{exitCode: int, stdout: string, stderr: string}
 */
function assertViteReferencesAreBuilt(string $directory, array $arguments = []): array
{
    return run([(string) nodeBinary(), packagePath('bin/assert-vite-references-are-built'), ...$arguments], $directory);
}

beforeEach(function (): void {
    if (nodeBinary() === null) {
        $this->markTestSkipped('Node.js is not installed.');
    }

    $this->directory = temporaryDirectory('vite');
    writeJson($this->directory.'/public/build/manifest.json', [
        'resources/css/app.css' => ['file' => 'assets/app.css'],
        'resources/js/app.js' => ['file' => 'assets/app.js'],
        'resources/images/logo.svg' => ['file' => 'assets/logo.svg'],
    ]);
    mkdir($this->directory.'/app', 0777, true);
    mkdir($this->directory.'/resources', 0777, true);
});

it('passes when every hard-coded reference is in the manifest', function (): void {
    writeFile($this->directory.'/resources/views/layout.blade.php', "@vite(['resources/css/app.css', \"resources/js/app.js\"])\n");
    writeFile($this->directory.'/app/Logo.php', "<?php\nreturn Vite::asset('resources/images/logo.svg');\n");

    $result = assertViteReferencesAreBuilt($this->directory);

    expect($result['exitCode'])->toBe(0)
        ->and($result['stdout'])->toContain('Vite references: 3 hard-coded reference(s), all present in the build manifest.');
});

it('lists the references missing from the manifest with their location', function (): void {
    writeFile($this->directory.'/resources/views/layout.blade.php', "<head>\n    @vite('resources/css/admin.css')\n</head>\n");

    $result = assertViteReferencesAreBuilt($this->directory);

    expect($result['exitCode'])->toBe(1)
        ->and($result['stderr'])->toContain('1 Vite reference(s) missing from the build manifest:')
        ->toContain('resources/views/layout.blade.php:2 → resources/css/admin.css');
});

it('fails on a call it cannot match against the manifest', function (): void {
    writeFile($this->directory.'/app/Asset.php', "<?php\nreturn Vite::content(\$path);\n");

    $result = assertViteReferencesAreBuilt($this->directory);

    expect($result['exitCode'])->toBe(1)
        ->and($result['stderr'])->toContain('1 Vite call(s) whose argument is not a hard-coded path:')
        ->toContain('app/Asset.php:2 Vite::content($path)');
});

it('fails when the build has not run', function (): void {
    unlink($this->directory.'/public/build/manifest.json');

    $result = assertViteReferencesAreBuilt($this->directory);

    expect($result['exitCode'])->toBe(1)
        ->and($result['stderr'])->toContain('public/build/manifest.json not found: run this check after the Vite build.');
});

it('reads the manifest and the scanned directories from options', function (): void {
    writeJson($this->directory.'/public/admin/manifest.json', ['resources/admin/app.js' => ['file' => 'assets/admin.js']]);
    writeFile($this->directory.'/modules/Admin/layout.blade.php', "@vite('resources/admin/app.js')\n");
    writeFile($this->directory.'/app/Ignored.php', "<?php\nreturn Vite::asset('resources/missing.svg');\n");

    $result = assertViteReferencesAreBuilt($this->directory, ['--manifest=public/admin/manifest.json', '--scan=modules']);

    expect($result['exitCode'])->toBe(0)
        ->and($result['stdout'])->toContain('1 hard-coded reference(s)');
});

it('fails when a scanned directory does not exist', function (): void {
    $result = assertViteReferencesAreBuilt($this->directory, ['--scan=app,modules']);

    expect($result['exitCode'])->toBe(1)
        ->and($result['stderr'])->toContain('Scanned directory not found: modules. Pass the existing ones with --scan.');
});

it('checks a reference against the manifest of the build directory it names', function (): void {
    writeJson($this->directory.'/public/build-admin/manifest.json', ['resources/admin/app.js' => ['file' => 'assets/admin.js']]);
    writeFile($this->directory.'/resources/views/admin.blade.php', "@vite('resources/admin/app.js', 'build-admin')\n@vite(['resources/admin/missing.js'], \"build-admin\")\n");
    writeFile($this->directory.'/app/Logo.php', "<?php\nreturn Vite::asset('resources/images/logo.svg', 'build-gone');\n");

    $result = assertViteReferencesAreBuilt($this->directory);

    expect($result['exitCode'])->toBe(1)
        ->and($result['stderr'])->toContain('2 Vite reference(s) missing from the build manifest:')
        ->toContain('resources/views/admin.blade.php:2 → resources/admin/missing.js (build directory build-admin)')
        ->toContain('app/Logo.php:2 → resources/images/logo.svg (build directory build-gone, no manifest)')
        ->not->toContain('→ build-admin');
});

it('cannot check a build directory that is not hard-coded', function (): void {
    writeFile($this->directory.'/resources/views/admin.blade.php', "@vite('resources/js/app.js', \$buildDirectory)\n");

    $result = assertViteReferencesAreBuilt($this->directory);

    expect($result['exitCode'])->toBe(1)
        ->and($result['stderr'])->toContain("resources/views/admin.blade.php:1 @vite('resources/js/app.js', \$buildDirectory)");
});

it('leaves out the calls that never run', function (): void {
    writeFile($this->directory.'/resources/views/layout.blade.php', <<<'BLADE'
        {{-- @vite('resources/js/legacy.js') --}}
        <code>@@vite('resources/js/doc.js')</code>
        @verbatim
            @vite('resources/js/verbatim.js')
        @endverbatim
        <a href="https://example.com">@vite('resources/js/app.js')</a>
        BLADE);
    writeFile($this->directory.'/app/Legacy.php', <<<'PHP'
        <?php
        // return Vite::asset('resources/images/old.svg');
        # Vite::asset('resources/images/older.svg');
        /* Vite::content('resources/images/oldest.svg'); */
        return [MyVite::asset('resources/images/other.svg'), 'not // a comment', Vite::asset('resources/images/logo.svg')];
        PHP);

    $result = assertViteReferencesAreBuilt($this->directory);

    expect($result['exitCode'])->toBe(0, output($result))
        ->and($result['stdout'])->toContain('2 hard-coded reference(s)');
});

it('reads the arguments without being misled by parentheses in strings', function (): void {
    writeJson($this->directory.'/public/build/manifest.json', ['resources/js/app (v2).js' => ['file' => 'assets/app.js']]);
    writeFile($this->directory.'/resources/views/layout.blade.php', "@vite(['resources/js/app (v2).js',])\n");

    $result = assertViteReferencesAreBuilt($this->directory);

    expect($result['exitCode'])->toBe(0, output($result))
        ->and($result['stdout'])->toContain('1 hard-coded reference(s)');
});

it('does not take an inherited object key for a manifest entry', function (): void {
    writeFile($this->directory.'/resources/views/layout.blade.php', "@vite('constructor')\n@vite('__proto__')\n");

    $result = assertViteReferencesAreBuilt($this->directory);

    expect($result['exitCode'])->toBe(1)
        ->and($result['stderr'])->toContain('2 Vite reference(s) missing from the build manifest:');
});

it('scans a directory given as an absolute path', function (): void {
    writeFile($this->directory.'/modules/Admin/layout.blade.php', "@vite('resources/js/app.js')\n");

    $result = assertViteReferencesAreBuilt($this->directory, ['--scan='.$this->directory.'/modules']);

    expect($result['exitCode'])->toBe(0, output($result))
        ->and($result['stdout'])->toContain('1 hard-coded reference(s)');
});
