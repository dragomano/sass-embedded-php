<?php

declare(strict_types=1);

use Bugo\Sass\Plugin;
use Composer\Composer;
use Composer\Config;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

function makePluginWithPlatform(string $osFamily, string $machine): Plugin
{
    return new class ($osFamily, $machine) extends Plugin {
        public function __construct(
            private readonly string $fakeOsFamily,
            private readonly string $fakeMachine,
        ) {}

        protected function getOsFamily(): string
        {
            return $this->fakeOsFamily;
        }

        protected function getMachine(): string
        {
            return $this->fakeMachine;
        }

        public function exposeDetectPlatform(): string
        {
            return $this->detectPlatform();
        }
    };
}

function makePluginWithOs(string $osFamily): Plugin
{
    return new class ($osFamily) extends Plugin {
        public function __construct(private readonly string $fakeOsFamily) {}

        protected function getOsFamily(): string
        {
            return $this->fakeOsFamily;
        }

        public function exposeIsNativeSassInstalled(string $targetDir): bool
        {
            return $this->isNativeSassInstalled($targetDir);
        }

        public function exposeExtractArchive(string $archivePath, string $targetDir): void
        {
            $this->extractArchive($archivePath, $targetDir);
        }
    };
}

function makePluginForFlatten(array $overrides = []): Plugin
{
    return new class ($overrides) extends Plugin {
        public function __construct(private readonly array $overrides) {}

        protected function scandirPath(string $path): array|false
        {
            return $this->overrides['scandir'] ?? parent::scandirPath($path);
        }

        protected function renamePath(string $from, string $to): bool
        {
            return $this->overrides['rename'] ?? parent::renamePath($from, $to);
        }

        protected function rmdirPath(string $path): bool
        {
            return $this->overrides['rmdir'] ?? parent::rmdirPath($path);
        }

        public function exposeFlatten(string $targetDir): void
        {
            $this->flattenExtractedSass($targetDir);
        }
    };
}

function makePluginForRemove(array $overrides = []): Plugin
{
    return new class ($overrides) extends Plugin {
        public function __construct(private readonly array $overrides) {}

        protected function unlinkPath(string $path): bool
        {
            return $this->overrides['unlink'] ?? parent::unlinkPath($path);
        }

        protected function scandirPath(string $path): array|false
        {
            return $this->overrides['scandir'] ?? parent::scandirPath($path);
        }

        protected function rmdirPath(string $path): bool
        {
            return $this->overrides['rmdir'] ?? parent::rmdirPath($path);
        }

        public function exposeRemovePath(string $path): void
        {
            $this->removePath($path);
        }
    };
}

beforeEach(function () {
    $this->plugin   = new Plugin();
    $this->composer = Mockery::mock(Composer::class);
    $this->io       = Mockery::mock(IOInterface::class);
    $this->config   = Mockery::mock(Config::class);
});

afterEach(function () {
    Mockery::close();
});

it('implements PluginInterface', function () {
    expect($this->plugin)->toBeInstanceOf(PluginInterface::class);
});

it('implements EventSubscriberInterface', function () {
    expect($this->plugin)->toBeInstanceOf(EventSubscriberInterface::class);
});

it('returns subscribed events', function () {
    $events = Plugin::getSubscribedEvents();

    expect($events)->toBeArray()
        ->and($events)->toHaveKey(PackageEvents::POST_PACKAGE_INSTALL)
        ->and($events)->toHaveKey(PackageEvents::POST_PACKAGE_UPDATE)
        ->and($events)->toHaveKey(ScriptEvents::POST_AUTOLOAD_DUMP)
        ->and($events[PackageEvents::POST_PACKAGE_INSTALL])->toBe('onPackageEvent')
        ->and($events[PackageEvents::POST_PACKAGE_UPDATE])->toBe('onPackageEvent')
        ->and($events[ScriptEvents::POST_AUTOLOAD_DUMP])->toBe('onScriptEvent');
});

it('activates plugin and initializes paths', function () {
    $this->config->shouldReceive('get')
        ->with('bin-dir')
        ->once()
        ->andReturn('vendor/bin');

    $this->composer->shouldReceive('getConfig')
        ->once()
        ->andReturn($this->config);

    $this->plugin->activate($this->composer, $this->io);

    expect(true)->toBeTrue();
});

