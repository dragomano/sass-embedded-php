<?php

declare(strict_types=1);

namespace Tests\Integration;

use Bugo\Sass\Plugin;
use Composer\Composer;
use Composer\Config;
use Composer\IO\IOInterface;
use Mockery;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

final class FakeDownloadSassPlugin extends Plugin
{
    public function __construct(
        private readonly string $fixtureArchivePath,
        private readonly string $fakePackagePath,
        private readonly string $fakeLatestVersion,
        private readonly string $fakeOsFamily = 'Linux',
        private readonly string $fakeMachine = 'x86_64',
    ) {}

    protected function getPackagePath(): string
    {
        return $this->fakePackagePath;
    }

    protected function getOsFamily(): string
    {
        return $this->fakeOsFamily;
    }

    protected function getMachine(): string
    {
        return $this->fakeMachine;
    }

    protected function fetchUrl(string $url): string|false
    {
        return json_encode(
            ['tag_name' => 'v' . $this->fakeLatestVersion],
            JSON_THROW_ON_ERROR,
        );
    }

    protected function fetchFileContent(string $url): string|false
    {
        $content = file_get_contents($this->fixtureArchivePath);

        return $content === false ? false : $content;
    }

    public function installNow(IOInterface $io): void
    {
        $this->downloadNativeSass($io);
    }
}

function createLinuxFixtureArchive(string $tmpDir): string
{
    $sourceDir = $tmpDir . '/fixture-source/dart-sass';

    mkdir($sourceDir . '/src', 0777, true);

    file_put_contents($sourceDir . '/sass', "#!/bin/sh\necho fake-sass\n");
    file_put_contents($sourceDir . '/src/dart', 'fake-dart-binary');
    file_put_contents($sourceDir . '/src/sass.snapshot', 'fake-snapshot');

    chmod($sourceDir . '/sass', 0755);
    chmod($sourceDir . '/src/dart', 0755);

    $archivePath = $tmpDir . '/dart-sass-fixture.tar.gz';

    $process = new Process(
        ['tar', '-czf', $archivePath, '-C', $tmpDir . '/fixture-source', 'dart-sass'],
    );
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException('Failed to build tar.gz fixture: ' . $process->getErrorOutput());
    }

    return $archivePath;
}

function createWindowsFixtureArchive(string $tmpDir): string
{
    $sourceDir = $tmpDir . '/fixture-source/dart-sass';

    mkdir($sourceDir . '/src', 0777, true);

    file_put_contents($sourceDir . '/sass.bat', "@echo off\r\necho fake-sass\r\n");
    file_put_contents($sourceDir . '/src/dart.exe', 'fake-dart-binary');
    file_put_contents($sourceDir . '/src/sass.snapshot', 'fake-snapshot');

    $archivePath = $tmpDir . '/dart-sass-fixture.zip';

    $zip = new ZipArchive();

    if ($zip->open($archivePath, ZipArchive::CREATE) !== true) {
        throw new RuntimeException('Failed to create zip fixture');
    }

    $zip->addFile($sourceDir . '/sass.bat', 'dart-sass/sass.bat');
    $zip->addFile($sourceDir . '/src/dart.exe', 'dart-sass/src/dart.exe');
    $zip->addFile($sourceDir . '/src/sass.snapshot', 'dart-sass/src/sass.snapshot');
    $zip->close();

    return $archivePath;
}

function removeDirectoryRecursively(string $path): void
{
    if (is_file($path)) {
        unlink($path);

        return;
    }

    if (! is_dir($path)) {
        return;
    }

    $entries = scandir($path);

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        removeDirectoryRecursively($path . '/' . $entry);
    }

    rmdir($path);
}

beforeEach(function () {
    $this->composer = mock(Composer::class);
    $this->io       = mock(IOInterface::class);
    $this->config   = mock(Config::class);

    $this->config
        ->shouldReceive('get')
        ->andReturnUsing(fn(string $key) => match ($key) {
            'bin-dir'      => 'vendor/bin',
            'github-oauth' => [],
            default        => null,
        });

    $this->composer->shouldReceive('getConfig')->andReturn($this->config);

    $this->tmpDir = sys_get_temp_dir() . '/sass-plugin-test-' . uniqid('', true);

    mkdir($this->tmpDir, 0777, true);
});

afterEach(function () {
    removeDirectoryRecursively($this->tmpDir);

    Mockery::close();
});

