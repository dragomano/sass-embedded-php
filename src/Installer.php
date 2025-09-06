<?php declare(strict_types=1);

namespace Bugo\Sass;

use Symfony\Component\Process\Process;

use function is_dir;
use function is_file;
use function realpath;
use function strtoupper;
use function substr;

class Installer
{
    public static function postInstall(): void
    {
        $packageDir = realpath(__DIR__ . '/../');
        if ($packageDir === false || ! is_dir($packageDir)) {
            echo "[Installer] package directory not found, skipping npm install.\n";
            return;
        }

        $packageJson = $packageDir . '/package.json';
        if (! is_file($packageJson)) {
            echo "[Installer] package.json not found in $packageDir, skipping npm install.\n";
            return;
        }

        $npmBin = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? 'npm.cmd' : 'npm';

        $checkNpm = new Process([$npmBin, '--version']);
        $checkNpm->run();
        if (! $checkNpm->isSuccessful()) {
            echo "[Installer] npm not found in PATH, skipping npm install.\n";
            return;
        }

        $nodeModules = $packageDir . '/node_modules/sass-embedded';
        if (is_dir($nodeModules)) {
            echo "[Installer] node_modules/sass-embedded already exists, skipping npm install.\n";
            return;
        }

        echo "[Installer] running npm install in $packageDir ...\n";
        $process = new Process([$npmBin, 'install'], $packageDir);
        $process->setTimeout(null);
        $process->run(function ($type, $buffer): void { echo $buffer; });

        if ($process->isSuccessful()) {
            echo "[Installer] npm install completed successfully.\n";
        } else {
            echo "[Installer] npm install failed:\n" . $process->getErrorOutput() . "\n";
        }
    }
}