it('deactivate does nothing', function () {
    $this->plugin->deactivate($this->composer, $this->io);

    expect(true)->toBeTrue();
});

it('uninstall does nothing', function () {
    $this->plugin->uninstall($this->composer, $this->io);

    expect(true)->toBeTrue();
});

it('onPackageEvent triggers installation when sass not installed', function () {
    $packageEvent = Mockery::mock(PackageEvent::class);

    $this->config->shouldReceive('get')
        ->with('bin-dir')
        ->once()
        ->andReturn('vendor/bin');

    $this->composer->shouldReceive('getConfig')
        ->once()
        ->andReturn($this->config);

    $packageEvent->shouldReceive('getComposer')
        ->once()
        ->andReturn($this->composer);

    $packageEvent->shouldReceive('getIO')
        ->once()
        ->andReturn($this->io);

    $this->io->shouldReceive('write')->zeroOrMoreTimes();

    $this->plugin->activate($this->composer, $this->io);
    $this->plugin->onPackageEvent($packageEvent);

    expect(true)->toBeTrue();
});

it('onScriptEvent calls getComposer and getIO', function () {
    $scriptEvent = Mockery::mock(Event::class);

    $this->config->shouldReceive('get')
        ->with('bin-dir')
        ->andReturn('vendor/bin');

    $this->composer->shouldReceive('getConfig')
        ->andReturn($this->config);

    $scriptEvent->shouldReceive('getComposer')
        ->once()
        ->andReturn($this->composer);

    $scriptEvent->shouldReceive('getIO')
        ->once()
        ->andReturn($this->io);

    $this->plugin->activate($this->composer, $this->io);
    $this->plugin->onScriptEvent($scriptEvent);

    expect(true)->toBeTrue();
});

it('installBinary static method calls getComposer and getIO', function () {
    $scriptEvent = Mockery::mock(Event::class);

    $this->config->shouldReceive('get')
        ->with('bin-dir')
        ->andReturn('vendor/bin');

    $this->composer->shouldReceive('getConfig')
        ->andReturn($this->config);

    $scriptEvent->shouldReceive('getComposer')
        ->once()
        ->andReturn($this->composer);

    $scriptEvent->shouldReceive('getIO')
        ->once()
        ->andReturn($this->io);

    Plugin::installBinary($scriptEvent);

    expect(true)->toBeTrue();
});

it('runInstall executes only once', function () {
    $scriptEvent1 = Mockery::mock(Event::class);
    $scriptEvent2 = Mockery::mock(Event::class);

    $this->config->shouldReceive('get')
        ->with('bin-dir')
        ->andReturn('vendor/bin');

    $this->composer->shouldReceive('getConfig')
        ->andReturn($this->config);

    $scriptEvent1->shouldReceive('getComposer')
        ->once()
        ->andReturn($this->composer);

    $scriptEvent1->shouldReceive('getIO')
        ->once()
        ->andReturn($this->io);

    $scriptEvent2->shouldReceive('getComposer')
        ->once()
        ->andReturn($this->composer);

    $scriptEvent2->shouldReceive('getIO')
        ->once()
        ->andReturn($this->io);

    $this->plugin->activate($this->composer, $this->io);
    $this->plugin->onScriptEvent($scriptEvent1);
    $this->plugin->onScriptEvent($scriptEvent2);

    expect(true)->toBeTrue();
});

it('onPackageEvent calls getComposer and getIO', function () {
    $packageEvent = Mockery::mock(PackageEvent::class);

    $this->config->shouldReceive('get')
        ->with('bin-dir')
        ->andReturn('vendor/bin');

    $this->composer->shouldReceive('getConfig')
        ->andReturn($this->config);

    $packageEvent->shouldReceive('getComposer')
        ->once()
        ->andReturn($this->composer);

    $packageEvent->shouldReceive('getIO')
        ->once()
        ->andReturn($this->io);

    $this->plugin->activate($this->composer, $this->io);
    $this->plugin->onPackageEvent($packageEvent);

    expect(true)->toBeTrue();
});

