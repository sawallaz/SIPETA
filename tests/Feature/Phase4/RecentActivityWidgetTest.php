<?php

namespace Tests\Feature\Phase4;

use App\Filament\Widgets\RecentActivityWidget;
use App\Models\KartuKeluarga;
use App\Models\Penduduk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4.4 — recent activity widget.
 *
 * Verifies the widget renders on the dashboard, shows a Filament empty state
 * when there is no data, and lists the 5 newest Kartu Keluarga and the 5
 * newest Penduduk (newest first), each linking to its edit page.
 */
class RecentActivityWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->actingAs($this->admin);
    }

    public function test_dashboard_renders_recent_activity_widget(): void
    {
        $this->get('/admin')
            ->assertOk()
            ->assertSee('Aktivitas Terbaru');
    }

    public function test_widget_shows_empty_state_when_no_data(): void
    {
        $this->get('/admin')
            ->assertOk()
            ->assertSee('Aktivitas Terbaru')
            ->assertSee('Belum ada aktivitas');

        $this->assertTrue(invade(new RecentActivityWidget)->getViewData()['activities']->isEmpty());
    }

    public function test_widget_lists_five_newest_kartu_keluarga(): void
    {
        // Six KKs, created one minute apart; only the five newest may appear,
        // in newest-first order, each linking to its edit page.
        $kks = collect(range(1, 6))->map(
            fn (int $i): KartuKeluarga => KartuKeluarga::factory()->create([
                'created_at' => now()->subMinutes($i),
            ]),
        );

        $kkActivities = invade(new RecentActivityWidget)->getViewData()['activities']
            ->where('icon', 'heroicon-o-home-modern')
            ->values();

        $this->assertCount(5, $kkActivities);
        $this->assertSame(
            $kks->take(5)->map(fn (KartuKeluarga $kk): string => "KK {$kk->kk_number}")->all(),
            $kkActivities->pluck('title')->all(),
        );
        $this->assertSame(
            $kks->take(5)->map(fn (KartuKeluarga $kk): string => $kk->address)->all(),
            $kkActivities->pluck('subtitle')->all(),
        );
        foreach ($kkActivities->pluck('url') as $url) {
            $this->assertStringContainsString('/edit', $url);
        }
    }

    public function test_widget_lists_five_newest_penduduk(): void
    {
        // Six residents, created one minute apart; only the five newest may
        // appear, in newest-first order, each linking to its edit page.
        $penduduks = collect(range(1, 6))->map(
            fn (int $i): Penduduk => Penduduk::factory()->create([
                'created_at' => now()->subMinutes($i),
            ]),
        );

        $pendudukActivities = invade(new RecentActivityWidget)->getViewData()['activities']
            ->where('icon', 'heroicon-o-user')
            ->values();

        $this->assertCount(5, $pendudukActivities);
        $this->assertSame(
            $penduduks->take(5)->pluck('full_name')->all(),
            $pendudukActivities->pluck('title')->all(),
        );
        foreach ($pendudukActivities->pluck('url') as $url) {
            $this->assertStringContainsString('/edit', $url);
        }
    }
}
