<?php

namespace Tests\Feature\Phase2;

use App\Enums\BloodType;
use App\Enums\Gender;
use App\Enums\ResidentStatus;
use App\Models\AuditLog;
use App\Models\Education;
use App\Models\KartuKeluarga;
use App\Models\KkPhoto;
use App\Models\Occupation;
use App\Models\OcrJob;
use App\Models\Penduduk;
use App\Models\Religion;
use App\Models\Rt;
use Carbon\Carbon;

/**
 * Eloquent relation, scope, and accessor verification (Phase 2.4 models).
 */
class RelationAndScopeTest extends Phase2TestCase
{
    public function test_penduduk_belongs_to_kartu_keluarga(): void
    {
        $kk = KartuKeluarga::factory()->create();
        $penduduk = Penduduk::factory()->create(['kk_id' => $kk->id]);

        $this->assertInstanceOf(KartuKeluarga::class, $penduduk->kartuKeluarga);
        $this->assertSame($kk->id, $penduduk->kartuKeluarga->id);
        $this->assertTrue($kk->penduduks->contains($penduduk));
    }

    public function test_penduduk_belongs_to_lookup_masters_and_rt(): void
    {
        $penduduk = Penduduk::factory()->create();

        $this->assertInstanceOf(Religion::class, $penduduk->religion);
        $this->assertInstanceOf(Education::class, $penduduk->education);
        $this->assertInstanceOf(Occupation::class, $penduduk->occupation);
        $this->assertInstanceOf(Rt::class, $penduduk->rt);
        $this->assertSame($penduduk->rt->id, $penduduk->rt_id);
    }

    public function test_kartu_keluarga_has_photos_and_ocr_jobs(): void
    {
        $kk = KartuKeluarga::factory()->create();
        KkPhoto::factory()->count(2)->create(['kk_id' => $kk->id]);
        OcrJob::factory()->count(2)->create(['kk_id' => $kk->id]);

        $this->assertCount(2, $kk->kkPhotos);
        $this->assertCount(2, $kk->ocrJobs);
    }

    public function test_scope_active_returns_only_active_residents(): void
    {
        $kk = KartuKeluarga::factory()->create();
        Penduduk::factory()->count(3)->create(['kk_id' => $kk->id, 'resident_status' => ResidentStatus::ACTIVE->value]);
        Penduduk::factory()->create(['kk_id' => $kk->id, 'resident_status' => ResidentStatus::PINDAH->value]);
        Penduduk::factory()->create(['kk_id' => $kk->id, 'resident_status' => ResidentStatus::MENINGGAL->value]);

        $this->assertSame(3, Penduduk::query()->active()->count());
    }

    public function test_age_accessor_never_stored(): void
    {
        $birthDate = '2000-01-01';
        $penduduk = Penduduk::factory()->create(['birth_date' => $birthDate]);

        $expected = Carbon::parse($birthDate)->age;
        $this->assertSame($expected, $penduduk->age);
        $this->assertArrayNotHasKey('age', $penduduk->getAttributes());
    }

    public function test_invalid_enum_value_throws(): void
    {
        $this->expectException(\ValueError::class);

        Penduduk::factory()->create([
            'resident_status' => 'NOT_A_STATUS',
        ]);
    }

    public function test_enum_casts_round_trip(): void
    {
        $penduduk = Penduduk::factory()->create([
            'gender' => Gender::PEREMPUAN->value,
            'blood_type' => BloodType::O->value,
            'resident_status' => ResidentStatus::PINDAH->value,
        ]);

        $fresh = $penduduk->fresh();
        $this->assertInstanceOf(Gender::class, $fresh->gender);
        $this->assertSame(Gender::PEREMPUAN, $fresh->gender);
        $this->assertSame(BloodType::O, $fresh->blood_type);
        $this->assertSame(ResidentStatus::PINDAH, $fresh->resident_status);
    }

    public function test_audit_log_morph_relation_from_model(): void
    {
        $kk = KartuKeluarga::factory()->create();
        AuditLog::create([
            'loggable_type' => $kk->getMorphClass(),
            'loggable_id' => $kk->id,
            'event' => 'created',
        ]);

        $this->assertSame(1, $kk->audits()->count());
    }
}
