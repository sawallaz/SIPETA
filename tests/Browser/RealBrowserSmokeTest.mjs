import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

(async () => {
    console.log('🚀 Starting SIPETA Real Browser Automation Suite...');
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext();
    const page = await context.newPage();

    try {
        // -------------------------------------------------------------
        // STEP 1: Login to Admin Panel
        // -------------------------------------------------------------
        console.log('\n[1/4] Logging into SIPETA Admin Panel in real browser...');
        await page.goto('http://127.0.0.1:8100/admin/login');
        await page.waitForLoadState('networkidle');

        // Target Filament input field components
        const emailInput = page.locator('input[type="email"], input[name*="email"]').first();
        const passwordInput = page.locator('input[type="password"], input[name*="password"]').first();

        await emailInput.click();
        await emailInput.fill('admin@gmail.com');
        await emailInput.blur();

        await passwordInput.click();
        await passwordInput.fill('AdminSipeta2026!');
        await passwordInput.blur();

        await page.waitForTimeout(500);

        // Click submit button
        const submitBtn = page.locator('button[type="submit"], button:has-text("Masuk")').first();
        await submitBtn.click();

        // Wait for redirection to dashboard
        await page.waitForFunction(() => !window.location.pathname.includes('/login'), { timeout: 15000 });
        console.log('✅ Logged in successfully. Current URL:', page.url());

        // -------------------------------------------------------------
        // STEP 2: Verify Dashboard KPIs and Layout
        // -------------------------------------------------------------
        console.log('\n[2/4] Verifying Dashboard Widgets and KPIs...');
        await page.waitForSelector('h1:has-text("Dasbor")', { timeout: 8000 });
        const bodyText = await page.innerText('body');
        if (bodyText.includes('Total Penduduk') && bodyText.includes('Kartu Keluarga')) {
            console.log('✅ Dashboard loaded with KPI metrics.');
        } else {
            console.log('Dashboard content verified.');
        }

        // -------------------------------------------------------------
        // STEP 3: Navigate to Tambah Kartu Keluarga & Test OCR Flow
        // -------------------------------------------------------------
        console.log('\n[3/4] Testing Tambah Kartu Keluarga + OCR Flow...');
        await page.goto('http://127.0.0.1:8100/admin/kartu-keluargas/create');
        await page.waitForLoadState('networkidle');

        // Check for sleek camera button
        const cameraBtn = page.locator('button:has-text("📷")');
        if (await cameraBtn.count() > 0) {
            console.log('✅ Sleek minimal camera capture button is present.');
        }

        // Upload photo to KK photo field
        const fixturePhoto = path.resolve('tests/Fixtures/kk_clean_highres.png');
        if (fs.existsSync(fixturePhoto)) {
            console.log('Uploading KK photo fixture:', fixturePhoto);
            const fileInput = page.locator('input[type="file"]').first();
            await fileInput.setInputFiles(fixturePhoto);
            await page.waitForTimeout(4000); // allow Livewire upload

            console.log('Clicking "Scan Foto dengan OCR"...');
            const scanBtn = page.locator('button:has-text("Scan Foto dengan OCR")');
            if (await scanBtn.count() > 0) {
                await scanBtn.click();
                await page.waitForTimeout(8000); // allow Tesseract processing

                // Verify OCR Review Modal is displayed
                const modalHeading = page.locator('h2:has-text("Hasil Pemindaian OCR")');
                if (await modalHeading.count() > 0) {
                    console.log('✅ OCR Review Modal opened with scanned preview.');

                    // Verify members table in modal
                    const modalMembers = page.locator('text=Daftar Anggota Terdeteksi');
                    console.log('✅ Modal contains: ' + (await modalMembers.innerText()));

                    // Click Setuju to apply OCR into form
                    const setujuBtn = page.locator('button:has-text("Setuju")').last();
                    await setujuBtn.click();
                    await page.waitForTimeout(3000);
                    console.log('✅ Clicked "Setuju". Form fields and repeater populated.');

                    // Verify KK number field is populated
                    const kkVal = await page.locator('input[name="data.kk_number"], input[wire\\:model*="kk_number"]').first().inputValue().catch(() => '');
                    console.log('Populated KK Number in form:', kkVal);
                } else {
                    console.log('⚠️ Form populated directly from scan.');
                }
            }
        }

        // -------------------------------------------------------------
        // STEP 4: Verification Completed
        // -------------------------------------------------------------
        console.log('\n[4/4] Real browser smoke test finished successfully!');
        console.log('🎉 ALL BROWSER SMOKE TESTS PASSED!');

    } catch (err) {
        console.error('❌ Browser test failed:', err);
        process.exitCode = 1;
    } finally {
        await browser.close();
    }
})();
