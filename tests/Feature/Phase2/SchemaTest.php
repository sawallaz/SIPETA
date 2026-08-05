<?php

namespace Tests\Feature\Phase2;

use Illuminate\Support\Facades\Schema;

/**
 * Static schema verification: tables, unique indexes, expected indexes,
 * foreign-key rules, and absence of soft-delete columns.
 */
class SchemaTest extends Phase2TestCase
{
    public function test_all_domain_tables_exist(): void
    {
        $tables = [
            'settings', 'kartu_keluarga', 'penduduk', 'kk_anggota', 'kk_photos',
            'ocr_jobs', 'backup_logs', 'audit_logs', 'religions', 'educations',
            'occupations', 'area_units', 'rts',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Table {$table} is missing.");
        }
    }

    public function test_unique_constraints_present(): void
    {
        $this->assertUniqueIndex('kartu_keluarga', 'kartu_keluarga_kk_number_unique');
        $this->assertUniqueIndex('penduduk', 'penduduk_nik_unique');
        $this->assertUniqueIndex('backup_logs', 'backup_logs_filename_unique');
        $this->assertUniqueIndex('religions', 'religions_name_unique');
        $this->assertUniqueIndex('educations', 'educations_name_unique');
        $this->assertUniqueIndex('occupations', 'occupations_name_unique');
        $this->assertUniqueIndex('area_units', 'area_units_name_unique');
        $this->assertUniqueIndex('rts', 'rts_area_unit_id_number_unique');
    }

    public function test_approved_indexes_present(): void
    {
        // Primary key indexes plus the documented search/filter indexes.
        $this->assertHasIndex('kartu_keluarga', 'kartu_keluarga_address_index');
        $this->assertHasIndex('penduduk', 'penduduk_full_name_index');
        $this->assertHasIndex('penduduk', 'penduduk_resident_status_index');
        $this->assertHasIndex('penduduk', 'penduduk_gender_index');
        $this->assertHasIndex('penduduk', 'penduduk_birth_date_index');
        $this->assertHasIndex('penduduk', 'penduduk_rt_id_index');
        $this->assertHasIndex('penduduk', 'penduduk_religion_id_index');
        $this->assertHasIndex('penduduk', 'penduduk_education_id_index');
        $this->assertHasIndex('penduduk', 'penduduk_occupation_id_index');
        $this->assertHasIndex('penduduk', 'penduduk_kk_id_resident_status_index');
        $this->assertHasIndex('penduduk', 'penduduk_blood_type_index');
        $this->assertHasIndex('kk_photos', 'kk_photos_kk_id_is_active_index');
        $this->assertHasIndex('kk_photos', 'kk_photos_sha256_hash_index');
        $this->assertHasIndex('kk_photos', 'kk_photos_ocr_job_id_index');
        $this->assertHasIndex('kk_photos', 'kk_photos_uploaded_by_index');
        $this->assertHasIndex('ocr_jobs', 'ocr_jobs_source_image_hash_index');
        $this->assertHasIndex('ocr_jobs', 'ocr_jobs_status_created_at_index');
        $this->assertHasIndex('audit_logs', 'audit_logs_loggable_type_loggable_id_index');
        $this->assertHasIndex('audit_logs', 'audit_logs_actor_id_index');
        $this->assertHasIndex('audit_logs', 'audit_logs_created_at_index');
    }

    public function test_audit_fix_indexes_present(): void
    {
        // The two indexes added to close PHASE2-AUDIT §11 findings.
        $this->assertHasIndex('backup_logs', 'backup_logs_started_at_index');
        $this->assertHasIndex('ocr_jobs', 'ocr_jobs_kk_id_index');
    }

    public function test_foreign_key_rules(): void
    {
        // penduduk FKs — all RESTRICT on delete, cascade on update.
        $this->assertForeignKey('penduduk', 'kk_id', 'kartu_keluarga', 'restrict');
        $this->assertForeignKey('penduduk', 'religion_id', 'religions', 'restrict');
        $this->assertForeignKey('penduduk', 'education_id', 'educations', 'restrict');
        $this->assertForeignKey('penduduk', 'occupation_id', 'occupations', 'restrict');
        $this->assertForeignKey('penduduk', 'rt_id', 'rts', 'restrict');

        // kk_anggota FKs — RESTRICT on both ends.
        $this->assertForeignKey('kk_anggota', 'kk_id', 'kartu_keluarga', 'restrict');
        $this->assertForeignKey('kk_anggota', 'penduduk_id', 'penduduk', 'restrict');

        // rts FK — RESTRICT.
        $this->assertForeignKey('rts', 'area_unit_id', 'area_units', 'restrict');

        // kk_photos FKs — kk_id RESTRICT; uploaded_by + ocr_job_id SET NULL.
        $this->assertForeignKey('kk_photos', 'kk_id', 'kartu_keluarga', 'restrict');
        $this->assertForeignKey('kk_photos', 'uploaded_by', 'users', 'set null');
        $this->assertForeignKey('kk_photos', 'ocr_job_id', 'ocr_jobs', 'set null');

        // ocr_jobs FKs — kk_id + operator_id SET NULL.
        $this->assertForeignKey('ocr_jobs', 'kk_id', 'kartu_keluarga', 'set null');
        $this->assertForeignKey('ocr_jobs', 'operator_id', 'users', 'set null');

        // backup_logs operator SET NULL.
        $this->assertForeignKey('backup_logs', 'operator_id', 'users', 'set null');
    }

    public function test_no_soft_delete_columns(): void
    {
        $this->assertNoSoftDeletes();
    }
}