it('detectPlatform returns linux-arm64 for aarch64 machine', function () {
    $plugin = makePluginWithPlatform('Linux', 'aarch64');

    expect($plugin->exposeDetectPlatform())->toBe('linux-arm64');
});

it('detectPlatform returns macos-arm64 for arm64 machine', function () {
    $plugin = makePluginWithPlatform('Darwin', 'arm64');

    expect($plugin->exposeDetectPlatform())->toBe('macos-arm64');
});

it('detectPlatform returns linux-arm for armv7l machine', function () {
    $plugin = makePluginWithPlatform('Linux', 'armv7l');

    expect($plugin->exposeDetectPlatform())->toBe('linux-arm');
});

it('detectPlatform returns windows-x64 for default machine on Windows', function () {
    $plugin = makePluginWithPlatform('Windows', 'x86_64');

    expect($plugin->exposeDetectPlatform())->toBe('windows-x64');
});

it('detectPlatform throws RuntimeException for unsupported OS', function () {
    $plugin = makePluginWithPlatform('FreeBSD', 'x86_64');

    expect(fn() => $plugin->exposeDetectPlatform())
        ->toThrow(RuntimeException::class, 'Unsupported OS: FreeBSD');
});

it('getLatestVersion throws RuntimeException when HTTP request fails', function () {
    $plugin = new class () extends Plugin {
        protected function fetchUrl(string $url): string|false
        {
            return false;
        }

        public function exposeGetLatestVersion(): string
        {
            return $this->getLatestVersion();
        }
    };

    expect(fn() => $plugin->exposeGetLatestVersion())
        ->toThrow(RuntimeException::class, 'Failed to fetch latest Dart Sass version from GitHub');
});

it('getLatestVersion throws RuntimeException when response has no tag_name', function () {
    $plugin = new class () extends Plugin {
        protected function fetchUrl(string $url): string|false
        {
            return json_encode(['name' => 'some-release']);
        }

        public function exposeGetLatestVersion(): string
        {
            return $this->getLatestVersion();
        }
    };

    expect(fn() => $plugin->exposeGetLatestVersion())
        ->toThrow(RuntimeException::class, 'Invalid GitHub API response');
});

it('getLatestVersion returns version string without leading v', function () {
    $plugin = new class () extends Plugin {
        protected function fetchUrl(string $url): string|false
        {
            return json_encode(['tag_name' => 'v1.77.0']);
        }

        public function exposeGetLatestVersion(): string
        {
            return $this->getLatestVersion();
        }
    };

    expect($plugin->exposeGetLatestVersion())->toBe('1.77.0');
});

it('isNativeSassInstalled returns true on Windows when all files exist', function () {
    $dir = sys_get_temp_dir() . '/sass-test-' . uniqid();

    mkdir($dir . '/src', 0777, true);
    touch($dir . '/sass.bat');
    touch($dir . '/src/dart.exe');
    touch($dir . '/src/sass.snapshot');

    $plugin = makePluginWithOs('Windows');

    expect($plugin->exposeIsNativeSassInstalled($dir))->toBeTrue();

    unlink($dir . '/sass.bat');
    unlink($dir . '/src/dart.exe');
    unlink($dir . '/src/sass.snapshot');
    rmdir($dir . '/src');
    rmdir($dir);
});

it('isNativeSassInstalled returns false on Windows when files are missing', function () {
    $dir = sys_get_temp_dir() . '/sass-test-' . uniqid();

    mkdir($dir, 0777, true);

    $plugin = makePluginWithOs('Windows');

    expect($plugin->exposeIsNativeSassInstalled($dir))->toBeFalse();

    rmdir($dir);
});

it('extractArchive on Windows throws RuntimeException when zip cannot be opened', function () {
    $plugin = makePluginWithOs('Windows');

    expect(fn() => $plugin->exposeExtractArchive('/nonexistent/archive.zip', sys_get_temp_dir()))
        ->toThrow(RuntimeException::class, 'Failed to open ZIP archive');
});

