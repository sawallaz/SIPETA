<?php

namespace Tests\Feature\Phase2;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Enums\KkAnggotaStatus;
use App\Models\AuditLog;
use App\Models\BackupLog;
use App\Models\KartuKeluarga;
use App\Models\KkAnggota;
use App\Models\KkPhoto;
use App\Models\OcrJob;
use App\Models\Penduduk;
use App\Models\User;
use Illuminate\Database\QueryException;

/**
 * Behavioural verification: FK enforcement, RESTRICT vs SET NULL cascade,
 * membership-history (KK re-issue) preservation, and append-only tables.
 *
 * These tests rely on real foreign-key enforcement (PRAGMA foreign_keys = ON
 * on SQLite; InnoDB on MySQL) — hence DatabaseMigrations, not RefreshDatabase.
 */
class DatabaseBehaviourTest extends Phase2TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_unique_nik_rejected(): void
    {
        $kk = KartuKeluarga::factory()->create();
        $nik = '1234567890123456';

        Penduduk::factory()->create(['kk_id' => $kk->id, 'nik' => $nik]);

        $this->expectException(QueryException::class);
        Penduduk::factory()->create(['kk_id' => $kk->id, 'nik' => $nik]);
    }

    public function test_unique_kk_number_rejected(): void
    {
        $kkNumber = '1234567890123456';
        KartuKeluarga::factory()->create(['kk_number' => $kkNumber]);

        $this->expectException(QueryException::class);
        KartuKeluarga::factory()->create(['kk_number' => $kkNumber]);
    }

    public function test_restrict_blocks_kk_delete_when_residents_exist(): void
    {
        $kk = KartuKeluarga::factory()->create();
        Penduduk::factory()->count(2)->create(['kk_id' => $kk->id]);

        $this->expectException(QueryException::class);
        $kk->delete();
    }

    public function test_restrict_blocks_kk_delete_when_membership_history_exists(): void
    {
        $kk = KartuKeluarga::factory()->create();
        $penduduk = Penduduk::factory()->create(['kk_id' => $kk->id]);
        KkAnggota::factory()->create([
            'kk_id' => $kk->id,
            'penduduk_id' => $penduduk->id,
        ]);

        $this->expectException(QueryException::class);
        $kk->delete();
    }

    public function test_ocr_jobs_kk_id_set_null_on_kk_delete(): void
    {
        $kk = KartuKeluarga::factory()->create();
        $ocr = OcrJob::factory()->create(['kk_id' => $kk->id]);

        // No resident linked, so the RESTRICT side of other tables is not hit.
        $kk->delete();

        $this->assertNull($ocr->fresh()->kk_id);
    }

    public function test_kk_photos_uploaded_by_set_null_when_user_deleted(): void
    {
        $user = User::factory()->create();
        $kk = KartuKeluarga::factory()->create();
        $photo = KkPhoto::factory()->create([
            'kk_id' => $kk->id,
            'uploaded_by' => $user->id,
        ]);

        $user->delete();

        $this->assertNull($photo->fresh()->uploaded_by);
    }

    public function test_membership_history_preserved_on_kk_reissue(): void
    {
        // Resident in old KK; re-issued to a new KK number (Q1 pattern).
        $oldKk = KartuKeluarga::factory()->create(['kk_number' => '1111111111111111']);
        $penduduk = Penduduk::factory()->create(['kk_id' => $oldKk->id]);

        // Old membership marked KELUAR with end_date.
        $oldMembership = KkAnggota::factory()->create([
            'kk_id' => $oldKk->id,
            'penduduk_id' => $penduduk->id,
            'status' => KkAnggotaStatus::AKTIF->value,
        ]);

        $newKk = KartuKeluarga::factory()->create(['kk_number' => '2222222222222222']);
        Penduduk::withoutEvents(fn () => $penduduk->update(['kk_id' => $newKk->id]));

        $oldMembership->update([
            'status' => KkAnggotaStatus::KELUAR->value,
            'end_date' => now()->toDateString(),
        ]);

        KkAnggota::factory()->create([
            'kk_id' => $newKk->id,
            'penduduk_id' => $penduduk->id,
            'status' => KkAnggotaStatus::AKTIF->value,
        ]);

        // The resident's current KK pointer moved to the new number.
        $this->assertSame($newKk->id, $penduduk->fresh()->kk_id);
        // History is preserved: old link still exists and is KELUAR.
        $this->assertSame(
            1,
            KkAnggota::where('penduduk_id', $penduduk->id)
                ->where('status', KkAnggotaStatus::KELUAR->value)
                ->count(),
        );
        // Two membership rows total -> old + new.
        $this->assertSame(2, KkAnggota::where('penduduk_id', $penduduk->id)->count());
    }

    public function test_audit_logs_is_append_only_no_updated_at(): void
    {
        $kk = KartuKeluarga::factory()->create();
        $log = AuditLog::create([
            'loggable_type' => $kk->getMorphClass(),
            'loggable_id' => $kk->id,
            'event' => 'created',
            'new_values' => ['kk_number' => $kk->kk_number],
        ]);

        $this->assertNotNull($log->created_at);
        $this->assertNull($log->fresh()->updated_at);

        // Deleting the audited KK must NOT remove the audit row (morphic, no hard FK).
        $kk->delete();
        $this->assertNotNull($log->fresh());
    }

    public function test_backup_logs_is_append_only_no_updated_at(): void
    {
        $log = BackupLog::create([
            'filename' => 'backup_'.now()->format('Ymd_His').'_'.uniqid().'.zip',
            'backup_type' => BackupType::MANUAL->value,
            'backup_status' => BackupStatus::SUCCESS->value,
            'backup_size' => 12345,
            'started_at' => now(),
        ]);

        $this->assertNotNull($log->created_at);
        $this->assertNull($log->fresh()->updated_at);
    }

    public function test_audit_log_morphic_relation_resolves(): void
    {
        $kk = KartuKeluarga::factory()->create();
        AuditLog::create([
            'loggable_type' => $kk->getMorphClass(),
            'loggable_id' => $kk->id,
            'event' => 'created',
        ]);

        $this->assertSame(1, $kk->audits()->count());
        $this->assertInstanceOf(AuditLog::class, $kk->audits()->first());
    }
}
