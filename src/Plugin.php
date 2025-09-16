<?php declare(strict_types=1);

namespace Bugo\Sass;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\Plugin\PluginInterface;
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

    private string $packagePath;

    private string $binDir;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $config = $composer->getConfig();

        $this->packagePath = realpath(__DIR__ . '/../');
        $this->binDir = (string) $config->get('bin-dir');
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    /* @uses onPostPackageUpdate */
    public static function getSubscribedEvents(): array
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'onPostPackageUpdate',
            PackageEvents::POST_PACKAGE_UPDATE  => 'onPostPackageUpdate',
        ];
    }

    public function onPostPackageUpdate(PackageEvent $event): void
    {
        static $alreadyRun = false;

        if ($alreadyRun) {
            return;
        }

        $alreadyRun = true;

        $this->installNpm($event->getIO());
        $this->copyBinary($event->getIO());
    }

    private function installNpm(IOInterface $io): void
    {
        if ($this->packagePath === '') {
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

        $checkNpm = new Process([$npmBin, '--version']);
        $checkNpm->run();
        if (! $checkNpm->isSuccessful()) {
            $io->write(sprintf(
                '<warning>[%s]</warning> npm not found in PATH, skipping npm install.',
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
        $process->setTimeout(null);
        $process->run(function ($type, $buffer): void { echo $buffer; });

        if ($process->isSuccessful()) {
            $io->write(sprintf(
                '<info>[%s]</info> npm install completed successfully.',
                self::PACKAGE_NAME
            ));

            return;
        }

        $io->write(sprintf('<error>[%s]</error> npm install failed:', self::PACKAGE_NAME));
        $io->write($process->getErrorOutput());
    }

    private function copyBinary(IOInterface $io): void
    {
        $source = $this->packagePath . '/bin/bridge.js';
        $target = $this->binDir . '/bridge.js';

        if (! is_file($source)) {
            return;
        }

        if (! is_dir($this->binDir)) {
            @mkdir($this->binDir, 0777, true);
        }

        if (@copy($source, $target)) {
            $io->write(sprintf('<info>[%s]</info> Binary overwritten: %s', self::PACKAGE_NAME, $target));
        } else {
            $io->writeError(sprintf('<error>[%s]</error> Failed to copy binary', self::PACKAGE_NAME));
        }
    }
}