it('extractArchive on Windows extracts zip successfully', function () {
    $targetDir = sys_get_temp_dir() . '/sass-zip-test-' . uniqid();
    $zipPath   = sys_get_temp_dir() . '/test-' . uniqid() . '.zip';

    mkdir($targetDir, 0777, true);

    // Create a minimal valid zip
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('hello.txt', 'hello');
    $zip->close();

    $plugin = makePluginWithOs('Windows');
    $plugin->exposeExtractArchive($zipPath, $targetDir);

    expect(file_exists($targetDir . '/hello.txt'))->toBeTrue();

    unlink($zipPath);
    unlink($targetDir . '/hello.txt');
    rmdir($targetDir);
});

it('downloadNativeSass writes already installed message when sass is present', function () {
    $io       = Mockery::mock(IOInterface::class);
    $composer = Mockery::mock(Composer::class);
    $config   = Mockery::mock(Config::class);
    $event    = Mockery::mock(Event::class);

    $config->shouldReceive('get')->with('bin-dir')->andReturn('vendor/bin');
    $composer->shouldReceive('getConfig')->andReturn($config);
    $event->shouldReceive('getComposer')->andReturn($composer);
    $event->shouldReceive('getIO')->andReturn($io);

    $io->shouldReceive('write')
        ->once()
        ->with(Mockery::pattern('/already installed/'));

    $plugin = new class () extends Plugin {
        protected function getPackagePath(): string
        {
            return '';
        }

        protected function isNativeSassInstalled(string $targetDir): bool
        {
            return true;
        }

        protected function runInstall(IOInterface $io): void
        {
            $this->downloadNativeSass($io);
        }
    };

    $plugin->onScriptEvent($event);

    expect(true)->toBeTrue();

    Mockery::close();
});