it('installs Sass when the bin directory does not exist (Linux/macOS path)', function () {
    $fixtureArchive = createLinuxFixtureArchive($this->tmpDir);
    $packagePath    = $this->tmpDir . '/package';

    mkdir($packagePath, 0777, true);

    $binDir = $packagePath . '/bin';

    $this->io->shouldReceive('write')->atLeast()->once();

    $plugin = new FakeDownloadSassPlugin(
        fixtureArchivePath: $fixtureArchive,
        fakePackagePath: $packagePath,
        fakeLatestVersion: '1.103.1',
        fakeOsFamily: 'Linux',
    );

    $plugin->activate($this->composer, $this->io);
    $plugin->installNow($this->io);

    expect(is_dir($binDir))->toBeTrue();
    expect(is_file($binDir . '/sass'))->toBeTrue();
    expect(is_file($binDir . '/src/dart'))->toBeTrue();
    expect(is_file($binDir . '/src/sass.snapshot'))->toBeTrue();
    expect(is_file($binDir . '/.sass-version'))->toBeTrue();
    expect(trim((string) file_get_contents($binDir . '/.sass-version')))->toBe('1.103.1');
    expect(is_dir($binDir . '/dart-sass'))->toBeFalse();
})->group('integration');

it('installs Sass when the bin directory does not exist (Windows path)', function () {
    $fixtureArchive = createWindowsFixtureArchive($this->tmpDir);
    $packagePath    = $this->tmpDir . '/package';

    mkdir($packagePath, 0777, true);

    $binDir = $packagePath . '/bin';

    $this->io->shouldReceive('write')->atLeast()->once();

    $plugin = new FakeDownloadSassPlugin(
        fixtureArchivePath: $fixtureArchive,
        fakePackagePath: $packagePath,
        fakeLatestVersion: '1.103.1',
        fakeOsFamily: 'Windows',
    );

    $plugin->activate($this->composer, $this->io);
    $plugin->installNow($this->io);

    expect(is_dir($binDir))->toBeTrue();
    expect(is_file($binDir . '/sass.bat'))->toBeTrue();
    expect(is_file($binDir . '/src/dart.exe'))->toBeTrue();
    expect(is_file($binDir . '/src/sass.snapshot'))->toBeTrue();
    expect(is_file($binDir . '/.sass-version'))->toBeTrue();
    expect(trim((string) file_get_contents($binDir . '/.sass-version')))->toBe('1.103.1');
    expect(is_dir($binDir . '/dart-sass'))->toBeFalse();
})->group('integration');

it('does not reinstall Sass when the same version is already installed', function () {
    $fixtureArchive = createLinuxFixtureArchive($this->tmpDir);
    $packagePath    = $this->tmpDir . '/package';

    mkdir($packagePath, 0777, true);

    $binDir = $packagePath . '/bin';

    $this->io->shouldReceive('write')->atLeast()->once();

    $plugin = new FakeDownloadSassPlugin(
        fixtureArchivePath: $fixtureArchive,
        fakePackagePath: $packagePath,
        fakeLatestVersion: '1.103.1',
        fakeOsFamily: 'Linux',
    );

    $plugin->activate($this->composer, $this->io);
    $plugin->installNow($this->io);

    $installedAt = filemtime($binDir . '/sass');

    sleep(1);

    $plugin->installNow($this->io);

    expect(filemtime($binDir . '/sass'))->toBe($installedAt);
    expect(trim((string) file_get_contents($binDir . '/.sass-version')))->toBe('1.103.1');
})->group('integration');

it('reinstalls Sass when a newer version is available', function () {
    $fixtureArchiveV1 = createLinuxFixtureArchive($this->tmpDir);
    $packagePath      = $this->tmpDir . '/package';

    mkdir($packagePath, 0777, true);

    $binDir = $packagePath . '/bin';

    $this->io->shouldReceive('write')->atLeast()->once();

    $pluginV1 = new FakeDownloadSassPlugin(
        fixtureArchivePath: $fixtureArchiveV1,
        fakePackagePath: $packagePath,
        fakeLatestVersion: '1.103.1',
        fakeOsFamily: 'Linux',
    );

    $pluginV1->activate($this->composer, $this->io);
    $pluginV1->installNow($this->io);

    expect(trim((string) file_get_contents($binDir . '/.sass-version')))->toBe('1.103.1');

    $pluginV2 = new FakeDownloadSassPlugin(
        fixtureArchivePath: $fixtureArchiveV1,
        fakePackagePath: $packagePath,
        fakeLatestVersion: '1.200.0',
        fakeOsFamily: 'Linux',
    );

    $pluginV2->activate($this->composer, $this->io);
    $pluginV2->installNow($this->io);

    expect(is_file($binDir . '/sass'))->toBeTrue();
    expect(trim((string) file_get_contents($binDir . '/.sass-version')))->toBe('1.200.0');
})->group('integration');
