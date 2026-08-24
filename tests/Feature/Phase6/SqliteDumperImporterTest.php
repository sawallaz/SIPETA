<?php

namespace Tests\Feature\Phase6;

use App\Models\User;
use App\Services\SqliteDatabaseDumper;
use App\Services\SqliteDatabaseImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SqliteDumperImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_sqlite_dumper_produces_valid_sql(): void
    {
        $dumper = new SqliteDatabaseDumper;
        $sql = $dumper->dump();

        $this->assertNotEmpty($sql);
        $this->assertStringContainsString('PRAGMA foreign_keys = OFF;', $sql);
        $this->assertStringContainsString('PRAGMA foreign_keys = ON;', $sql);
        $this->assertStringContainsString('CREATE TABLE', $sql);
    }

    public function test_sqlite_importer_applies_sql_dump_correctly(): void
    {
        User::factory()->create([
            'email' => 'test_importer@sipeta.test',
        ]);

        $dumper = new SqliteDatabaseDumper;
        $dumpSql = $dumper->dump();

        // Wipe and reimport
        $importer = new SqliteDatabaseImporter;
        $importer->apply($dumpSql);

        $this->assertDatabaseHas('users', [
            'email' => 'test_importer@sipeta.test',
        ]);
    }
}
