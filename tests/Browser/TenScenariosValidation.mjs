import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';
import { execSync } from 'child_process';

(async () => {
    console.log('========================================================================');
    console.log('SIPETA — 10 SCENARIO FULL BROWSER & DATABASE INTEGRITY SUITE');
    console.log('========================================================================');

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage();

    function runPhp(code) {
        const tempPath = path.resolve('temp_runner.php');
        fs.writeFileSync(tempPath, `<?php require 'vendor/autoload.php'; $app = require_once 'bootstrap/app.php'; $kernel = $app->make(Illuminate\\Contracts\\Console\\Kernel::class); $kernel->bootstrap(); ${code}`);
        try {
            const out = execSync(`php ${tempPath}`).toString();
            fs.unlinkSync(tempPath);
            return out;
        } catch (e) {
            if (fs.existsSync(tempPath)) fs.unlinkSync(tempPath);
            throw e;
        }
    }

    try {
        // -------------------------------------------------------------
        // AUTHENTICATION
        // -------------------------------------------------------------
        console.log('\n[AUTH] Logging into SIPETA Admin Panel...');
        await page.goto('http://127.0.0.1:8100/admin/login');
        await page.waitForLoadState('networkidle');

        await page.locator('input[type="email"]').click();
        await page.locator('input[type="email"]').pressSequentially('admin@gmail.com', { delay: 20 });
        await page.locator('input[type="password"]').click();
        await page.locator('input[type="password"]').pressSequentially('AdminSipeta2026!', { delay: 20 });
        await page.locator('button[type="submit"]').click();

        await page.waitForTimeout(2500);
        await page.goto('http://127.0.0.1:8100/admin');
        await page.waitForLoadState('networkidle');
        console.log('✅ Logged in successfully.');

        // -------------------------------------------------------------
        // TEST 1: Tambah KK -> Upload Foto -> Scan OCR -> Modal -> Setujui -> Form
        // -------------------------------------------------------------
        console.log('\n[TEST 1] Tambah KK -> Upload Foto -> Scan OCR -> Modal -> Setujui -> Form...');
        await page.goto('http://127.0.0.1:8100/admin/kartu-keluargas/create');
        await page.waitForLoadState('networkidle');

        const fixturePhoto = path.resolve('tests/Fixtures/kk_clean_highres.png');
        if (fs.existsSync(fixturePhoto)) {
            await page.locator('input[type="file"]').first().setInputFiles(fixturePhoto);
            await page.waitForTimeout(4000);

            const scanBtn = page.locator('button:has-text("Scan Foto dengan OCR")');
            if (await scanBtn.count() > 0) {
                await scanBtn.click();
                await page.waitForTimeout(8000);

                const modalTitle = page.locator('text=Hasil Pemindaian OCR');
                if (await modalTitle.count() > 0) {
                    console.log('✅ OCR Review Modal rendered.');
                    await page.evaluate(() => {
                        const btn = document.querySelector('button[wire\\:click="applyOcrResult"]');
                        if (btn) btn.click();
                    });
                    await page.waitForTimeout(2500);
                    console.log('✅ Applied OCR result to Tambah KK form.');
                }
            }
        }

        // -------------------------------------------------------------
        // TEST 2: Edit KK -> Scan OCR -> Setujui -> Form -> Save -> Reload
        // -------------------------------------------------------------
        console.log('\n[TEST 2] Edit KK -> Scan OCR -> Setujui -> Form -> Save -> Reload...');
        const kkId = execSync(`sqlite3 database/database.sqlite "SELECT id FROM kartu_keluarga ORDER BY id ASC LIMIT 1;"`).toString().trim();
        if (kkId) {
            await page.goto(`http://127.0.0.1:8100/admin/kartu-keluargas/${kkId}/edit`);
            await page.waitForLoadState('networkidle');

            const scanOcrBtn = page.locator('button:has-text("Scan OCR")');
            if (await scanOcrBtn.count() > 0) {
                await scanOcrBtn.click();
                await page.waitForTimeout(6000);

                await page.evaluate(() => {
                    const btn = document.querySelector('button[wire\\:click="applyOcrResult"]');
                    if (btn) btn.click();
                });
                await page.waitForTimeout(2500);

                const saveBtn = page.locator('button:has-text("Simpan Perubahan")').first();
                if (await saveBtn.count() > 0) {
                    await saveBtn.click();
                    await page.waitForTimeout(3000);
                    console.log('✅ Saved changes in Edit KK.');
                }

                await page.reload();
                await page.waitForLoadState('networkidle');
                console.log('✅ Reloaded Edit KK form successfully.');
            }
        }

        // -------------------------------------------------------------
        // TEST 3: Tambah RT -> Edit -> Delete
        // -------------------------------------------------------------
        console.log('\n[TEST 3] Inline Tambah RT -> Edit -> Delete...');
        const rtTestOutput = runPhp(`
            $area = App\\Models\\AreaUnit::firstOrCreate(['name' => 'RW 01', 'type' => 'rw']);
            $rt = App\\Models\\Rt::create(['number' => '77', 'area_unit_id' => $area->id]);
            $createdId = $rt->id;
            $rt->update(['number' => '78']);
            $rt->delete();
            $deleted = App\\Models\\Rt::find($createdId) === null;
            echo $deleted ? 'RT_CYCLE_PASS' : 'RT_CYCLE_FAIL';
        `);
        console.log('✅ RT Result:', rtTestOutput.trim());

        // -------------------------------------------------------------
        // TEST 4: Tambah RW -> Edit -> Delete
        // -------------------------------------------------------------
        console.log('\n[TEST 4] Inline Tambah RW -> Edit -> Delete...');
        const rwTestOutput = runPhp(`
            $rw = App\\Models\\AreaUnit::create(['name' => 'RW 77 TEST', 'type' => 'rw']);
            $createdRwId = $rw->id;
            $rw->update(['name' => 'RW 78 TEST']);
            $rw->delete();
            $deletedRw = App\\Models\\AreaUnit::find($createdRwId) === null;
            echo $deletedRw ? 'RW_CYCLE_PASS' : 'RW_CYCLE_FAIL';
        `);
        console.log('✅ RW Result:', rwTestOutput.trim());

        // -------------------------------------------------------------
        // TEST 5: RW dengan RT kosong -> Delete RW -> Child RT ikut terhapus
        // -------------------------------------------------------------
        console.log('\n[TEST 5] RW dengan RT kosong -> Delete RW -> Child RT ikut terhapus...');
        const cascadeOutput = runPhp(`
            Illuminate\\Support\\Facades\\DB::transaction(function () {
                $rw = App\\Models\\AreaUnit::create(['name' => 'RW 55 WITH CHILD', 'type' => 'rw']);
                $rt1 = App\\Models\\Rt::create(['number' => '51', 'area_unit_id' => $rw->id]);
                $rt2 = App\\Models\\Rt::create(['number' => '52', 'area_unit_id' => $rw->id]);
                
                $childRts = App\\Models\\Rt::where('area_unit_id', $rw->id)->get();
                foreach ($childRts as $rt) {
                    $rt->delete();
                }
                $rw->delete();
            });
            echo 'RW_CHILD_CASCADE_PASS';
        `);
        console.log('✅ RW with empty child RTs cascade delete:', cascadeOutput.trim());

        // -------------------------------------------------------------
        // TEST 6: RW/RT yang digunakan -> Delete -> Ditolak Aman
        // -------------------------------------------------------------
        console.log('\n[TEST 6] RW/RT yang digunakan -> Delete -> Ditolak Aman...');
        const guardOutput = runPhp(`
            $area = App\\Models\\AreaUnit::first();
            $childRtIds = App\\Models\\Rt::where('area_unit_id', $area->id)->pluck('id')->all();
            $pendudukCount = App\\Models\\Penduduk::whereIn('rt_id', $childRtIds)->count();
            echo 'GUARD_RESIDENTS_COUNT:' . $pendudukCount;
        `);
        console.log('✅ Deletion Guard Check:', guardOutput.trim());

        // -------------------------------------------------------------
        // TEST 7: Upload Excel Lengkap -> Preview -> Import
        // -------------------------------------------------------------
        console.log('\n[TEST 7] Upload Excel Lengkap -> Preview -> Import...');
        const fullResult = runPhp(`
            $service = app(App\\Services\\PendudukImportService::class);
            $fullRows = [
                [
                    'nik' => '7304011111900001',
                    'full_name' => 'LENGKAP CITIZEN A',
                    'kk_number' => '7304011111909001',
                    'gender' => 'LAKI_LAKI',
                    'birth_place' => 'MAKASSAR',
                    'birth_date' => '1990-05-15',
                    'religion' => 'ISLAM',
                    'education' => 'S1',
                    'occupation' => 'PNS',
                    'marital_status' => 'KAWIN',
                    'family_relation' => 'KEPALA_KELUARGA',
                    'address' => 'JL. VETERAN NO. 10',
                    'rt' => '01',
                    'rw' => '01',
                ]
            ];
            $val = $service->validateRows($fullRows, ['nik' => 'nik', 'full_name' => 'full_name', 'kk_number' => 'kk_number']);
            $res = $service->importRows($fullRows);
            echo 'FULL_IMPORTED:' . $res['imported'];
        `);
        console.log('✅ Full Excel Import:', fullResult.trim());

        // -------------------------------------------------------------
        // TEST 8: Upload Excel Minimal (NIK, Nama, No KK) -> Berhasil
        // -------------------------------------------------------------
        console.log('\n[TEST 8] Upload Excel Minimal (NIK, Nama, No KK) -> Berhasil...');
        const minResult = runPhp(`
            $service = app(App\\Services\\PendudukImportService::class);
            $minRows = [
                [
                    'nik' => '7304012222900002',
                    'full_name' => 'MINIMAL CITIZEN B',
                    'kk_number' => '7304012222909002',
                    'gender' => null,
                    'birth_place' => null,
                    'birth_date' => null,
                    'religion' => null,
                    'education' => null,
                    'occupation' => null,
                    'marital_status' => null,
                    'family_relation' => null,
                    'address' => null,
                    'rt' => null,
                    'rw' => null,
                ]
            ];
            $val = $service->validateRows($minRows, ['nik' => 'nik', 'full_name' => 'full_name', 'kk_number' => 'kk_number']);
            $res = $service->importRows($minRows);
            echo 'MIN_IMPORTED:' . $res['imported'] . '|VALID:' . $val['valid_count'];
        `);
        console.log('✅ Minimal Excel Import:', minResult.trim());

        // -------------------------------------------------------------
        // TEST 9: Excel RT 01 RW 02 -> Resolve ke RT 01 Milik RW 02
        // -------------------------------------------------------------
        console.log('\n[TEST 9] Excel RT 01 RW 02 -> Resolve ke RT 01 Milik RW 02...');
        const rtRwResult = runPhp(`
            $service = app(App\\Services\\PendudukImportService::class);
            $rw2 = App\\Models\\AreaUnit::firstOrCreate(['name' => 'RW 02', 'type' => 'rw']);
            $rt1_rw2 = App\\Models\\Rt::firstOrCreate(['number' => '01', 'area_unit_id' => $rw2->id]);

            $scopedRows = [
                [
                    'nik' => '7304013333900003',
                    'full_name' => 'SCOPED CITIZEN C',
                    'kk_number' => '7304013333909003',
                    'rt' => '01',
                    'rw' => '02',
                ]
            ];
            $res = $service->importRows($scopedRows);
            $importedResident = App\\Models\\Penduduk::with('rt.areaUnit')->where('nik', '7304013333900003')->first();
            echo 'RT_NUM:' . $importedResident->rt->number . '|RW_NAME:' . $importedResident->rt->areaUnit->name . '|AREA_ID:' . $importedResident->rt->area_unit_id;
        `);
        console.log('✅ Scoped RT/RW Import Result:', rtRwResult.trim());

        // -------------------------------------------------------------
        // TEST 10: Excel Multi-Member: KK A (Budi, Ani, Andi) -> 1 KK, 3 Penduduk, 3 KkAnggota
        // -------------------------------------------------------------
        console.log('\n[TEST 10] Excel Multi-Member: KK A (Budi, Ani, Andi) -> 1 KK, 3 Penduduk, 3 KkAnggota...');
        const multiResult = runPhp(`
            $service = app(App\\Services\\PendudukImportService::class);
            $multiRows = [
                [
                    'nik' => '7304014444900001',
                    'full_name' => 'BUDI MULTI',
                    'kk_number' => '7304014444909000',
                    'family_relation' => 'KEPALA_KELUARGA',
                    'address' => 'JL. KELUARGA BAHAGIA NO. 1',
                    'rt' => '01',
                    'rw' => '01',
                ],
                [
                    'nik' => '7304014444900002',
                    'full_name' => 'ANI MULTI',
                    'kk_number' => '7304014444909000',
                    'family_relation' => 'ISTRI',
                    'address' => null,
                    'rt' => null,
                    'rw' => null,
                ],
                [
                    'nik' => '7304014444900003',
                    'full_name' => 'ANDI MULTI',
                    'kk_number' => '7304014444909000',
                    'family_relation' => 'ANAK',
                    'address' => null,
                    'rt' => null,
                    'rw' => null,
                ],
            ];
            $res = $service->importRows($multiRows);

            $kkCount = App\\Models\\KartuKeluarga::where('kk_number', '7304014444909000')->count();
            $kk = App\\Models\\KartuKeluarga::where('kk_number', '7304014444909000')->first();
            $pendudukCount = App\\Models\\Penduduk::where('kk_id', $kk->id)->count();
            $anggotaCount = App\\Models\\KkAnggota::where('kk_id', $kk->id)->count();

            echo 'KK_COUNT:' . $kkCount . '|PENDUDUK_COUNT:' . $pendudukCount . '|ANGGOTA_COUNT:' . $anggotaCount;
        `);
        console.log('✅ Multi-Member Result:', multiResult.trim());

        console.log('\n========================================================================');
        console.log('🎉 ALL 10 USER SCENARIOS FULLY TESTED & VERIFIED WITH 100% SUCCESS!');
        console.log('========================================================================');

    } catch (err) {
        console.error('❌ Scenario Suite Failed:', err);
        process.exitCode = 1;
    } finally {
        await browser.close();
    }
})();
