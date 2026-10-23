<?php

declare(strict_types=1);

final class LastTemporaryDirectory
{
    public static ?string $path = null;
}

it('creates a temporary directory', function (): void {
    LastTemporaryDirectory::$path = temporaryDirectory('helpers');

    expect(LastTemporaryDirectory::$path)->toBeDirectory();
});

it('removes the temporary directories of the previous test', function (): void {
    expect(LastTemporaryDirectory::$path)->not->toBeNull()
        ->and(LastTemporaryDirectory::$path)->not->toBeDirectory();
})->depends('it creates a temporary directory');

it('captures a large output on both streams without blocking', function (): void {
    $result = run([PHP_BINARY, '-r', 'fwrite(STDERR, str_repeat("e", 1 << 20)); echo str_repeat("o", 1 << 20); exit(3);'], sys_get_temp_dir());

    expect($result['exitCode'])->toBe(3)
        ->and(strlen($result['stdout']))->toBe(1 << 20)
        ->and(strlen($result['stderr']))->toBe(1 << 20);
});
