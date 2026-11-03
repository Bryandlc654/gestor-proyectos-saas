<?php

namespace Tests\Unit;

use App\Database\SqlSplitter;
use App\Support\Env;
use App\Support\Format;
use App\Support\Paginator;
use PHPUnit\Framework\TestCase;

final class SupportTest extends TestCase
{
    public function testPaginatorFirstPage(): void
    {
        $p = Paginator::paginate(134, 60, 1);
        $this->assertSame(['page' => 1, 'per_page' => 60, 'offset' => 0, 'total' => 134, 'total_pages' => 3], $p);
    }

    public function testPaginatorClampsOutOfRangePages(): void
    {
        $this->assertSame(3, Paginator::paginate(134, 60, 99)['page']);
        $this->assertSame(120, Paginator::paginate(134, 60, 99)['offset']);
        $this->assertSame(1, Paginator::paginate(134, 60, -5)['page']);
    }

    public function testPaginatorWithNoItemsHasOnePage(): void
    {
        $p = Paginator::paginate(0, 25, 4);
        $this->assertSame(1, $p['page']);
        $this->assertSame(1, $p['total_pages']);
        $this->assertSame(0, $p['offset']);
    }

    public function testFormatBytes(): void
    {
        $this->assertSame('0 B', Format::bytes(0));
        $this->assertSame('512 B', Format::bytes(512));
        $this->assertSame('1.5 KB', Format::bytes(1536));
        $this->assertSame('20 MB', Format::bytes(20 * 1024 * 1024));
    }

    public function testFormatDate(): void
    {
        $this->assertSame('Sin fecha', Format::date(null));
        $this->assertSame('05 Nov, 2026', Format::date('2026-11-05'));
        $this->assertSame('05 Nov, 2026 - 03:30 PM', Format::date('2026-11-05 15:30:00', true));
    }

    public function testSqlSplitterRespectsQuotesAndComments(): void
    {
        $sql = <<<SQL
        -- comentario con ; punto y coma
        CREATE TABLE a (id INT);
        INSERT INTO a VALUES ('texto; con punto y coma'), ('it\\'s');
        UPDATE `tabla;rara` SET x = "a;b";
        SQL;

        $statements = SqlSplitter::split($sql);

        $this->assertCount(3, $statements);
        $this->assertSame('CREATE TABLE a (id INT)', $statements[0]);
        $this->assertStringContainsString("'texto; con punto y coma'", $statements[1]);
        $this->assertStringContainsString('`tabla;rara`', $statements[2]);
    }

    public function testSqlSplitterIgnoresEmptyStatements(): void
    {
        $this->assertSame(['SELECT 1'], SqlSplitter::split(";;\n SELECT 1 ;\n-- fin\n"));
    }

    public function testEnvLoadsFileWithoutOverridingRealVariables(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, "# comentario\nFT_TEST_A=uno\nFT_TEST_B='con espacios y #'\nFT_TEST_REAL=archivo\nlinea invalida\n");
        putenv('FT_TEST_REAL=servidor');

        Env::load($file);
        unlink($file);

        $this->assertSame('uno', Env::get('FT_TEST_A'));
        $this->assertSame('con espacios y #', Env::get('FT_TEST_B'));
        $this->assertSame('servidor', Env::get('FT_TEST_REAL'));
        $this->assertSame('defecto', Env::get('FT_TEST_NO_EXISTE', 'defecto'));

        putenv('FT_TEST_A');
        putenv('FT_TEST_B');
        putenv('FT_TEST_REAL');
    }
}
