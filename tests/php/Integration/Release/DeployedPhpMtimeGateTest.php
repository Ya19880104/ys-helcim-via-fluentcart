<?php

declare(strict_types=1);

namespace YangSheep\Helcim\FluentCart\Tests\Integration\Release;

use PHPUnit\Framework\TestCase;

final class DeployedPhpMtimeGateTest extends TestCase
{
    private string $runtimeDirectory;

    protected function setUp(): void
    {
        $this->runtimeDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'ys-helcim-mtime-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->runtimeDirectory, 0700, true));
        self::assertNotFalse(file_put_contents(
            $this->runtimeDirectory . DIRECTORY_SEPARATOR . 'runtime.php',
            "<?php\n"
        ));
    }

    protected function tearDown(): void
    {
        foreach (['linked-runtime.php', 'linked-directory'] as $link) {
            $path = $this->runtimeDirectory . DIRECTORY_SEPARATOR . $link;
            if (is_link($path)) {
                unlink($path);
            }
        }
        $nested = $this->runtimeDirectory . DIRECTORY_SEPARATOR . 'real-directory'
            . DIRECTORY_SEPARATOR . 'nested.php';
        if (is_file($nested)) {
            unlink($nested);
        }
        $realDirectory = dirname($nested);
        if (is_dir($realDirectory)) {
            rmdir($realDirectory);
        }
        $runtime = $this->runtimeDirectory . DIRECTORY_SEPARATOR . 'runtime.php';
        if (is_file($runtime)) {
            unlink($runtime);
        }
        if (is_dir($this->runtimeDirectory)) {
            rmdir($this->runtimeDirectory);
        }
    }

    public function testGateRejectsDeterministicArchiveMtimeAndAcceptsTouchedRuntime(): void
    {
        $runtime = $this->runtimeDirectory . DIRECTORY_SEPARATOR . 'runtime.php';
        $minimum = time() - 5;
        self::assertTrue(touch($runtime, strtotime('1980-01-01 00:00:00 UTC')));

        $stale = $this->runGate($minimum);

        self::assertSame(1, $stale['exit_code']);
        self::assertStringContainsString('php_files=1', $stale['output']);
        self::assertStringContainsString('stale=1', $stale['output']);

        self::assertTrue(touch($runtime, $minimum));
        clearstatcache(true, $runtime);
        $fresh = $this->runGate($minimum);

        self::assertSame(0, $fresh['exit_code']);
        self::assertStringContainsString('php_files=1', $fresh['output']);
        self::assertStringContainsString('stale=0', $fresh['output']);
    }

    public function testGateRejectsPhpFileSymlink(): void
    {
        $link = $this->runtimeDirectory . DIRECTORY_SEPARATOR . 'linked-runtime.php';
        if (!@symlink($this->runtimeDirectory . DIRECTORY_SEPARATOR . 'runtime.php', $link)) {
            self::markTestSkipped('This platform does not permit creating file symlinks.');
        }

        $result = $this->runGate(time() - 5);

        self::assertSame(1, $result['exit_code']);
        self::assertStringContainsString('unsafe_links=1', $result['output']);
        self::assertStringContainsString('UNSAFE_LINK linked-runtime.php', $result['output']);
    }

    public function testGateRejectsSymlinkedDirectory(): void
    {
        $realDirectory = $this->runtimeDirectory . DIRECTORY_SEPARATOR . 'real-directory';
        self::assertTrue(mkdir($realDirectory, 0700));
        self::assertNotFalse(file_put_contents($realDirectory . DIRECTORY_SEPARATOR . 'nested.php', "<?php\n"));
        $link = $this->runtimeDirectory . DIRECTORY_SEPARATOR . 'linked-directory';
        if (!@symlink($realDirectory, $link)) {
            self::markTestSkipped('This platform does not permit creating directory symlinks.');
        }

        $result = $this->runGate(time() - 5);

        self::assertSame(1, $result['exit_code']);
        self::assertStringContainsString('unsafe_links=1', $result['output']);
        self::assertStringContainsString('UNSAFE_LINK linked-directory', $result['output']);
    }

    /** @return array{exit_code:int,output:string} */
    private function runGate(int $minimumEpoch): array
    {
        $script = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR
            . 'scripts' . DIRECTORY_SEPARATOR . 'verify-deployed-php-mtime.php';
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $script, $this->runtimeDirectory, (string) $minimumEpoch],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return ['exit_code' => $exitCode, 'output' => $output];
    }
}
