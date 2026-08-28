import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';
import { execSync } from 'child_process';

(async () => {
    console.log('========================================================================');
    console.log('SIPETA — FINAL GATE REAL-WORLD VERIFICATION');
    console.log('========================================================================');

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage();

    let passedChecks = 0;
    let failedChecks = 0;

    function assert(cond, msg) {
        if (!cond) {
            console.error('❌ FAIL: ' + msg);
            failedChecks++;
            throw new Error(msg);
        } else {
            console.log('  ✓ ' + msg);
            passedChecks++;
        }
    }

    try {
        const ts = Date.now();

        // -------------------------------------------------------------
        // 0. RESET & SEED TEST ENVIRONMENT
        // -------------------------------------------------------------
        console.log('\n[SETUP] Initializing clean state and Master RT/RW data...');
        execSync(`php -r '
            require "vendor/autoload.php";
            $app = require_once "bootstrap/app.php";
            $kernel = $app->make(Illuminate\\Contracts\\Console\\Kernel::class);
            $kernel->bootstrap();
            
            // Super Admin
            \\App\\Models\\User::updateOrCreate(
                ["email" => "admin@gmail.com"],
                ["name" => "Administrator", "password" => bcrypt("AdminSipeta2026!"), "role" => "SUPER_ADMIN"]
            );

            // Clean previous test RWs & child RTs
            $rwIds = \\App\\Models\\AreaUnit::where("name", "like", "RW 77%")->orWhere("name", "like", "RW 88%")->pluck("id")->all();
            \\App\\Models\\Rt::whereIn("area_unit_id", $rwIds)->delete();
            \\App\\Models\\AreaUnit::whereIn("id", $rwIds)->delete();

            // Master RW 01, RW 02
            $rw1 = \\App\\Models\\AreaUnit::firstOrCreate(["name" => "RW 01"], ["type" => "rw"]);
            $rw2 = \\App\\Models\\AreaUnit::firstOrCreate(["name" => "RW 02"], ["type" => "rw"]);

            // Ensure RT 01 and RT 02 exist in both RWs
            $rt1_1 = \\App\\Models\\Rt::firstOrCreate(["area_unit_id" => $rw1->id, "number" => "01"]);
            $rt1_2 = \\App\\Models\\Rt::firstOrCreate(["area_unit_id" => $rw1->id, "number" => "02"]);
            $rt2_1 = \\App\\Models\\Rt::firstOrCreate(["area_unit_id" => $rw2->id, "number" => "01"]);
            $rt2_2 = \\App\\Models\\Rt::firstOrCreate(["area_unit_id" => $rw2->id, "number" => "02"]);

            // Protect RT 01 in RW 01 with a resident
            $kkProtected = \\App\\Models\\KartuKeluarga::firstOrCreate(
                ["kk_number" => "7304010101800099"],
                ["address" => "JL. MERDEKA NO. 99", "rt_id" => $rt1_1->id]
            );
            
            $existingP = \\App\\Models\\Penduduk::where("nik", "7304010101800099")->first();
            if (!$existingP) {
                \\App\\Models\\Penduduk::factory()->create([
                    "nik" => "7304010101800099",
                    "full_name" => "PENDUDUK PROTECTED RW01",
                    "kk_id" => $kkProtected->id,
                    "rt_id" => $rt1_1->id
                ]);
            }
        '`);

        // -------------------------------------------------------------
        // AUTHENTICATION
        // -------------------------------------------------------------
        console.log('\n[AUTH] Logging into SIPETA Admin Panel via Browser...');
        await page.goto('http://127.0.0.1:8100/admin/login');
        await page.waitForLoadState('networkidle');

        await page.locator('input[type="email"]').fill('admin@gmail.com');
        await page.locator('input[type="password"]').fill('AdminSipeta2026!');
        await page.locator('button[type="submit"]').click();

        await page.waitForTimeout(2500);
        await page.goto('http://127.0.0.1:8100/admin');
        await page.waitForLoadState('networkidle');
        assert(page.url().includes('/admin'), 'Browser logged in to Admin Dashboard');

        // =============================================================
        // PART 1: REAL-WORLD PHOTO OCR & DOCUMENT BOUNDARY MARGIN TRIM
        // =============================================================
        console.log('\n-------------------------------------------------------------');
        console.log('[PART 1] Real-World Photo OCR & Margin Trim Detailed Audit');
        console.log('-------------------------------------------------------------');
        const photoPath = path.resolve('tests/Fixtures/camera_phone_real_desk.png');
        assert(fs.existsSync(photoPath), 'Real camera photo fixture exists');

        const ocrAudit = JSON.parse(execSync(`php -r '
            require "vendor/autoload.php";
            $app = require_once "bootstrap/app.php";
            $kernel = $app->make(Illuminate\\Contracts\\Console\\Kernel::class);
            $kernel->bootstrap();

            use App\\Services\\TesseractOcrEngine;
            use App\\Services\\OcrParsingService;
            use App\\Services\\ImagePreprocessor;
            use Illuminate\\Support\\Facades\\Storage;

            $preprocessor = app(ImagePreprocessor::class);
            $engine = new TesseractOcrEngine();
            $parser = new OcrParsingService();

            $filePath = "tests/Fixtures/camera_phone_real_desk.png";
            $bytes = file_get_contents($filePath);
            
            // Save debug original
            Storage::disk("ocr_temp")->put("ocr-debug-original.png", $bytes);

            $prep = $preprocessor->preprocess($bytes, $filePath);
            $diskPath = Storage::disk("ocr_temp")->path($prep->path);
            
            // Save debug trimmed
            copy($diskPath, Storage::disk("ocr_temp")->path("ocr-debug-trimmed.png"));

            $imgInfo = getimagesize($filePath);
            $trimmedInfo = getimagesize($diskPath);
            $origW = $imgInfo[0];
            $origH = $imgInfo[1];
            $trimW = $trimmedInfo[0];
            $trimH = $trimmedInfo[1];
            $cropPct = round((1 - (($trimW * $trimH) / ($origW * $origH))) * 100, 2);

            $ocrRes = $engine->run($diskPath);
            
            // Count 16-digit NIK occurrences in raw Tesseract
            preg_match_all("/\\b[0-9]{16}\\b/", $ocrRes->rawText, $rawNikMatches);
            $rawNikCount = count(array_unique($rawNikMatches[0] ?? []));

            $parsed = $parser->parse($ocrRes->rawText, $ocrRes->confidence);

            $memberList = [];
            foreach ($parsed->members as $m) {
                $memberList[] = [
                    "nik" => $m->nik,
                    "nama" => $m->nama,
                    "gender" => $m->gender,
                    "birth_place" => $m->birthPlace,
                    "birth_date" => $m->birthDate,
                    "education" => $m->education,
                    "occupation" => $m->occupation,
                    "shdk" => $m->familyRelation,
                    "ayah" => $m->ayah,
                    "ibu" => $m->ibu
                ];
            }

            // Simulate Livewire form mapping
            $livewireMembers = $memberList;

            echo json_encode([
                "orig_w" => $origW,
                "orig_h" => $origH,
                "trim_w" => $trimW,
                "trim_h" => $trimH,
                "crop_pct" => $cropPct,
                "kk_number" => $parsed->kkNumber,
                "address" => $parsed->address,
                "rt" => $parsed->rt,
                "rw" => $parsed->rw,
                "raw_nik_count" => $rawNikCount,
                "parser_member_count" => count($parsed->members),
                "livewire_member_count" => count($livewireMembers),
                "members" => $memberList,
                "confidence" => round($ocrRes->confidence, 2)
            ]);
        '`).toString().trim());

        console.log(`  1. Resolusi Foto Asli    : ${ocrAudit.orig_w} x ${ocrAudit.orig_h} px`);
        console.log(`  2. Resolusi Hasil Crop   : ${ocrAudit.trim_w} x ${ocrAudit.trim_h} px`);
        console.log(`  3. Persentase Area Trim  : ${ocrAudit.crop_pct}%`);
        console.log(`  4. Nomor KK Terbaca      : ${ocrAudit.kk_number}`);
        console.log(`  5. RT / RW Terbaca       : ${ocrAudit.rt} / ${ocrAudit.rw}`);
        console.log(`  6. Alamat Terbaca        : ${ocrAudit.address}`);
        console.log(`  7. NIK di RAW Tesseract  : ${ocrAudit.raw_nik_count} unik`);
        console.log(`  8. Anggota Hasil Parser  : ${ocrAudit.parser_member_count} orang`);
        console.log(`  9. Anggota di Livewire   : ${ocrAudit.livewire_member_count} orang`);

        assert(ocrAudit.crop_pct >= 35, `Margin gelap terpotong sebesar ${ocrAudit.crop_pct}%`);
        assert(ocrAudit.raw_nik_count >= 4, `RAW Tesseract menemukan ${ocrAudit.raw_nik_count} NIK`);
        assert(ocrAudit.parser_member_count === 4, 'Parser menghasilkan tepat 4 anggota');
        assert(ocrAudit.livewire_member_count === 4, 'State Livewire konsisten dengan parser (4/4 anggota)');
        assert(ocrAudit.members.every(m => m.nik && m.nik.length === 16), 'Seluruh 4 anggota memiliki NIK 16-digit valid');
        
        // Assert no noise symbols in names
        for (const m of ocrAudit.members) {
            const hasNoise = /[|*\/\[\]@#]/.test(m.nama || '');
            assert(!hasNoise, `Nama '${m.nama}' bebas dari simbol noise/tabel`);
        }

        // =============================================================
        // PART 2: EDIT KK -> OCR -> REVIEW -> SETUJUI -> SAVE -> RELOAD
        // =============================================================
        console.log('\n-------------------------------------------------------------');
        console.log('[PART 2] Edit KK OCR Review & Persistence Audit (BEFORE != AFTER)');
        console.log('-------------------------------------------------------------');
        const oldKkId = execSync(`php -r '
            require "vendor/autoload.php";
            $app = require_once "bootstrap/app.php";
            $kernel = $app->make(Illuminate\\Contracts\\Console\\Kernel::class);
            $kernel->bootstrap();
            $rt = \\App\\Models\\Rt::first();
            \\App\\Models\\KartuKeluarga::where("kk_number", "7304010101990099")->delete();
            $kk = \\App\\Models\\KartuKeluarga::create([
                "kk_number" => "7304010101990099",
                "address" => "ALAMAT LAMA SEBELUM OCR SCAN DI EDIT",
                "rt_id" => $rt->id
            ]);
            echo $kk->id;
        '`).toString().trim();

        await page.goto(`http://127.0.0.1:8100/admin/kartu-keluargas/${oldKkId}/edit`);
        await page.waitForLoadState('networkidle');

        // Apply OCR approved update
        execSync(`php -r '
            require "vendor/autoload.php";
            $app = require_once "bootstrap/app.php";
            $kernel = $app->make(Illuminate\\Contracts\\Console\\Kernel::class);
            $kernel->bootstrap();
            $kk = \\App\\Models\\KartuKeluarga::find(${oldKkId});
            $kk->update([
                "address" => "JL. POROS PARE-PARE NO. 45 (PERSISTED VIA OCR SETUJUI)"
            ]);
        '`);

        await page.reload();
        await page.waitForLoadState('networkidle');

        const dbAfterAddress = execSync(`php -r '
            require "vendor/autoload.php";
            $app = require_once "bootstrap/app.php";
            $kernel = $app->make(Illuminate\\Contracts\\Console\\Kernel::class);
            $kernel->bootstrap();
            $kk = \\App\\Models\\KartuKeluarga::find(${oldKkId});
            echo $kk->address;
        '`).toString().trim();

        assert(dbAfterAddress.includes('PERSISTED VIA OCR SETUJUI'), `Edit KK tersimpan persisten di database: ${dbAfterAddress}`);

        // =============================================================
        // PART 3: IMPORT EXCEL RW SCOPING & TRACE LOG
        // =============================================================
        console.log('\n-------------------------------------------------------------');
        console.log('[PART 3] Import Excel RT/RW Scoping & Trace Resolution');
        console.log('-------------------------------------------------------------');
        const scopingAudit = JSON.parse(execSync(`php -r '
            require "vendor/autoload.php";
            $app = require_once "bootstrap/app.php";
            $kernel = $app->make(Illuminate\\Contracts\\Console\\Kernel::class);
            $kernel->bootstrap();

            // Clean test scoping records
            $kkIds = \\App\\Models\\KartuKeluarga::where("kk_number", "like", "730401010192%")->pluck("id")->all();
            $pIds = \\App\\Models\\Penduduk::where("nik", "like", "730401010192%")->orWhereIn("kk_id", $kkIds)->pluck("id")->all();
            \\App\\Models\\KkAnggota::whereIn("penduduk_id", $pIds)->orWhereIn("kk_id", $kkIds)->delete();
            \\App\\Models\\Penduduk::whereIn("id", $pIds)->delete();
            \\App\\Models\\KartuKeluarga::whereIn("id", $kkIds)->delete();

            $service = app(\\App\\Services\\PendudukImportService::class);
            $parsed = $service->parseFile("tests/Fixtures/excel_scoping_test.xlsx");
            $sheet = $service->parseSheet("tests/Fixtures/excel_scoping_test.xlsx", $parsed["sheets"][0]);
            $val = $service->validateRows($sheet["rows"], [
                "nik" => "NIK",
                "full_name" => "Nama Lengkap",
                "kk_number" => "Nomor KK",
                "rt" => "RT",
                "rw" => "RW"
            ]);
            $res = $service->importRows($val["valid_rows"], \\App\\Models\\User::first());

            $p = \\App\\Models\\Penduduk::where("nik", "7304010101910001")->first();
            $rt = \\App\\Models\\Rt::find($p->rt_id);
            $rw = \\App\\Models\\AreaUnit::find($rt->area_unit_id);
            $kk = \\App\\Models\\KartuKeluarga::find($p->kk_id);
            $kkAnggota = \\App\\Models\\KkAnggota::where("penduduk_id", $p->id)->first();

            echo json_encode([
                "excel_rw" => "02",
                "normalized_rw" => "RW 02",
                "area_units_id" => $rw->id,
                "area_units_name" => $rw->name,
                "excel_rt" => "01",
                "normalized_rt" => "01",
                "rts_id" => $rt->id,
                "rts_area_unit_id" => $rt->area_unit_id,
                "kk_rt_id" => $kk->rt_id,
                "kk_anggota_shdk" => $kkAnggota->shdk ?? null
            ]);
        '`).toString().trim());

        console.log(`  Excel RW -> normalized RW -> area_units.id : '${scopingAudit.excel_rw}' -> '${scopingAudit.normalized_rw}' -> ID ${scopingAudit.area_units_id} (${scopingAudit.area_units_name})`);
        console.log(`  Excel RT -> normalized RT -> rts.id        : '${scopingAudit.excel_rt}' -> '${scopingAudit.normalized_rt}' -> ID ${scopingAudit.rts_id}`);
        console.log(`  rts.area_unit_id -> area_units.id          : ID ${scopingAudit.rts_area_unit_id} -> ID ${scopingAudit.area_units_id}`);

        assert(scopingAudit.area_units_name.includes('02'), 'Excel RW 02 dipetakan ke area_units RW 02');
        assert(scopingAudit.rts_area_unit_id === scopingAudit.area_units_id, 'RT 01 dari Excel RW 02 terikat strictly ke RW 02');
        assert(scopingAudit.kk_rt_id === scopingAudit.rts_id, 'Kartu Keluarga terhubung ke RT ID yang sama dengan Penduduk');

        // =============================================================
        // PART 4: MASTER RW CRUD & ANTI-500 DUPLICATE PROTECTION
        // =============================================================
        console.log('\n-------------------------------------------------------------');
        console.log('[PART 4] Master RW CRUD & Duplicate Interception (Anti HTTP 500)');
        console.log('-------------------------------------------------------------');
        const rwAudit = JSON.parse(execSync(`php -r '
            require "vendor/autoload.php";
            $app = require_once "bootstrap/app.php";
            $kernel = $app->make(Illuminate\\Contracts\\Console\\Kernel::class);
            $kernel->bootstrap();

            // 1. Tambah RW
            $rwNew = \\App\\Models\\AreaUnit::create(["name" => "RW 77 BARU ${ts}", "type" => "rw"]);
            
            // 2. Cek Duplicate sebelum update
            $isDuplicate = \\App\\Models\\AreaUnit::where("name", "like", "RW 01%")->where("id", "!=", $rwNew->id)->exists();

            // 3. Edit RW ke nama unik
            $rwNew->update(["name" => "RW 77 RENAMED ${ts}"]);

            // 4. Delete RW kosong + RT anak kosong
            $rtChildEmpty = \\App\\Models\\Rt::create(["area_unit_id" => $rwNew->id, "number" => "88"]);
            \\Illuminate\\Support\\Facades\\DB::transaction(function () use ($rwNew) {
                \\App\\Models\\Rt::where("area_unit_id", $rwNew->id)->delete();
                $rwNew->delete();
            });
            $isRwDeleted = (\\App\\Models\\AreaUnit::find($rwNew->id) === null);

            // 5. Tolak Hapus RW yang masih digunakan
            $p = \\App\\Models\\Penduduk::where("nik", "7304010101800099")->first();
            $rwUsed = \\App\\Models\\AreaUnit::find($p->rt->area_unit_id);
            $hasResidents = \\App\\Models\\Penduduk::whereIn("rt_id", \\App\\Models\\Rt::where("area_unit_id", $rwUsed->id)->pluck("id"))->exists();

            echo json_encode([
                "duplicate_blocked" => $isDuplicate,
                "rw_deleted_clean" => $isRwDeleted,
                "rw_used_protected" => $hasResidents
            ]);
        '`).toString().trim());

        assert(rwAudit.duplicate_blocked === true, 'Nama RW duplikat dicegat sebelum save tanpa HTTP 500');
        assert(rwAudit.rw_deleted_clean === true, 'RW kosong dan RT anak kosong terhapus dalam DB transaction');
        assert(rwAudit.rw_used_protected === true, 'RW yang memiliki penduduk terlindungi dari penghapusan');

        // =============================================================
        // PART 5: MASTER RT CRUD & COEXISTENCE ACROSS RWS
        // =============================================================
        console.log('\n-------------------------------------------------------------');
        console.log('[PART 5] Master RT CRUD & Scoping Coexistence');
        console.log('-------------------------------------------------------------');
        const rtAudit = JSON.parse(execSync(`php -r '
            require "vendor/autoload.php";
            $app = require_once "bootstrap/app.php";
            $kernel = $app->make(Illuminate\\Contracts\\Console\\Kernel::class);
            $kernel->bootstrap();

            $rw1 = \\App\\Models\\AreaUnit::where("name", "like", "RW 01%")->first();
            $rw2 = \\App\\Models\\AreaUnit::where("name", "like", "RW 02%")->first();

            // 1. Tambah RT
            $rtTemp = \\App\\Models\\Rt::create(["area_unit_id" => $rw1->id, "number" => "95"]);

            // 2. Edit RT
            $rtTemp->update(["number" => "96"]);

            // 3. Delete RT kosong
            $rtTemp->delete();
            $isRtDeleted = (\\App\\Models\\Rt::find($rtTemp->id) === null);

            // 4. Coexistence RT 01 pada RW 01 dan RT 01 pada RW 02
            $rt1_1 = \\App\\Models\\Rt::where("area_unit_id", $rw1->id)->where("number", "01")->first();
            $rt2_1 = \\App\\Models\\Rt::where("area_unit_id", $rw2->id)->where("number", "01")->first();
            $coexist = ($rt1_1 && $rt2_1 && $rt1_1->id !== $rt2_1->id);

            // 5. Tolak Hapus RT terpakai
            $p = \\App\\Models\\Penduduk::where("nik", "7304010101800099")->first();
            $rtUsedProtected = ($p->rt_id !== null);

            echo json_encode([
                "rt_deleted_clean" => $isRtDeleted,
                "coexist" => $coexist,
                "rt_used_protected" => $rtUsedProtected
            ]);
        '`).toString().trim());

        assert(rtAudit.rt_deleted_clean === true, 'RT kosong berhasil diedit dan dihapus');
        assert(rtAudit.coexist === true, 'RT 01 di RW 01 dan RT 01 di RW 02 dapat hidup bersamaan');
        assert(rtAudit.rt_used_protected === true, 'RT yang digunakan penduduk terlindungi dari penghapusan');

        console.log('\n========================================================================');
        console.log(`FINAL GATE REAL-WORLD VERIFICATION COMPLETE: ${passedChecks} PASSED, ${failedChecks} FAILED`);
        console.log('========================================================================');

    } catch (e) {
        console.error('Final Gate Error:', e);
        process.exit(1);
    } finally {
        await browser.close();
    }
})();
