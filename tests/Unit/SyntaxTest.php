<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Comprueba la sintaxis de todos los PHP del proyecto, incluidos los que los tests no cargan
 * (páginas, parciales o el autoloader de respaldo que solo se usa en producción sin vendor/).
 */
final class SyntaxTest extends TestCase
{
    public static function phpFiles(): iterable
    {
        $root = dirname(__DIR__, 2);
        $skip = '#[\\\\/](vendor|node_modules|\.git)[\\\\/]#';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = $file->getPathname();
            if ($file->getExtension() === 'php' && !preg_match($skip, $path)) {
                yield substr($path, strlen($root) + 1) => [$path];
            }
        }
    }

    #[DataProvider('phpFiles')]
    public function testParses(string $path): void
    {
        try {
            token_get_all(file_get_contents($path), TOKEN_PARSE);
        } catch (\ParseError $e) {
            $this->fail($e->getMessage() . ' (línea ' . $e->getLine() . ')');
        }
        $this->addToAssertionCount(1);
    }
}
