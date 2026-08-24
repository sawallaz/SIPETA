<?php

namespace Tests\Feature\Phase3;

use App\Enums\BloodType;
use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Enums\KkAnggotaStatus;
use App\Enums\MaritalStatus;
use App\Enums\ResidentStatus;
use App\Filament\Resources\Penduduks\Pages\CreatePenduduk;
use App\Filament\Resources\Penduduks\Pages\EditPenduduk;
use App\Models\Education;
use App\Models\KartuKeluarga;
use App\Models\KkAnggota;
use App\Models\Occupation;
use App\Models\Penduduk;
use App\Models\Religion;
use Livewire\Livewire;

/**
 * Aturan inti arsitektur:
 *
 * NIK = satu orang.
 * KK  = tempat orang tersebut berada.
 * kk_anggota = histori perpindahan.
 * Wilayah = milik KK.
 *
 * Semua jalur simpan Penduduk (Create + Edit) melewati
 * PendudukKkService, jadi aturan ini tidak boleh berbeda
 * antar halaman.
 */
class PendudukKkServiceTest extends Phase3ResourceTestCase
{
    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'kk_id' => KartuKeluarga::factory()->create()->getKey(),
            'nik' => '7371010101010101',
            'full_name' => 'Andi Baso',
            'birth_place' => 'Bulukumba',
            'birth_date' => '1990-05-17',
            'gender' => Gender::LAKI_LAKI->value,
            'blood_type' => BloodType::O->value,
            'family_relation' => FamilyRelation::KEPALA_KELUARGA->value,
            'religion_id' => Religion::factory()->create()->getKey(),
            'education_id' => Education::factory()->create()->getKey(),
            'occupation_id' => Occupation::factory()->create()->getKey(),
            'marital_status' => MaritalStatus::KAWIN->value,
            'resident_status' => ResidentStatus::ACTIVE->value,
        ], $overrides);
    }

    public function test_existing_nik_is_moved_to_new_kk_instead_of_duplicated(): void
    {
        $kkLama = KartuKeluarga::factory()->create();
        $kkBaru = KartuKeluarga::factory()->create();

        $budi = Penduduk::factory()->create([
            'nik' => '7371000000000001',
            'full_name' => 'Budi',
            'kk_id' => $kkLama->getKey(),
            'resident_status' => ResidentStatus::ACTIVE->value,
        ]);

        KkAnggota::create([
            'kk_id' => $kkLama->getKey(),
            'penduduk_id' => $budi->getKey(),
            'family_relation' => FamilyRelation::KEPALA_KELUARGA->value,
            'status' => KkAnggotaStatus::AKTIF->value,
            'effective_date' => now()->subYear()->toDateString(),
        ]);

        // CreatePenduduk rejects duplicate NIK
        Livewire::test(CreatePenduduk::class)
            ->fillForm($this->validPayload([
                'nik' => '7371000000000001',
                'kk_id' => $kkBaru->getKey(),
                'full_name' => 'Budi',
            ]))
            ->call('create')
            ->assertHasFormErrors(['nik']);

        // Reassignment happens via EditPenduduk
        Livewire::test(EditPenduduk::class, ['record' => $budi->getRouteKey()])
            ->fillForm([
                'kk_id' => $kkBaru->getKey(),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        // Tidak ada Penduduk duplikat.
        $this->assertSame(1, Penduduk::where('nik', '7371000000000001')->count());

        $budi->refresh();
        $this->assertSame($kkBaru->getKey(), $budi->kk_id);
        $this->assertSame($kkBaru->rt_id, $budi->rt_id);

        // Membership lama ditutup.
        $lama = KkAnggota::where('penduduk_id', $budi->getKey())
            ->where('kk_id', $kkLama->getKey())->firstOrFail();
        $this->assertSame(KkAnggotaStatus::KELUAR, $lama->status);
        $this->assertNotNull($lama->end_date);

        // Membership baru aktif, dan hanya satu.
        $baru = KkAnggota::where('penduduk_id', $budi->getKey())
            ->where('kk_id', $kkBaru->getKey())->firstOrFail();
        $this->assertSame(KkAnggotaStatus::AKTIF, $baru->status);
        $this->assertNull($baru->end_date);

        $this->assertSame(1, KkAnggota::where('penduduk_id', $budi->getKey())
            ->where('status', KkAnggotaStatus::AKTIF->value)->count());
    }

    public function test_deceased_nik_cannot_be_recreated(): void
    {
        Penduduk::factory()->create([
            'nik' => '7371000000000002',
            'resident_status' => ResidentStatus::MENINGGAL->value,
        ]);

        Livewire::test(CreatePenduduk::class)
            ->fillForm($this->validPayload(['nik' => '7371000000000002']))
            ->call('create')
            ->assertHasFormErrors(['nik']);
    }

    public function test_create_new_nik_creates_active_membership(): void
    {
        $kk = KartuKeluarga::factory()->create();

        Livewire::test(CreatePenduduk::class)
            ->fillForm($this->validPayload([
                'nik' => '7371000000000003',
                'kk_id' => $kk->getKey(),
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $p = Penduduk::where('nik', '7371000000000003')->firstOrFail();

        $this->assertDatabaseHas('kk_anggota', [
            'kk_id' => $kk->getKey(),
            'penduduk_id' => $p->getKey(),
            'status' => KkAnggotaStatus::AKTIF->value,
            'end_date' => null,
        ]);
    }

    /**
     * Jalur Edit harus menghasilkan histori yang sama dengan Create:
     * KK lama ditutup, KK baru aktif, rt_id ikut KK baru.
     */
    public function test_edit_moves_resident_and_records_history(): void
    {
        $kkLama = KartuKeluarga::factory()->create();
        $kkBaru = KartuKeluarga::factory()->create();

        $penduduk = Penduduk::factory()->create([
            'nik' => '7371000000000004',
            'kk_id' => $kkLama->getKey(),
            'resident_status' => ResidentStatus::ACTIVE->value,
        ]);

        KkAnggota::create([
            'kk_id' => $kkLama->getKey(),
            'penduduk_id' => $penduduk->getKey(),
            'family_relation' => FamilyRelation::ANAK->value,
            'status' => KkAnggotaStatus::AKTIF->value,
            'effective_date' => now()->subYear()->toDateString(),
        ]);

        Livewire::test(EditPenduduk::class, ['record' => $penduduk->getKey()])
            ->fillForm(['kk_id' => $kkBaru->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $penduduk->refresh();
        $this->assertSame($kkBaru->getKey(), $penduduk->kk_id);
        $this->assertSame($kkBaru->rt_id, $penduduk->rt_id);

        $this->assertDatabaseHas('kk_anggota', [
            'kk_id' => $kkLama->getKey(),
            'penduduk_id' => $penduduk->getKey(),
            'status' => KkAnggotaStatus::KELUAR->value,
        ]);

        $this->assertDatabaseHas('kk_anggota', [
            'kk_id' => $kkBaru->getKey(),
            'penduduk_id' => $penduduk->getKey(),
            'status' => KkAnggotaStatus::AKTIF->value,
            'end_date' => null,
        ]);

        $this->assertSame(1, KkAnggota::where('penduduk_id', $penduduk->getKey())
            ->where('status', KkAnggotaStatus::AKTIF->value)->count());
    }

    /**
     * Edit tanpa ganti KK tidak boleh menumpuk membership baru.
     */
    public function test_edit_without_changing_kk_does_not_duplicate_membership(): void
    {
        $penduduk = Penduduk::factory()->create([
            'nik' => '7371000000000005',
            'resident_status' => ResidentStatus::ACTIVE->value,
        ]);

        Livewire::test(EditPenduduk::class, ['record' => $penduduk->getKey()])
            ->fillForm(['full_name' => 'Nama Diperbarui'])
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(EditPenduduk::class, ['record' => $penduduk->getKey()])
            ->fillForm(['full_name' => 'Nama Diperbarui Lagi'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1, KkAnggota::where('penduduk_id', $penduduk->getKey())
            ->where('status', KkAnggotaStatus::AKTIF->value)->count());
    }
}
