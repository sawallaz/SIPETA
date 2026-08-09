<?php

namespace Tests\Feature\PhaseUI;

use App\Enums\FamilyRelation;
use App\Filament\Resources\KartuKeluargas\KartuKeluargaResource;
use App\Filament\Resources\KartuKeluargas\Pages\ViewKartuKeluarga;
use App\Models\KartuKeluarga;
use App\Models\Penduduk;
use Livewire\Livewire;
use Tests\Feature\Phase3\Phase3ResourceTestCase;

/**
 * Phase UI-5 — "Lihat KK" detail page.
 *
 * Regression guard for the 500 caused by importing layout components from the
 * wrong namespace: `Filament\Infolists\Components` ships only *Entry* classes in
 * Filament 4, so `Infolists\Components\Section` does not exist and the infolist
 * blew up at render time. These tests render the real page (Livewire + HTTP) so
 * a wrong-namespace import can never ship silently again.
 */
class KartuKeluargaViewPageTest extends Phase3ResourceTestCase
{
    public function test_infolist_layout_components_come_from_the_schemas_namespace(): void
    {
        // Deliberately built at runtime: this class does NOT exist, so a literal
        // import would be rewritten/flagged by tooling.
        $missing = 'Filament\Infolists\Components\Section';

        $this->assertFalse(
            class_exists($missing),
            $missing.' must not be used — it does not exist in Filament 4.',
        );

        $source = file_get_contents(
            app_path('Filament/Resources/KartuKeluargas/KartuKeluargaResource.php')
        );

        $this->assertStringNotContainsString($missing, $source);
        $this->assertStringContainsString('use Filament\Schemas\Components\Section;', $source);
        $this->assertStringContainsString('use Filament\Schemas\Components\Grid;', $source);
    }

    public function test_view_page_renders(): void
    {
        $kk = KartuKeluarga::factory()->create([
            'kk_number' => '7371019900010001',
            'address' => 'Jl. Poros Tanete No. 7',
            'postal_code' => '90811',
        ]);

        Livewire::test(ViewKartuKeluarga::class, ['record' => $kk->getKey()])
            ->assertOk()
            ->assertSee('Foto Kartu Keluarga')
            ->assertSee('Data Kartu Keluarga')
            ->assertSee('Wilayah & Keluarga')
            ->assertSee('7371019900010001')
            ->assertSee('Jl. Poros Tanete No. 7');
    }

    public function test_view_page_responds_over_http(): void
    {
        $kk = KartuKeluarga::factory()->create();

        $this->get(KartuKeluargaResource::getUrl('view', ['record' => $kk]))
            ->assertSuccessful();
    }

    public function test_view_page_shows_head_of_family_and_member_count(): void
    {
        $kk = KartuKeluarga::factory()->create();

        Penduduk::factory()->create([
            'kk_id' => $kk->id,
            'full_name' => 'Sitti Aminah',
            'family_relation' => FamilyRelation::KEPALA_KELUARGA->value,
        ]);
        Penduduk::factory()->count(2)->create([
            'kk_id' => $kk->id,
            'family_relation' => FamilyRelation::ANAK->value,
        ]);

        Livewire::test(ViewKartuKeluarga::class, ['record' => $kk->getKey()])
            ->assertOk()
            ->assertSee('Sitti Aminah')
            ->assertSee('3 orang');
    }

    public function test_view_page_renders_without_photo(): void
    {
        $kk = KartuKeluarga::factory()->create();

        Livewire::test(ViewKartuKeluarga::class, ['record' => $kk->getKey()])
            ->assertOk()
            ->assertSee('Belum ada foto KK.');
    }
}
