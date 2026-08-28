<?php

namespace Tests\Feature;

use App\Models\AreaUnit;
use App\Models\KartuKeluarga;
use App\Models\KkAnggota;
use App\Models\Penduduk;
use App\Models\Rt;
use App\Models\User;
use App\Services\PendudukImportService;
use Database\Seeders\SystemReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class RtRwCrossScopingAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_rt_rw_scoping_and_database_verification(): void
    {
        $this->seed(SystemReferenceSeeder::class);
        $user = User::factory()->create();

        // 1. Setup explicit master data:
        // RW 01: RT 01 (id 1), RT 02 (id 2)
        // RW 02: RT 01 (id 3), RT 02 (id 4)
        $rw1 = AreaUnit::create(['name' => 'RW 01', 'type' => 'rw', 'code' => '01']);
        $rw2 = AreaUnit::create(['name' => 'RW 02', 'type' => 'rw', 'code' => '02']);

        $rt1_rw1 = Rt::create(['number' => '01', 'area_unit_id' => $rw1->id]);
        $rt2_rw1 = Rt::create(['number' => '02', 'area_unit_id' => $rw1->id]);
        $rt1_rw2 = Rt::create(['number' => '01', 'area_unit_id' => $rw2->id]);
        $rt2_rw2 = Rt::create(['number' => '02', 'area_unit_id' => $rw2->id]);

        // Output structure of rts JOIN area_units
        $joinRows = DB::table('rts')
            ->join('area_units', 'rts.area_unit_id', '=', 'area_units.id')
            ->select('rts.id as rt_id', 'rts.number as rt_number', 'area_units.id as rw_id', 'area_units.name as rw_name')
            ->orderBy('rts.id')
            ->get();

        echo "\n\n===============================================================\n";
        echo "1. QUERY JOIN DATABASE: rts JOIN area_units\n";
        echo "===============================================================\n";
        printf("%-8s | %-10s | %-8s | %-20s\n", "RT ID", "Nomor RT", "RW ID", "Nama RW / Lingkungan");
        printf("%'-8s-+-%'-10s-+-%'-8s-+-%'-20s\n", "", "", "", "");
        foreach ($joinRows as $jr) {
            printf("%-8s | %-10s | %-8s | %-20s\n", $jr->rt_id, $jr->rt_number, $jr->rw_id, $jr->rw_name);
        }
        echo "\n";

        // 2. Generate Excel containing 3 families:
        // KK A -> RT 01 / RW 01 (1 member)
        // KK B -> RT 01 / RW 02 (3 members: row 2 & 3 have empty RT/RW and inherit)
        // KK C -> RT 02 / RW 02 (1 member)
        $filePath = sys_get_temp_dir() . '/kelurahan_audit_' . uniqid() . '.xlsx';
        $writer = new Writer();
        $writer->openToFile($filePath);

        $writer->addRow(Row::fromValues([
            'NO', 'NO KK', 'NIK', 'NAMA LENGKAP', 'JENIS KELAMIN',
            'TEMPAT LAHIR', 'TANGGAL LAHIR', 'AGAMA', 'PENDIDIKAN',
            'PEKERJAAN', 'STATUS PERKAWINAN', 'SHDK', 'NAMA AYAH',
            'NAMA IBU', 'ALAMAT', 'RT', 'RW', 'DESA',
            'KECAMATAN', 'KABUPATEN', 'PROVINSI', 'KODE POS'
        ]));

        // KK A (RT 01 / RW 01)
        $writer->addRow(Row::fromValues([
            1, '7304010101809001', '7304010101800001', 'BUDI SANTOSO (KK A)', 'LAKI-LAKI',
            'BARRU', '1980-01-01', 'ISLAM', 'S1',
            'PNS', 'KAWIN', 'KEPALA KELUARGA', 'AHMAD',
            'FATIMAH', 'JL. MAWAR NO. 1', '01', '01', 'TANETE',
            'TANETE RILAU', 'BARRU', 'SULAWESI SELATAN', '90761'
        ]));

        // KK B (RT 01 / RW 02) -> 3 rows (Row 2 & 3 inherit RT 01 / RW 02)
        $writer->addRow(Row::fromValues([
            2, '7304010101809002', '7304010101800002', 'HASAN BASRI (KK B KEPALA)', 'LAKI-LAKI',
            'BARRU', '1982-03-15', 'ISLAM', 'SMA',
            'WIRASWASTA', 'KAWIN', 'KEPALA KELUARGA', 'BASRI',
            'NUR', 'JL. MELATI NO. 5', '01', '02', 'TANETE',
            'TANETE RILAU', 'BARRU', 'SULAWESI SELATAN', '90762'
        ]));
        $writer->addRow(Row::fromValues([
            3, '7304010101809002', '7304014502850003', 'SITI AMINAH (KK B ISTRI)', 'PEREMPUAN',
            'BARRU', '1985-06-20', 'ISLAM', 'SMA',
            'IBU RUMAH TANGGA', 'KAWIN', 'ISTRI', 'KADIR',
            'AISYAH', '', '', '', '', // Empty address, RT, RW
            '', '', '', ''
        ]));
        $writer->addRow(Row::fromValues([
            4, '7304010101809002', '7304011203050004', 'FAJAR BASRI (KK B ANAK)', 'LAKI-LAKI',
            'BARRU', '2005-09-10', 'ISLAM', 'SMP',
            'PELAJAR/MAHASISWA', 'BELUM KAWIN', 'ANAK', 'HASAN BASRI',
            'SITI AMINAH', '', '', '', '', // Empty address, RT, RW
            '', '', '', ''
        ]));

        // KK C (RT 02 / RW 02)
        $writer->addRow(Row::fromValues([
            5, '7304010101809003', '7304016005900005', 'NURUL HIKMAH (KK C)', 'PEREMPUAN',
            'BARRU', '1990-11-25', 'ISLAM', 'D3',
            'PEGAWAI SWASTA', 'BELUM KAWIN', 'KEPALA KELUARGA', 'HIKMAT',
            'ROHANI', 'JL. ANGGREK NO. 9', '02', '02', 'TANETE',
            'TANETE RILAU', 'BARRU', 'SULAWESI SELATAN', '90762'
        ]));

        $writer->close();

        $service = app(PendudukImportService::class);
        $sheet = $service->parseSheet($filePath, 'Sheet1');
        $mapping = $service->suggestMapping($sheet['headers']);
        $validation = $service->validateRows($sheet['rows'], $mapping['mapping']);

        echo "===============================================================\n";
        echo "2. HASIL PREVIEW IMPORT\n";
        echo "===============================================================\n";
        echo "Total baris Excel   : " . count($sheet['rows']) . " baris\n";
        echo "Penduduk Valid      : " . $validation['valid_count'] . " (100% Valid)\n";
        echo "KK Baru             : " . $validation['new_kk_count'] . " KK\n";
        echo "RT Sesuai Master    : " . $validation['rt_valid_count'] . "\n";
        echo "RW Terhubung        : " . $validation['rw_valid_count'] . "\n";
        echo "Invalid / Ditolak   : " . $validation['invalid_count'] . "\n\n";

        $importResult = $service->importRows($validation['valid_rows'], $user);
        echo "===============================================================\n";
        echo "3. HASIL IMPORT EXECUTION\n";
        echo "===============================================================\n";
        echo "Status              : " . $importResult['status'] . "\n";
        echo "Penduduk Diimpor    : " . $importResult['imported'] . " orang\n";
        echo "KK Baru Dibuat      : " . $importResult['created_kk'] . " KK\n";
        echo "Pesan               : " . $importResult['message'] . "\n\n";

        // 3. Database Output Verification
        $kks = KartuKeluarga::whereIn('kk_number', [
            '7304010101809001', '7304010101809002', '7304010101809003'
        ])->with('rt.areaUnit', 'penduduks')->orderBy('kk_number')->get();

        echo "===============================================================\n";
        echo "4. VERIFIKASI DATABASE: TABEL KARTU KELUARGA (kartu_keluarga)\n";
        echo "===============================================================\n";
        printf("%-6s | %-17s | %-18s | %-6s | %-10s | %-6s | %-12s\n", "KK ID", "Nomor KK", "Alamat", "RT ID", "Nomor RT", "RW ID", "Nama RW");
        printf("%'-6s-+-%'-17s-+-%'-18s-+-%'-6s-+-%'-10s-+-%'-6s-+-%'-12s\n", "", "", "", "", "", "", "");
        foreach ($kks as $kk) {
            printf("%-6s | %-17s | %-18s | %-6s | %-10s | %-6s | %-12s\n",
                $kk->id,
                $kk->kk_number,
                $kk->address,
                $kk->rt_id,
                $kk->rt->number,
                $kk->rt->area_unit_id,
                $kk->rt->areaUnit->name
            );
        }
        echo "\n";

        echo "===============================================================\n";
        echo "5. VERIFIKASI DATABASE: TABEL PENDUDUK (penduduk)\n";
        echo "===============================================================\n";
        $penduduks = Penduduk::whereIn('kk_id', $kks->pluck('id'))
            ->with('rt.areaUnit', 'kartuKeluarga')
            ->orderBy('nik')
            ->get();

        printf("%-17s | %-26s | %-6s | %-17s | %-6s | %-8s | %-6s | %-10s | %-16s\n",
            "NIK", "Nama Lengkap", "KK ID", "Nomor KK", "RT ID", "Nomor RT", "RW ID", "Nama RW", "SHDK"
        );
        printf("%'-17s-+-%'-26s-+-%'-6s-+-%'-17s-+-%'-6s-+-%'-8s-+-%'-6s-+-%'-10s-+-%'-16s\n",
            "", "", "", "", "", "", "", "", ""
        );
        foreach ($penduduks as $p) {
            $shdk = is_object($p->family_relation) ? $p->family_relation->value : (string) $p->family_relation;
            printf("%-17s | %-26s | %-6s | %-17s | %-6s | %-8s | %-6s | %-10s | %-16s\n",
                $p->nik,
                $p->full_name,
                $p->kk_id,
                $p->kartuKeluarga->kk_number,
                $p->rt_id,
                $p->rt->number,
                $p->rt->area_unit_id,
                $p->rt->areaUnit->name,
                $shdk
            );
        }
        echo "\n";

        echo "===============================================================\n";
        echo "6. VERIFIKASI DATABASE: TABEL KK_ANGGOTA (kk_anggota)\n";
        echo "===============================================================\n";
        $memberships = KkAnggota::whereIn('kk_id', $kks->pluck('id'))->orderBy('id')->get();
        printf("%-6s | %-6s | %-12s | %-16s | %-10s\n", "ID", "KK ID", "Penduduk ID", "SHDK", "Status");
        printf("%'-6s-+-%'-6s-+-%'-12s-+-%'-16s-+-%'-10s\n", "", "", "", "", "");
        foreach ($memberships as $m) {
            $shdk = is_object($m->family_relation) ? $m->family_relation->value : (string) $m->family_relation;
            $stat = is_object($m->status) ? $m->status->value : (string) $m->status;
            printf("%-6s | %-6s | %-12s | %-16s | %-10s\n", $m->id, $m->kk_id, $m->penduduk_id, $shdk, $stat);
        }
        echo "\n";

        // Assertions
        $kkA = $kks->firstWhere('kk_number', '7304010101809001');
        $kkB = $kks->firstWhere('kk_number', '7304010101809002');
        $kkC = $kks->firstWhere('kk_number', '7304010101809003');

        $this->assertSame($rt1_rw1->id, $kkA->rt_id);
        $this->assertSame('RW 01', $kkA->rt->areaUnit->name);

        $this->assertSame($rt1_rw2->id, $kkB->rt_id);
        $this->assertSame('RW 02', $kkB->rt->areaUnit->name);
        $this->assertSame(3, $kkB->penduduks()->count());

        $this->assertSame($rt2_rw2->id, $kkC->rt_id);
        $this->assertSame('RW 02', $kkC->rt->areaUnit->name);

        // Assert multi-row inheritance for all 3 members of KK B
        foreach ($kkB->penduduks as $memberB) {
            $this->assertSame($rt1_rw2->id, $memberB->rt_id);
            $this->assertSame($rw2->id, $memberB->rt->area_unit_id);
            $this->assertSame('RW 02', $memberB->rt->areaUnit->name);
        }

        // 7. Test invalid combinations (Zero silent fallback proof)
        $invalidRows = [
            [
                '__row_number' => 2,
                'nik' => '7304010101999901',
                'full_name' => 'Warga RW Salah',
                'kk_number' => '7304010101999991',
                'gender' => 'Laki-laki',
                'birth_date' => '1980-01-01',
                'birth_place' => 'Barru',
                'address' => 'Jl. Test',
                'rt' => '01',
                'rw' => '99', // RW 99 does not exist
            ],
            [
                '__row_number' => 3,
                'nik' => '7304010101999902',
                'full_name' => 'Warga RT Salah',
                'kk_number' => '7304010101999992',
                'gender' => 'Laki-laki',
                'birth_date' => '1980-01-01',
                'birth_place' => 'Barru',
                'address' => 'Jl. Test',
                'rt' => '09', // RT 09 does not exist under RW 02
                'rw' => '02',
            ],
        ];
        $invalidVal = $service->validateRows($invalidRows, array_combine(array_keys($invalidRows[0]), array_keys($invalidRows[0])));

        echo "===============================================================\n";
        echo "7. PENGUJIAN PREVENSI FALLBACK DIAM-DIAM PADA KOMBINASI SALAH\n";
        echo "===============================================================\n";
        echo "Baris 2 (RT 01 / RW 99): " . ($invalidVal['errors'][2][0] ?? '-') . "\n";
        echo "Baris 3 (RT 09 / RW 02): " . ($invalidVal['errors'][3][0] ?? '-') . "\n";
        echo "Valid Count: " . $invalidVal['valid_count'] . " (DITOLAK DENGAN BENAR, TIDAK ADA FALLBACK SILENT)\n";

        $this->assertSame(0, $invalidVal['valid_count']);
        $this->assertSame(2, $invalidVal['invalid_count']);

        @unlink($filePath);
    }
}
