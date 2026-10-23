<?php

declare(strict_types=1);

// A bare afterEach() here would only apply to tests written in this file.
pest()->afterEach(function (): void {
    foreach (TemporaryDirectories::$created as $directory) {
        removeDirectory($directory);
    }

    TemporaryDirectories::$created = [];
});

final class TemporaryDirectories
{
    /** @var list<string> */
    public static array $created = [];
}

/**
 * @param  array{exitCode: int, stdout: string, stderr: string}  $result
 */
function output(array $result): string
{
    return $result['stdout'].$result['stderr'];
}

function packagePath(string $path = ''): string
{
    return dirname(__DIR__).($path === '' ? '' : '/'.$path);
}

function removeDirectory(string $directory): void
{
    if (! is_dir($directory) || is_link($directory)) {
        @unlink($directory);

        return;
    }

    foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
        removeDirectory($directory.'/'.$entry);
    }

    rmdir($directory);
}

/**
 * @param  list<string>  $command
 * @param  array<string, string>  $environment
 * @return array{exitCode: int, stdout: string, stderr: string}
 */
function run(array $command, string $workingDirectory, array $environment = []): array
{
    // Files rather than pipes: a child filling one pipe while the other is read would block both.
    $stdoutFile = (string) tempnam(sys_get_temp_dir(), 'stdout');
    $stderrFile = (string) tempnam(sys_get_temp_dir(), 'stderr');

    $process = proc_open(
        $command,
        [1 => ['file', $stdoutFile, 'w'], 2 => ['file', $stderrFile, 'w']],
        $pipes,
        $workingDirectory,
        [...getenv(), ...$environment],
    );

    if (! is_resource($process)) {
        throw new RuntimeException(sprintf('Could not start [%s].', implode(' ', $command)));
    }

    $exitCode = proc_close($process);
    $result = ['exitCode' => $exitCode, 'stdout' => (string) file_get_contents($stdoutFile), 'stderr' => (string) file_get_contents($stderrFile)];
    unlink($stdoutFile);
    unlink($stderrFile);

    return $result;
}

function temporaryDirectory(string $prefix): string
{
    $directory = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(4));
    mkdir($directory, 0777, true);
    TemporaryDirectories::$created[] = $directory;

    return $directory;
}

function writeFile(string $path, string $contents): void
{
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }

    file_put_contents($path, $contents);
}

/**
 * @param  array<mixed>  $data
 */
function writeJson(string $path, array $data): void
{
    writeFile($path, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}
