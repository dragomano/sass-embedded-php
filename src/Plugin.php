<?php

declare(strict_types=1);

namespace Bugo\Sass;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

use function basename;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_dir;
use function is_file;
use function json_decode;
use function ltrim;
use function mkdir;
use function php_uname;
use function realpath;
use function rename;
use function rmdir;
use function scandir;
use function sprintf;
use function stream_context_create;
use function unlink;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    private const PACKAGE_NAME = 'sass-embedded-php';

    private const GITHUB_API_URL = 'https://api.github.com/repos/sass/dart-sass/releases/latest';

    private const SASS_BASE_URL = 'https://github.com/sass/dart-sass/releases/download';

    private string $packagePath = '';

    private string $binDir = '';

    public function activate(Composer $composer, IOInterface $io): void
    {
        $config = $composer->getConfig();

        $this->packagePath = (string) realpath(__DIR__ . '/../');
        $this->binDir      = (string) $config->get('bin-dir');
    }

    public function deactivate(Composer $composer, IOInterface $io): void {}

    public function uninstall(Composer $composer, IOInterface $io): void {}

    /* @uses onPackageEvent */
    /* @uses onScriptEvent */
    public static function getSubscribedEvents(): array
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'onPackageEvent',
            PackageEvents::POST_PACKAGE_UPDATE  => 'onPackageEvent',
            ScriptEvents::POST_AUTOLOAD_DUMP    => 'onScriptEvent',
        ];
    }

    public function onPackageEvent(PackageEvent $event): void
    {
        $this->ensureInitializedFromComposer($event->getComposer());
        $this->runInstall($event->getIO());
    }

    public function onScriptEvent(Event $event): void
    {
        $this->ensureInitializedFromComposer($event->getComposer());
        $this->runInstall($event->getIO());
    }

    public static function installBinary(Event $event): void
    {
        $plugin = new self();
        $plugin->ensureInitializedFromComposer($event->getComposer());
        $plugin->runInstall($event->getIO());
    }

    protected function ensureInitializedFromComposer(?Composer $composer): void
    {
        if ($this->packagePath === '') {
            $this->packagePath = (string) realpath(__DIR__ . '/../');
        }

        if ($composer !== null && $this->binDir === '') {
            $this->binDir = (string) $composer->getConfig()->get('bin-dir');
        }
    }

    protected function runInstall(IOInterface $io): void
    {
        static $alreadyRun = false;

        if ($alreadyRun) {
            return;
        }

        $alreadyRun = true;

        $this->downloadNativeSass($io);
    }

    protected function getOsFamily(): string
    {
        return PHP_OS_FAMILY;
    }

    protected function getMachine(): string
    {
        return php_uname('m');
    }

    protected function detectPlatform(): string
    {
        $osFamily = $this->getOsFamily();

        $os = match ($osFamily) {
            'Windows' => 'windows',
            'Darwin'  => 'macos',
            'Linux'   => 'linux',
            default   => throw new RuntimeException('Unsupported OS: ' . $osFamily),
        };

        $arch = match ($this->getMachine()) {
            'aarch64',
            'arm64'  => 'arm64',
            'armv7l' => 'arm',
            default  => 'x64',
        };

        return $os . '-' . $arch;
    }

    protected function fetchUrl(string $url): string|false
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", [
                    'Accept: application/vnd.github+json',
                    'User-Agent: sass-embedded-php',
                ]) . "\r\n",
            ],
        ]);

        return file_get_contents($url, false, $context);
    }

    protected function getLatestVersion(): string
    {
        $response = $this->fetchUrl(self::GITHUB_API_URL);

        if ($response === false) {
            throw new RuntimeException('Failed to fetch latest Dart Sass version from GitHub');
        }

        $data = json_decode($response, true);

        if (! isset($data['tag_name'])) {
            throw new RuntimeException('Invalid GitHub API response');
        }

        return ltrim((string) $data['tag_name'], 'v');
    }

    protected function getPackagePath(): string
    {
        return $this->packagePath;
    }

    protected function downloadNativeSass(IOInterface $io): void
    {
        $targetDir = $this->getPackagePath() . '/bin';

        if ($this->isNativeSassInstalled($targetDir)) {
            $io->write(sprintf(
                '<info>[%s]</info> Native Dart Sass already installed.',
                self::PACKAGE_NAME
            ));

            return;
        }

        $version     = $this->getLatestVersion();
        $platform    = $this->detectPlatform();
        $extension   = $this->getOsFamily() === 'Windows' ? 'zip' : 'tar.gz';
        $filename    = "dart-sass-$version-$platform.$extension";
        $url         = self::SASS_BASE_URL . "/$version/$filename";
        $archivePath = $targetDir . '/' . $filename;

        $io->write(sprintf(
            '<info>[%s]</info> Downloading Dart Sass %s for %s...',
            self::PACKAGE_NAME,
            $version,
            $platform
        ));

        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $this->downloadFile($url, $archivePath);

        $io->write(sprintf(
            '<info>[%s]</info> Extracting %s...',
            self::PACKAGE_NAME,
            $filename
        ));

        $this->extractArchive($archivePath, $targetDir);
        $this->flattenExtractedSass($targetDir);

        unlink($archivePath);

        $io->write(sprintf(
            '<info>[%s]</info> Native Dart Sass %s installed successfully.',
            self::PACKAGE_NAME,
            $version
        ));
    }

    protected function isNativeSassInstalled(string $targetDir): bool
    {
        if ($this->getOsFamily() === 'Windows') {
            return is_file($targetDir . '/sass.bat')
                && is_file($targetDir . '/src/dart.exe')
                && is_file($targetDir . '/src/sass.snapshot');
        }

        return is_file($targetDir . '/sass')
            && is_file($targetDir . '/src/dart')
            && is_file($targetDir . '/src/sass.snapshot');
    }

    protected function downloadFile(string $url, string $targetPath): void
    {
        $result = $this->fetchFileContent($url);

        if ($result === false) {
            throw new RuntimeException('Failed to download Dart Sass from: ' . $url);
        }

        file_put_contents($targetPath, $result);
    }

    protected function fetchFileContent(string $url): string|false
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: sass-embedded-php\r\n",
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        return file_get_contents($url, false, $context);
    }

    protected function extractArchive(string $archivePath, string $targetDir): void
    {
        if ($this->getOsFamily() === 'Windows') {
            $zip    = new ZipArchive();
            $result = $zip->open($archivePath);

            if ($result !== true) {
                throw new RuntimeException('Failed to open ZIP archive: code ' . $result);
            }

            $zip->extractTo($targetDir);
            $zip->close();
        } else {
            $process = $this->createTarProcess($archivePath, $targetDir);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException('Failed to extract archive: ' . $process->getErrorOutput());
            }
        }
    }

    protected function createTarProcess(string $archivePath, string $targetDir): Process
    {
        return new Process(['tar', '-xzf', $archivePath, '-C', $targetDir]);
    }

    protected function flattenExtractedSass(string $targetDir): void
    {
        $sourceDir = $targetDir . '/dart-sass';

        if (! is_dir($sourceDir)) {
            return;
        }

        $entries = $this->scandirPath($sourceDir);

        if ($entries === false) {
            throw new RuntimeException('Failed to read extracted Dart Sass directory');
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $source = $sourceDir . '/' . $entry;
            $target = $targetDir . '/' . basename((string) $entry);

            $this->removePath($target);

            if (! $this->renamePath($source, $target)) {
                throw new RuntimeException('Failed to move extracted file: ' . $entry);
            }
        }

        if (! $this->rmdirPath($sourceDir)) {
            throw new RuntimeException('Failed to remove temporary Dart Sass directory');
        }
    }

    protected function scandirPath(string $path): array|false
    {
        return scandir($path);
    }

    protected function renamePath(string $from, string $to): bool
    {
        return rename($from, $to);
    }

    protected function rmdirPath(string $path): bool
    {
        return rmdir($path);
    }

    protected function unlinkPath(string $path): bool
    {
        return unlink($path);
    }

    protected function removePath(string $path): void
    {
        if (is_file($path)) {
            if (! $this->unlinkPath($path)) {
                throw new RuntimeException('Failed to remove file: ' . $path);
            }

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        $entries = $this->scandirPath($path);

        if ($entries === false) {
            throw new RuntimeException('Failed to read directory: ' . $path);
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->removePath($path . '/' . $entry);
        }

        if (! $this->rmdirPath($path)) {
            throw new RuntimeException('Failed to remove directory: ' . $path);
        }
    }
}
