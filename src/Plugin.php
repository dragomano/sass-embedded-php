<?php declare(strict_types=1);

namespace Bugo\Sass;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Installer\PackageEvents;
use RuntimeException;
use Symfony\Component\Process\Process;

use function copy;
use function is_dir;
use function is_file;
use function mkdir;
use function realpath;
use function sprintf;
use function strtoupper;
use function substr;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    private const PACKAGE_NAME = 'sass-embedded-php';

    private string $packagePath = '';

    private string $binDir = '';

    public function activate(Composer $composer, IOInterface $io): void
    {
        $config = $composer->getConfig();

        $this->packagePath = (string) realpath(__DIR__ . '/../');
        $this->binDir = (string) $config->get('bin-dir');
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

    private function ensureInitializedFromComposer(?Composer $composer): void
    {
        if ($this->packagePath === '') {
            $this->packagePath = (string) realpath(__DIR__ . '/../');
        }

        if ($composer !== null && $this->binDir === '') {
            $this->binDir = (string) $composer->getConfig()->get('bin-dir');
        }
    }

    private function runInstall(IOInterface $io): void
    {
        static $alreadyRun = false;

        if ($alreadyRun) {
            return;
        }

        $alreadyRun = true;

        $this->installNpm($io);
        $this->copyBinary($io);
    }

    private function installNpm(IOInterface $io): void
    {
        if ($this->packagePath === '' || ! is_dir($this->packagePath)) {
            $io->write(sprintf(
                '<warning>[%s]</warning> package directory not found, skipping npm install.',
                self::PACKAGE_NAME
            ));
            return;
        }

        $packageJson = $this->packagePath . '/package.json';
        if (! is_file($packageJson)) {
            $io->write(sprintf(
                '<warning>[%s]</warning> package.json not found in %s, skipping npm install.',
                self::PACKAGE_NAME,
                $this->packagePath
            ));
            return;
        }

        $npmBin = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? 'npm.cmd' : 'npm';

        $check = new Process([$npmBin, '--version']);
        $check->setTimeout(10);

        try {
            $check->run();
        } catch (RuntimeException $runtimeException) {
            $io->write(sprintf(
                '<warning>[%s]</warning> failed to execute "%s --version": %s',
                self::PACKAGE_NAME,
                $npmBin,
                $runtimeException->getMessage()
            ));
            return;
        }

        if (! $check->isSuccessful()) {
            $io->write(sprintf(
                '<warning>[%s]</warning> npm not found in PATH or not executable, skipping npm install.',
                self::PACKAGE_NAME
            ));
            return;
        }

        $nodeModules = $this->packagePath . '/node_modules/sass-embedded';
        if (is_dir($nodeModules)) {
            $io->write(sprintf(
                '<info>[%s]</info> node_modules/sass-embedded already exists, skipping npm install.',
                self::PACKAGE_NAME
            ));
            return;
        }

        $process = new Process([$npmBin, 'install'], $this->packagePath);
        $process->setTimeout(300);

        $io->write(sprintf(
            '<info>[%s]</info> running "npm install" in %s',
            self::PACKAGE_NAME, $this->packagePath
        ));

        $process->run(function (string $type, string $buffer) use ($io): void {
            $io->write($buffer);
        });

        if ($process->isSuccessful()) {
            $io->write(sprintf(
                '<info>[%s]</info> npm install completed successfully.',
                self::PACKAGE_NAME
            ));
            return;
        }

        $io->write(sprintf(
            '<error>[%s]</error> npm install failed:',
            self::PACKAGE_NAME
        ));

        $io->write($process->getErrorOutput());
    }

    private function copyBinary(IOInterface $io): void
    {
        $source = $this->packagePath . '/bin/bridge.js';
        $targetDir = rtrim($this->binDir, '/\\');
        $target = $targetDir . DIRECTORY_SEPARATOR . 'bridge.js';

        if (! is_file($source)) {
            $io->write(sprintf(
                '<warning>[%s]</warning> binary not found in package (%s), skipping copy.',
                self::PACKAGE_NAME,
                $source
            ));
            return;
        }

        if (! is_dir($this->binDir)) {
            @mkdir($this->binDir, 0777, true);
        }

        if (@copy($source, $target)) {
            $io->write(sprintf(
                '<info>[%s]</info> Binary copied: %s',
                self::PACKAGE_NAME,
                $target
            ));
        } else {
            $io->write(sprintf(
                '<error>[%s]</error> Failed to copy binary to %s',
                self::PACKAGE_NAME,
                $target
            ));
        }
    }
}
