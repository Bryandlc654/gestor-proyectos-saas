<?php

namespace Tests\Unit;

use App\Support\AvatarLibrary;
use PHPUnit\Framework\TestCase;

class AvatarLibraryTest extends TestCase
{
    public function testCatalogHasMenAndWomen(): void
    {
        $this->assertCount(16, AvatarLibrary::all());
        $this->assertCount(8, AvatarLibrary::men());
        $this->assertCount(8, AvatarLibrary::women());
    }

    public function testEveryCatalogFileExists(): void
    {
        foreach (array_keys(AvatarLibrary::all()) as $key) {
            $path = AvatarLibrary::path($key);
            $this->assertNotNull($path, "Falta el avatar {$key}");
            $full = dirname(__DIR__, 2) . '/' . $path;
            $this->assertFileExists($full);
            $this->assertStringStartsWith('assets/avatars/', $path);
            $this->assertSame('image/svg+xml', mime_content_type($full));

            $svg = simplexml_load_file($full);
            $this->assertNotFalse($svg, "El SVG {$key} no es XML válido");
            $this->assertSame('svg', $svg->getName());
            $this->assertSame('0 0 64 64', (string)$svg['viewBox']);
        }
    }

    public function testUnknownKeysAreRejected(): void
    {
        $this->assertNull(AvatarLibrary::path('hombre-99'));
        $this->assertNull(AvatarLibrary::path('../../.env'));
        $this->assertNull(AvatarLibrary::path(''));
        $this->assertFalse(AvatarLibrary::isPreset('uploads/avatars/user.png'));
        $this->assertFalse(AvatarLibrary::isPreset(null));
    }

    public function testPresetDetection(): void
    {
        $this->assertTrue(AvatarLibrary::isPreset('assets/avatars/mujer-3.svg'));
        $this->assertFalse(AvatarLibrary::isPreset('assets/avatars/otro.svg'));
    }
}
