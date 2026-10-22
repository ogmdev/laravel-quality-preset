<?php

declare(strict_types=1);

afterEach(function (): void {
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
    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $workingDirectory,
        [...getenv(), ...$environment],
    );

    if (! is_resource($process)) {
        throw new RuntimeException(sprintf('Could not start [%s].', implode(' ', $command)));
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exitCode' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
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
