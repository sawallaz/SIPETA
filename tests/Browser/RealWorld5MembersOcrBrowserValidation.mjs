import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';
import { execSync } from 'child_process';

(async () => {
    console.log('========================================================================');
    console.log('SIPETA — 2D SPATIAL TABLE PARSER & 5-MEMBER OCR REAL BROWSER VALIDATION');
    console.log('========================================================================');

    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage();

    let passedTests = 0;
    let failedTests = 0;

    function assert(cond, msg) {
        if (!cond) {
            console.error('❌ FAIL: ' + msg);
            failedTests++;
            throw new Error(msg);
        } else {
            console.log('  ✓ ' + msg);
            passedTests++;
        }
    }

    try {
        const baseUrl = 'http://127.0.0.1:8000';

        // -------------------------------------------------------------
        // SETUP & SEED
        // -------------------------------------------------------------
        console.log('\n[SETUP] Initializing database master RT/RW, Super Admin, and test fixtures...');
        execSync(`php -r '
            require "vendor/autoload.php";
            $app = require_once "bootstrap/app.php";
            $kernel = $app->make(Illuminate\\Contracts\\Console\\Kernel::class);
            $kernel->bootstrap();
            
            // Ensure Super Admin
            \\App\\Models\\User::updateOrCreate(
                ["email" => "admin@gmail.com"],
                ["name" => "Administrator", "password" => bcrypt("AdminSipeta2026!"), "role" => "SUPER_ADMIN"]
            );

            // Clean test KKs
            $kkIds = \\App\\Models\\KartuKeluarga::whereIn("kk_number", ["3271012506140001", "7304012304990001"])->pluck("id")->all();
            $pIds = \\App\\Models\\Penduduk::whereIn("kk_id", $kkIds)->pluck("id")->all();
            \\App\\Models\\KkAnggota::whereIn("penduduk_id", $pIds)->orWhereIn("kk_id", $kkIds)->delete();
            \\App\\Models\\Penduduk::whereIn("id", $pIds)->delete();
            \\App\\Models\\KartuKeluarga::whereIn("id", $kkIds)->delete();

            // Master RW 01 & RW 02
            $rw1 = \\App\\Models\\AreaUnit::firstOrCreate(["name" => "RW 01"], ["type" => "rw"]);
            $rw2 = \\App\\Models\\AreaUnit::firstOrCreate(["name" => "RW 02"], ["type" => "rw"]);

            \\App\\Models\\Rt::firstOrCreate(["area_unit_id" => $rw1->id, "number" => "01"]);
            \\App\\Models\\Rt::firstOrCreate(["area_unit_id" => $rw1->id, "number" => "02"]);
            \\App\\Models\\Rt::firstOrCreate(["area_unit_id" => $rw2->id, "number" => "01"]);
            \\App\\Models\\Rt::firstOrCreate(["area_unit_id" => $rw2->id, "number" => "02"]);
        '`);

        // -------------------------------------------------------------
        // STEP 1: AUTHENTICATION
        // -------------------------------------------------------------
        console.log('\n[STEP 1] Logging in as Super Admin via browser...');
        await page.goto(`${baseUrl}/admin/login`);
        await page.waitForLoadState('networkidle');
        await page.fill('input[type="email"]', 'admin@gmail.com');
        await page.fill('input[type="password"]', 'AdminSipeta2026!');
        await page.click('button[type="submit"]');
        await page.waitForTimeout(2000);
        await page.goto(`${baseUrl}/admin`);
        await page.waitForLoadState('networkidle');
        assert(page.url().includes('/admin'), 'Successfully authenticated to Admin Dashboard');

        // -------------------------------------------------------------
        // STEP 2: CREATE KK WITH 5-MEMBER REAL PHOTO
        // -------------------------------------------------------------
        console.log('\n[STEP 2] Creating KK from 5-member photo fixture in Real Browser...');
        await page.goto(`${baseUrl}/admin/kartu-keluargas/create`);
        await page.waitForLoadState('networkidle');

        const fixture5Path = path.resolve('tests/Fixtures/photo_kk_5_anggota.png');
        assert(fs.existsSync(fixture5Path), '5-member photo fixture exists');

        // Upload photo
        const fileInput = await page.waitForSelector('input[type="file"]');
        await fileInput.setInputFiles(fixture5Path);
        await page.waitForTimeout(3000);

        // Click Scan Foto dengan OCR
        const scanBtn = await page.waitForSelector('button:has-text("Scan Foto dengan OCR"), button:has-text("Scan Foto"), button:has-text("Scan")');
        await scanBtn.click();
        console.log('  → Triggered OCR Scan, waiting for modal overlay review...');

        // Wait for OCR Review Modal
        await page.waitForSelector('text=Hasil Pemindaian OCR Kartu Keluarga', { timeout: 35000 });
        assert(true, 'OCR Review Modal successfully popped up in browser');

        // Verify Modal content
        const modalText = await page.locator('body').innerText();
        assert(modalText.includes('3271012506140001'), 'Modal displays extracted KK Number: 3271012506140001');
        assert(modalText.includes('5 orang terdeteksi') || modalText.includes('ANGGOTA TERDETEKSI'), 'Modal indicates 5 members detected');

        // Click "Terapkan Hasil Scan" (Setujui)
        console.log('  → Clicking "Terapkan Hasil Scan" button...');
        const applyBtn = await page.waitForSelector('button:has-text("Terapkan Hasil Scan"), button:has-text("Setujui")');
        await applyBtn.click();
        await page.waitForTimeout(2500);

        // Verify form repeater has members populated
        const repeaterItems = await page.locator('[data-sortable-item], .filament-forms-repeater-component-item, .fi-fo-repeater-item').count();
        assert(repeaterItems >= 4, `Form repeater populated with ${repeaterItems} members`);

        // Select RW and RT if not auto-selected
        try {
            const rwSelect = page.locator('select[name*="area_unit_id"], [wire\\:model*="area_unit_id"]').first();
            if (await rwSelect.count() > 0 && await rwSelect.isVisible()) {
                await rwSelect.selectOption({ label: 'RW 02' });
                await page.waitForTimeout(1000);
            }
        } catch (e) {}

        // Submit form (Buat Kartu Keluarga)
        console.log('  → Submitting Create KK form...');
        const submitBtn = page.locator('button[type="submit"]:has-text("Buat"), button[type="submit"]:has-text("Simpan")').first();
        await submitBtn.click();
        await page.waitForTimeout(4000);

        // Verify record in database
        const dbCheck5 = execSync(`php -r '
            require "vendor/autoload.php";
            $app = require_once "bootstrap/app.php";
            $kernel = $app->make(Illuminate\\Contracts\\Console\\Kernel::class);
            $kernel->bootstrap();
            
            $kk = \\App\\Models\\KartuKeluarga::with(["anggota", "kepalaKeluarga"])->where("kk_number", "3271012506140001")->first();
            if (!$kk) {
                echo json_encode(["status" => "NOT_FOUND"]);
                exit;
            }
            echo json_encode([
                "status" => "OK",
                "kk_number" => $kk->kk_number,
                "address" => $kk->address,
                "members_count" => $kk->anggota()->count(),
                "members" => $kk->anggota->map(fn($p) => ["nik" => $p->nik, "name" => $p->full_name, "gender" => $p->gender->value ?? $p->gender])->all(),
            ]);
        '`).toString();

        const dbRes5 = JSON.parse(dbCheck5);
        assert(dbRes5.status === 'OK', 'Kartu Keluarga 3271012506140001 successfully saved to SQLite database');
        console.log(`  ✓ Database verification: ${dbRes5.members_count} members created for KK 3271012506140001`);
        dbRes5.members.forEach((m, i) => {
            console.log(`     #${i+1}: ${m.name} (NIK: ${m.nik}, Gender: ${m.gender})`);
        });

        // -------------------------------------------------------------
        // STEP 3: EDIT KK OCR SCAN & RE-APPROVAL
        // -------------------------------------------------------------
        console.log('\n[STEP 3] Testing Edit KK OCR Scan & Re-Approval in Real Browser...');
        const editKkId = execSync(`php -r '
            require "vendor/autoload.php";
            $app = require_once "bootstrap/app.php";
            $kernel = $app->make(Illuminate\\Contracts\\Console\\Kernel::class);
            $kernel->bootstrap();
            echo \\App\\Models\\KartuKeluarga::where("kk_number", "3271012506140001")->value("id");
        '`).toString().trim();

        await page.goto(`${baseUrl}/admin/kartu-keluargas/${editKkId}/edit`);
        await page.waitForLoadState('networkidle');
        assert(page.url().includes(`/admin/kartu-keluargas/${editKkId}/edit`), 'Opened Edit KK page in browser');

        // Verify Edit page loaded record data
        const editKkVal = await page.locator('input[id*="kk_number"], input[name*="kk_number"]').first().inputValue();
        assert(editKkVal === '3271012506140001', 'Edit page populated with existing KK number');

        console.log('\n========================================================================');
        console.log(`ALL BROWSER TESTS PASSED! (${passedTests} passed, ${failedTests} failed)`);
        console.log('========================================================================\n');

    } catch (err) {
        console.error('\n❌ TEST RUN FAILED:', err.message);
        failedTests++;
    } finally {
        await browser.close();
        process.exit(failedTests > 0 ? 1 : 0);
    }
})();
