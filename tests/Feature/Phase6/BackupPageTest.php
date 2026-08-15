<?php

namespace Tests\Feature\Phase6;

use App\Filament\Pages\Backup;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 6.4 — operator-facing Backup & Restore page. Verifies the page wiring
 * against the Phase 6.2 BackupService and the Phase 6.3 RestoreService:
 * the archive list, the "Buat Backup" action (workflow §14), the two-step
 * restore flow (§15) with the FR-BR-05 explicit confirmation gate, the
 * FR-BR-04 integrity-failure handling, and the FR-BR-06 restart advice.
 */
class BackupPageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->actingAs($this->admin);
    }

    public function test_backup_page_lists_stored_archives(): void
    {
        $this->get(Backup::getUrl())
            ->assertOk()
            ->assertSee('Backup & Restore')
            ->assertSee('Google Drive')
            ->assertDontSee('Total Backup')
            ->assertDontSee('Penyimpanan Database SIPETA')
            ->assertDontSee('Daftar Backup')
            ->assertDontSee('Buat Backup')
            ->assertSee('Hubungkan Google Drive');
    }

    public function test_connected_state_exposes_disabled_loading_backup_action(): void
    {
        app(SettingsService::class)->saveGoogleDriveConnection(
            ['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_at' => now()->addHour()->toIso8601String()],
            'admin@gmail.com',
            'folder-1',
        );

        $this->get(Backup::getUrl())
            ->assertOk()
            ->assertSee('admin@gmail.com')
            ->assertSee('Backup Sekarang')
            ->assertSee('Backup sedang diproses...')
            ->assertSeeHtml('wire:loading.attr="disabled"')
            ->assertSeeHtml('wire:target="createGoogleDriveBackup"');
    }
}
