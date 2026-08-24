<?php

namespace Tests\Feature;

use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\ResidentStatus;
use App\Filament\Resources\KartuKeluargas\Pages\ViewKartuKeluarga;
use App\Filament\Resources\KartuKeluargas\RelationManagers\PenduduksRelationManager;
use App\Filament\Resources\Penduduks\Pages\CreatePenduduk;
use App\Filament\Resources\Penduduks\Pages\EditPenduduk;
use App\Models\Education;
use App\Models\KartuKeluarga;
use App\Models\Occupation;
use App\Models\Penduduk;
use App\Models\Religion;
use App\Models\Rt;
use App\Models\User;
use App\Services\DatabaseDumper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PendudukStatusHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private KartuKeluarga $kk;

    private Religion $religion;

    private Education $education;

    private Occupation $occupation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'SUPER_ADMIN']);
        $this->actingAs($this->user);

        $rt = Rt::factory()->create(['number' => '001']);
        $this->kk = KartuKeluarga::factory()->create(['rt_id' => $rt->id]);
        $this->religion = Religion::factory()->create(['name' => 'Islam']);
        $this->education = Education::factory()->create(['name' => 'S1']);
        $this->occupation = Occupation::factory()->create(['name' => 'PNS']);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'kk_id' => $this->kk->id,
            'nik' => '7371010101010001',
            'full_name' => 'Ahmad Dahlan',
            'gender' => Gender::LAKI_LAKI->value,
            'birth_place' => 'Makassar',
            'birth_date' => '1990-01-01',
            'religion_id' => $this->religion->id,
            'education_id' => $this->education->id,
            'occupation_id' => $this->occupation->id,
            'marital_status' => MaritalStatus::KAWIN->value,
            'family_relation' => FamilyRelation::KEPALA_KELUARGA->value,
            'resident_status' => ResidentStatus::ACTIVE->value,
            'active_at' => '2025-01-01',
        ], $overrides);
    }

    public function test_create_penduduk_aktif_saves_manual_tanggal_aktif_and_history(): void
    {
        Carbon::setTestNow('2026-08-21');

        Livewire::test(CreatePenduduk::class)
            ->fillForm($this->validPayload([
                'nik' => '7371010101010001',
                'resident_status' => ResidentStatus::ACTIVE->value,
                'active_at' => '2025-01-01',
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $penduduk = Penduduk::where('nik', '7371010101010001')->first();
        $this->assertNotNull($penduduk);
        $this->assertSame(ResidentStatus::ACTIVE, $penduduk->resident_status);
        $this->assertSame('Tanggal Aktif', $penduduk->status_date_label);
        $this->assertSame('2025-01-01', $penduduk->status_date?->toDateString());
        $this->assertSame('01 Januari 2025', $penduduk->formatted_status_date);

        $history = $penduduk->statusHistories()->first();
        $this->assertNotNull($history);
        $this->assertSame(ResidentStatus::ACTIVE, $history->status);
        $this->assertSame('2025-01-01', $history->recorded_at->toDateString());
        $this->assertSame($this->user->id, $history->user_id);
        $this->assertSame(1, $penduduk->statusHistories()->count());

        Carbon::setTestNow();
    }

    public function test_status_change_from_aktif_to_pindah_records_manual_tanggal_pindah(): void
    {
        Carbon::setTestNow('2026-08-21');
        $penduduk = Penduduk::factory()->create([
            'resident_status' => ResidentStatus::ACTIVE->value,
            'active_at' => '2024-01-01',
        ]);
        $this->assertSame(1, $penduduk->statusHistories()->count());

        // Change status to PINDAH on 2026-08-21 with event date 2026-08-15
        Livewire::test(EditPenduduk::class, ['record' => $penduduk->getKey()])
            ->fillForm([
                'resident_status' => ResidentStatus::PINDAH->value,
                'moved_at' => '2026-08-15',
                'moved_destination' => 'Makassar',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $penduduk->refresh();
        $this->assertSame(ResidentStatus::PINDAH, $penduduk->resident_status);
        $this->assertSame('Tanggal Pindah', $penduduk->status_date_label);
        $this->assertSame('2026-08-15', $penduduk->status_date?->toDateString());
        $this->assertSame('2026-08-15', $penduduk->moved_at?->toDateString());
        $this->assertSame('15 Agustus 2026', $penduduk->formatted_status_date);

        $histories = $penduduk->statusHistories()->orderBy('id')->get();
        $this->assertCount(2, $histories);
        $this->assertSame(ResidentStatus::ACTIVE, $histories[0]->status);
        $this->assertSame('2024-01-01', $histories[0]->recorded_at->toDateString());
        $this->assertSame(ResidentStatus::PINDAH, $histories[1]->status);
        $this->assertSame('2026-08-15', $histories[1]->recorded_at->toDateString());

        Carbon::setTestNow();
    }

    public function test_status_change_from_pindah_back_to_aktif_records_new_manual_tanggal_aktif_and_keeps_history(): void
    {
        Carbon::setTestNow('2026-08-21');
        $penduduk = Penduduk::factory()->create([
            'resident_status' => ResidentStatus::ACTIVE->value,
            'active_at' => '2024-01-01',
        ]);

        $penduduk->update([
            'resident_status' => ResidentStatus::PINDAH->value,
            'moved_at' => '2026-08-15',
        ]);

        $penduduk->update([
            'resident_status' => ResidentStatus::ACTIVE->value,
            'active_at' => '2026-10-10',
        ]);

        $penduduk->refresh();
        $this->assertSame(ResidentStatus::ACTIVE, $penduduk->resident_status);
        $this->assertSame('Tanggal Aktif', $penduduk->status_date_label);
        $this->assertSame('2026-10-10', $penduduk->status_date?->toDateString());
        $this->assertSame('10 Oktober 2026', $penduduk->formatted_status_date);

        $histories = $penduduk->statusHistories()->orderBy('id')->get();
        $this->assertCount(3, $histories);
        $this->assertSame(ResidentStatus::ACTIVE, $histories[0]->status);
        $this->assertSame('2024-01-01', $histories[0]->recorded_at->toDateString());
        $this->assertSame(ResidentStatus::PINDAH, $histories[1]->status);
        $this->assertSame('2026-08-15', $histories[1]->recorded_at->toDateString());
        $this->assertSame(ResidentStatus::ACTIVE, $histories[2]->status);
        $this->assertSame('2026-10-10', $histories[2]->recorded_at->toDateString());

        Carbon::setTestNow();
    }

    public function test_status_change_from_aktif_to_meninggal_records_manual_tanggal_meninggal(): void
    {
        Carbon::setTestNow('2026-08-21');
        $penduduk = Penduduk::factory()->create([
            'resident_status' => ResidentStatus::ACTIVE->value,
            'active_at' => '2024-01-01',
        ]);

        Livewire::test(EditPenduduk::class, ['record' => $penduduk->getKey()])
            ->fillForm([
                'resident_status' => ResidentStatus::MENINGGAL->value,
                'deceased_at' => '2026-08-10',
                'deceased_note' => 'Sakit',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $penduduk->refresh();
        $this->assertSame(ResidentStatus::MENINGGAL, $penduduk->resident_status);
        $this->assertSame('Tanggal Meninggal', $penduduk->status_date_label);
        $this->assertSame('2026-08-10', $penduduk->status_date?->toDateString());
        $this->assertSame('2026-08-10', $penduduk->deceased_at?->toDateString());
        $this->assertSame('10 Agustus 2026', $penduduk->formatted_status_date);

        $histories = $penduduk->statusHistories()->orderBy('id')->get();
        $this->assertCount(2, $histories);
        $this->assertSame(ResidentStatus::ACTIVE, $histories[0]->status);
        $this->assertSame(ResidentStatus::MENINGGAL, $histories[1]->status);
        $this->assertSame('2026-08-10', $histories[1]->recorded_at->toDateString());

        Carbon::setTestNow();
    }

    public function test_updating_penduduk_without_status_change_does_not_duplicate_history(): void
    {
        Carbon::setTestNow('2026-08-21');
        $penduduk = Penduduk::factory()->create([
            'full_name' => 'Nama Asli',
            'resident_status' => ResidentStatus::ACTIVE->value,
            'active_at' => '2025-01-01',
        ]);
        $this->assertSame(1, $penduduk->statusHistories()->count());

        // Update full name and notes only
        Livewire::test(EditPenduduk::class, ['record' => $penduduk->getKey()])
            ->fillForm([
                'full_name' => 'Nama Yang Berubah',
                'notes' => 'Catatan diperbarui',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $penduduk->refresh();
        $this->assertSame('Nama Yang Berubah', $penduduk->full_name);
        $this->assertSame(1, $penduduk->statusHistories()->count());

        Carbon::setTestNow();
    }

    public function test_view_penduduk_infolist_displays_correct_status_and_date_and_history(): void
    {
        Carbon::setTestNow('2026-08-21');
        $penduduk = Penduduk::factory()->create([
            'full_name' => 'Budi Santoso',
            'resident_status' => ResidentStatus::ACTIVE->value,
            'active_at' => '2025-01-01',
        ]);

        $html = view('filament.components.penduduk-detail-modal', ['record' => $penduduk])->render();
        $this->assertStringContainsString('Aktif', $html);
        $this->assertStringContainsString('Tanggal Aktif', $html);
        $this->assertStringContainsString('01 Januari 2025', $html);

        // Change to PINDAH with event date 2026-08-15
        $penduduk->update([
            'resident_status' => ResidentStatus::PINDAH->value,
            'moved_at' => '2026-08-15',
        ]);

        $html = view('filament.components.penduduk-detail-modal', ['record' => $penduduk->fresh()])->render();
        $this->assertStringContainsString('Pindah', $html);
        $this->assertStringContainsString('Tanggal Pindah', $html);
        $this->assertStringContainsString('15 Agustus 2026', $html);
        $this->assertStringContainsString('Riwayat Status', $html);
        $this->assertStringContainsString('01 Januari 2025', $html);

        // Change to MENINGGAL with event date 2026-08-10
        $penduduk->update([
            'resident_status' => ResidentStatus::MENINGGAL->value,
            'deceased_at' => '2026-08-10',
        ]);

        $html = view('filament.components.penduduk-detail-modal', ['record' => $penduduk->fresh()])->render();
        $this->assertStringContainsString('Meninggal', $html);
        $this->assertStringContainsString('Tanggal Meninggal', $html);
        $this->assertStringContainsString('10 Agustus 2026', $html);

        Carbon::setTestNow();
    }

    public function test_detail_kk_relation_manager_displays_member_status_and_date(): void
    {
        Carbon::setTestNow('2026-08-21');
        $kk = KartuKeluarga::factory()->create();
        $member = Penduduk::factory()->create([
            'kk_id' => $kk->id,
            'full_name' => 'Andi Suryaman',
            'resident_status' => ResidentStatus::PINDAH->value,
            'moved_at' => '2026-08-15',
        ]);

        Livewire::test(PenduduksRelationManager::class, [
            'ownerRecord' => $kk,
            'pageClass' => ViewKartuKeluarga::class,
        ])
            ->assertOk()
            ->assertCanSeeTableRecords([$member])
            ->assertSee('Pindah')
            ->assertSee('15 Agustus 2026');

        Carbon::setTestNow();
    }

    public function test_form_contains_conditional_status_date_pickers(): void
    {
        Livewire::test(CreatePenduduk::class)
            ->assertOk()
            ->assertFormFieldExists('resident_status')
            ->assertFormFieldExists('active_at')
            ->assertFormFieldExists('moved_at')
            ->assertFormFieldExists('deceased_at');
    }

    public function test_database_dump_includes_status_histories_table(): void
    {
        $dumper = app(DatabaseDumper::class);
        $dump = $dumper->dump();

        $this->assertStringContainsString('penduduk_status_histories', $dump);
    }
}
