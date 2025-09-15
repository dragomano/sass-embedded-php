<?php declare(strict_types=1);

namespace Bugo\Sass;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    public function activate(Composer $composer, IOInterface $io): void
    {
        $io->write('This is the way.');
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        $io->write('May the Force be with you.');
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        $io->write('I\'ll see you again. I promise ©️');
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'onPostPackageInstall',
        ];
    }

    public function onPostPackageInstall(): void
    {
        Installer::postInstall();
    }
}
