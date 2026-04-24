<?php

declare(strict_types=1);

use Bugo\Sass\Plugin;
use Composer\Composer;
use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Script\Event;

beforeEach(function () {
    $this->composer = Mockery::mock(Composer::class);
    $this->io       = Mockery::mock(IOInterface::class);
    $this->config   = Mockery::mock(Config::class);
});

afterEach(function () {
    Mockery::close();
});

it('installs Sass when bin directory does not exist', function () {
    $binDir    = __DIR__ . '/../../bin';
    $backupDir = __DIR__ . '/../../bin_backup_test';

    if (is_dir($binDir)) {
        rename($binDir, $backupDir);
    }

    try {
        $scriptEvent = Mockery::mock(Event::class);

        $this->config->shouldReceive('get')
            ->with('bin-dir')
            ->andReturn('vendor/bin');

        $this->composer->shouldReceive('getConfig')
            ->andReturn($this->config);

        $scriptEvent->shouldReceive('getComposer')
            ->andReturn($this->composer);

        $scriptEvent->shouldReceive('getIO')
            ->andReturn($this->io);

        $this->io->shouldReceive('write')->atLeast()->once();

        Plugin::installBinary($scriptEvent);

        expect(is_dir($binDir))->toBeTrue();

        if (PHP_OS_FAMILY === 'Windows') {
            expect(is_file($binDir . '/sass.bat'))->toBeTrue();
        } else {
            expect(is_file($binDir . '/sass'))->toBeTrue();
        }
    } finally {
        if (is_dir($binDir)) {
            $cleanup = function ($path) use (&$cleanup) {
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
                    $cleanup($path . '/' . $entry);
                }

                rmdir($path);
            };

            $cleanup($binDir);
        }

        if (is_dir($backupDir)) {
            rename($backupDir, $binDir);
        }
    }
})->group('integration');
