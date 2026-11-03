<?php

namespace Tests\Unit;

use App\Upload\AttachmentStorage;
use PHPUnit\Framework\TestCase;

final class AttachmentStorageTest extends TestCase
{
    private AttachmentStorage $storage;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->storage = new AttachmentStorage(sys_get_temp_dir() . '/ft-uploads-test');
    }

    protected function tearDown(): void
    {
        array_map('unlink', $this->tempFiles);
    }

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($path, $content);
        return $this->tempFiles[] = $path;
    }

    public function testAcceptsWhitelistedDocument(): void
    {
        $path = $this->tempFile("Informe del proyecto\nLínea 2");
        $this->assertNull($this->storage->validate('informe.txt', filesize($path), $path));
    }

    public function testRejectsScriptExtensions(): void
    {
        $path = $this->tempFile('<?php echo 1;');
        foreach (['shell.php', 'x.phtml', 'x.phar', 'pagina.html', 'logo.svg', 'run.exe', 'script.js'] as $name) {
            $this->assertSame('Tipo de archivo no permitido.', $this->storage->validate($name, 10, $path), $name);
        }
    }

    public function testRejectsHtmlDisguisedAsAllowedExtension(): void
    {
        $path = $this->tempFile('<!DOCTYPE html><html><body><script>alert(1)</script></body></html>');
        $this->assertSame('El contenido del archivo no está permitido.', $this->storage->validate('factura.pdf', filesize($path), $path));
    }

    public function testRejectsOversizedFiles(): void
    {
        $path = $this->tempFile('x');
        $error = $this->storage->validate('a.txt', 2048, $path, 1024);
        $this->assertSame('El archivo no debe superar los 1 KB.', $error);
    }

    public function testStoreRejectsFilesNotUploadedViaHttp(): void
    {
        $path = $this->tempFile('contenido');
        $result = $this->storage->store(['name' => 'a.txt', 'tmp_name' => $path, 'size' => 9, 'error' => UPLOAD_ERR_OK]);
        $this->assertFalse($result['ok']);
    }

    public function testDetectsRealImageMime(): void
    {
        // PNG mínimo válido de 1x1
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $path = $this->tempFile($png);
        $this->assertSame('image/png', AttachmentStorage::detectMime($path));
        $this->assertArrayHasKey('image/png', AttachmentStorage::IMAGE_MIMES);
        $this->assertArrayNotHasKey('image/svg+xml', AttachmentStorage::IMAGE_MIMES);
    }
}