it('downloadNativeSass on Windows extracts zip and flattens dart-sass directory', function () {
    $baseDir   = sys_get_temp_dir() . '/sass-dl-test-' . uniqid();
    $targetDir = $baseDir . '/bin';

    mkdir($targetDir, 0777, true);

    $zipPath = $targetDir . '/fake.zip';

    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('dart-sass/sass', '#!/bin/sh');
    $zip->close();

    $zipContent = file_get_contents($zipPath);

    unlink($zipPath);

    $io       = Mockery::mock(IOInterface::class);
    $composer = Mockery::mock(Composer::class);
    $config   = Mockery::mock(Config::class);
    $event    = Mockery::mock(Event::class);

    $config->shouldReceive('get')->with('bin-dir')->andReturn('vendor/bin');

    $composer->shouldReceive('getConfig')->andReturn($config);

    $event->shouldReceive('getComposer')->andReturn($composer);
    $event->shouldReceive('getIO')->andReturn($io);

    $io->shouldReceive('write')->zeroOrMoreTimes();

    $plugin = new class ($baseDir, $zipContent) extends Plugin {
        public function __construct(
            private readonly string $fakeBaseDir,
            private readonly string $fakeZipContent,
        ) {}

        protected function getPackagePath(): string
        {
            return $this->fakeBaseDir;
        }

        protected function getOsFamily(): string
        {
            return 'Windows';
        }

        protected function getMachine(): string
        {
            return 'x86_64';
        }

        protected function getLatestVersion(): string
        {
            return '1.99.0';
        }

        protected function isNativeSassInstalled(string $targetDir): bool
        {
            return false;
        }

        protected function downloadFile(string $url, string $targetPath): void
        {
            file_put_contents($targetPath, $this->fakeZipContent);
        }

        protected function runInstall(IOInterface $io): void
        {
            $this->downloadNativeSass($io);
        }
    };

    $caughtError = null;

    try {
        $plugin->onScriptEvent($event);
    } catch (Throwable $e) {
        $caughtError = $e->getMessage();
    }

    $files = [];
    if (is_dir($targetDir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($targetDir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $files[] = $f->getPathname();
        }
    }

    expect($caughtError)->toBeNull()
        ->and($files)->not->toBeEmpty()
        ->and($files[0])->toContain('sass');

    // cleanup
    foreach ($files as $file) {
        @unlink($file);
    }

    @rmdir($targetDir);
    @rmdir($baseDir);

    Mockery::close();
});

it('flattenExtractedSass returns early when dart-sass directory does not exist', function () {
    $dir = sys_get_temp_dir() . '/flatten-test-' . uniqid();

    mkdir($dir, 0777, true);

    $plugin = makePluginForFlatten();
    $plugin->exposeFlatten($dir);

    // no exception means early return was hit
    expect(is_dir($dir . '/dart-sass'))->toBeFalse();

    rmdir($dir);
});

it('flattenExtractedSass throws when scandir fails', function () {
    $dir       = sys_get_temp_dir() . '/flatten-test-' . uniqid();
    $sourceDir = $dir . '/dart-sass';

    mkdir($sourceDir, 0777, true);

    $plugin = makePluginForFlatten(['scandir' => false]);

    expect(fn() => $plugin->exposeFlatten($dir))
        ->toThrow(RuntimeException::class, 'Failed to read extracted Dart Sass directory');

    rmdir($sourceDir);
    rmdir($dir);
});

it('flattenExtractedSass throws when rename fails', function () {
    $dir       = sys_get_temp_dir() . '/flatten-test-' . uniqid();
    $sourceDir = $dir . '/dart-sass';

    mkdir($sourceDir, 0777, true);

    file_put_contents($sourceDir . '/sass', '#!/bin/sh');

    $plugin = makePluginForFlatten(['rename' => false]);

    expect(fn() => $plugin->exposeFlatten($dir))
        ->toThrow(RuntimeException::class, 'Failed to move extracted file: sass');

    unlink($sourceDir . '/sass');
    rmdir($sourceDir);
    rmdir($dir);
});

it('flattenExtractedSass throws when rmdir fails after moving files', function () {
    $dir       = sys_get_temp_dir() . '/flatten-test-' . uniqid();
    $sourceDir = $dir . '/dart-sass';

    mkdir($sourceDir, 0777, true);

    file_put_contents($sourceDir . '/sass', '#!/bin/sh');

    $plugin = makePluginForFlatten(['rmdir' => false]);

    expect(fn() => $plugin->exposeFlatten($dir))
        ->toThrow(RuntimeException::class, 'Failed to remove temporary Dart Sass directory');

    @unlink($dir . '/sass');
    @rmdir($sourceDir);
    @rmdir($dir);
});

it('downloadFile throws when HTTP download fails', function () {
    $plugin = new class () extends Plugin {
        protected function fetchFileContent(string $url): string|false
        {
            return false;
        }

        public function exposeDownloadFile(string $url, string $targetPath): void
        {
            $this->downloadFile($url, $targetPath);
        }
    };

    expect(fn() => $plugin->exposeDownloadFile('https://example.com/sass.tar.gz', '/tmp/sass.tar.gz'))
        ->toThrow(RuntimeException::class, 'Failed to download Dart Sass from: https://example.com/sass.tar.gz');
});

it('extractArchive on Unix runs tar and succeeds', function () {
    $targetDir   = sys_get_temp_dir() . '/extract-unix-' . uniqid();
    $archivePath = '/fake/sass.tar.gz';

    mkdir($targetDir, 0777, true);

    $process = Mockery::mock(Symfony\Component\Process\Process::class);
    $process->shouldReceive('run')->once();
    $process->shouldReceive('isSuccessful')->once()->andReturn(true);

    $plugin = new class ($process) extends Plugin {
        public function __construct(private readonly Symfony\Component\Process\Process $fakeProcess) {}

        protected function getOsFamily(): string
        {
            return 'Linux';
        }

        protected function createTarProcess(string $archivePath, string $targetDir): Symfony\Component\Process\Process
        {
            return $this->fakeProcess;
        }

        public function exposeExtractArchive(string $archivePath, string $targetDir): void
        {
            $this->extractArchive($archivePath, $targetDir);
        }
    };

    $plugin->exposeExtractArchive($archivePath, $targetDir);

    expect(is_dir($targetDir))->toBeTrue();

    rmdir($targetDir);
    Mockery::close();
});

it('extractArchive on Unix throws when tar fails', function () {
    $process = Mockery::mock(Symfony\Component\Process\Process::class);
    $process->shouldReceive('run')->once();
    $process->shouldReceive('isSuccessful')->once()->andReturn(false);
    $process->shouldReceive('getErrorOutput')->once()->andReturn('tar: file not found');

    $plugin = new class ($process) extends Plugin {
        public function __construct(private readonly Symfony\Component\Process\Process $fakeProcess) {}

        protected function getOsFamily(): string
        {
            return 'Linux';
        }

        protected function createTarProcess(string $archivePath, string $targetDir): Symfony\Component\Process\Process
        {
            return $this->fakeProcess;
        }

        public function exposeExtractArchive(string $archivePath, string $targetDir): void
        {
            $this->extractArchive($archivePath, $targetDir);
        }
    };

    expect(fn() => $plugin->exposeExtractArchive('/fake/archive.tar.gz', sys_get_temp_dir()))
        ->toThrow(RuntimeException::class, 'Failed to extract archive: tar: file not found');

    Mockery::close();
});

it('removePath throws when unlink fails on a file', function () {
    $file = sys_get_temp_dir() . '/remove-test-' . uniqid();

    file_put_contents($file, 'data');

    $plugin = makePluginForRemove(['unlink' => false]);

    expect(fn() => $plugin->exposeRemovePath($file))
        ->toThrow(RuntimeException::class, 'Failed to remove file: ' . $file);

    unlink($file);
});

it('removePath successfully removes a file and returns', function () {
    $file = sys_get_temp_dir() . '/remove-test-' . uniqid();

    file_put_contents($file, 'data');

    $plugin = makePluginForRemove();
    $plugin->exposeRemovePath($file);

    expect(file_exists($file))->toBeFalse();
});

it('removePath throws when scandir fails on a directory', function () {
    $dir = sys_get_temp_dir() . '/remove-dir-' . uniqid();

    mkdir($dir, 0777, true);

    $plugin = makePluginForRemove(['scandir' => false]);

    expect(fn() => $plugin->exposeRemovePath($dir))
        ->toThrow(RuntimeException::class, 'Failed to read directory: ' . $dir);

    rmdir($dir);
});

it('removePath recursively removes directory contents', function () {
    $dir = sys_get_temp_dir() . '/remove-dir-' . uniqid();

    mkdir($dir . '/sub', 0777, true);

    file_put_contents($dir . '/sub/file.txt', 'data');

    $plugin = makePluginForRemove();
    $plugin->exposeRemovePath($dir);

    expect(is_dir($dir))->toBeFalse();
});

it('removePath throws when rmdir fails on a directory', function () {
    $dir = sys_get_temp_dir() . '/remove-dir-' . uniqid();

    mkdir($dir, 0777, true);

    $plugin = makePluginForRemove(['rmdir' => false]);

    expect(fn() => $plugin->exposeRemovePath($dir))
        ->toThrow(RuntimeException::class, 'Failed to remove directory: ' . $dir);

    rmdir($dir);
});

it('isNativeSassInstalled returns true on Unix when all files exist', function () {
    $dir = sys_get_temp_dir() . '/sass-unix-test-' . uniqid();

    mkdir($dir . '/src', 0777, true);
    touch($dir . '/sass');
    touch($dir . '/src/dart');
    touch($dir . '/src/sass.snapshot');

    $plugin = makePluginWithOs('Linux');

    expect($plugin->exposeIsNativeSassInstalled($dir))->toBeTrue();

    unlink($dir . '/sass');
    unlink($dir . '/src/dart');
    unlink($dir . '/src/sass.snapshot');
    rmdir($dir . '/src');
    rmdir($dir);
});

it('isNativeSassInstalled returns false on Unix when files are missing', function () {
    $dir = sys_get_temp_dir() . '/sass-unix-test-' . uniqid();

    mkdir($dir, 0777, true);

    $plugin = makePluginWithOs('Linux');

    expect($plugin->exposeIsNativeSassInstalled($dir))->toBeFalse();

    rmdir($dir);
});

it('createTarProcess returns a Process configured with tar command', function () {
    $plugin = new class () extends Plugin {
        public function exposeCreateTarProcess(string $archivePath, string $targetDir): Symfony\Component\Process\Process
        {
            return $this->createTarProcess($archivePath, $targetDir);
        }
    };

    $process = $plugin->exposeCreateTarProcess('/tmp/sass.tar.gz', '/tmp/target');

    expect($process)->toBeInstanceOf(Symfony\Component\Process\Process::class)
        ->and($process->getCommandLine())->toContain('tar');
});
