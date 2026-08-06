<?php

namespace Tests\Feature\Phase4;

use App\Filament\Widgets\QuickActionsWidget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase 4.5 — quick actions widget.
 *
 * Verifies the widget renders on the dashboard with all four shortcuts
 * (Tambah / Data for Kartu Keluarga and Penduduk), and that every shortcut
 * points to an existing Filament resource route.
 */
class QuickActionsWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->actingAs($this->admin);
    }

    public function test_dashboard_renders_quick_actions_widget(): void
    {
        $this->get('/admin')
            ->assertOk()
            ->assertSee('Aksi Cepat');
    }

    public function test_widget_exposes_four_actions(): void
    {
        $actions = invade(new QuickActionsWidget)->getViewData()['actions'];

        $this->assertCount(4, $actions);
        $this->assertSame(
            [
                'Tambah Kartu Keluarga',
                'Tambah Penduduk',
                'Data Kartu Keluarga',
                'Data Penduduk',
            ],
            collect($actions)->pluck('label')->all(),
        );
    }

    public function test_all_four_actions_visible_on_dashboard(): void
    {
        $this->get('/admin')
            ->assertOk()
            ->assertSee('Aksi Cepat')
            ->assertSee('Tambah Kartu Keluarga')
            ->assertSee('Tambah Penduduk')
            ->assertSee('Data Kartu Keluarga')
            ->assertSee('Data Penduduk');
    }

    public function test_every_action_points_to_an_existing_filament_route(): void
    {
        $actions = invade(new QuickActionsWidget)->getViewData()['actions'];

        $expected = [
            'Tambah Kartu Keluarga' => [
                'route' => 'filament.admin.resources.kartu-keluargas.create',
                'path' => '/admin/kartu-keluargas/create',
            ],
            'Tambah Penduduk' => [
                'route' => 'filament.admin.resources.penduduks.create',
                'path' => '/admin/penduduks/create',
            ],
            'Data Kartu Keluarga' => [
                'route' => 'filament.admin.resources.kartu-keluargas.index',
                'path' => '/admin/kartu-keluargas',
            ],
            'Data Penduduk' => [
                'route' => 'filament.admin.resources.penduduks.index',
                'path' => '/admin/penduduks',
            ],
        ];

        $this->assertSame(array_keys($expected), collect($actions)->pluck('label')->all());

        foreach ($actions as $action) {
            $this->assertTrue(
                Route::has($expected[$action['label']]['route']),
                "Route {$expected[$action['label']]['route']} is not registered",
            );
            $this->assertSame(route($expected[$action['label']]['route']), $action['url']);
            $this->assertStringContainsString($expected[$action['label']]['path'], $action['url']);
        }
    }
}
