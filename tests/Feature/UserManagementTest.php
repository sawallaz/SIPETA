<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@test.com',
            'password' => bcrypt('password'),
            'role' => UserRole::SUPER_ADMIN,
        ]);

        $this->operator = User::create([
            'name' => 'Operator',
            'email' => 'operator@test.com',
            'password' => bcrypt('password'),
            'role' => UserRole::OPERATOR,
        ]);
    }

    // =========================================================
    // AUTHORIZATION
    // =========================================================

    public function test_super_admin_can_see_user_management(): void
    {
        $this->actingAs($this->superAdmin);

        $response = $this->get('/admin/users');

        $response->assertSuccessful();
    }

    public function test_operator_gets_403_on_user_management(): void
    {
        $this->actingAs($this->operator);

        $response = $this->get('/admin/users');

        $response->assertStatus(403);
    }

    public function test_operator_gets_403_on_user_create(): void
    {
        $this->actingAs($this->operator);

        $response = $this->get('/admin/users/create');

        $response->assertStatus(403);
    }

    public function test_operator_gets_403_on_user_edit(): void
    {
        $this->actingAs($this->operator);

        $response = $this->get("/admin/users/{$this->superAdmin->id}/edit");

        $response->assertStatus(403);
    }

    // =========================================================
    // CREATE OPERATOR — via Livewire
    // =========================================================

    public function test_super_admin_can_create_operator(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New Operator',
                'email' => 'newoperator@test.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'newoperator@test.com',
            'role' => UserRole::OPERATOR->value,
        ]);
    }

    public function test_created_user_role_is_operator(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New Operator 2',
                'email' => 'newoperator2@test.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'newoperator2@test.com')->first();

        $this->assertNotNull($user);
        $this->assertEquals(UserRole::OPERATOR, $user->role);
    }

    public function test_password_is_hashed(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New Operator 3',
                'email' => 'newoperator3@test.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'newoperator3@test.com')->first();

        $this->assertNotNull($user);
        $this->assertNotEquals('password123', $user->password);
        $this->assertTrue(password_verify('password123', $user->password));
    }

    public function test_duplicate_email_rejected(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Duplicate',
                'email' => 'superadmin@test.com', // already exists
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->call('create')
            ->assertHasFormErrors(['email']);
    }

    // =========================================================
    // EDIT OPERATOR — via Livewire
    // =========================================================

    public function test_super_admin_can_edit_operator(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(EditUser::class, ['record' => $this->operator->getKey()])
            ->fillForm([
                'name' => 'Updated Operator',
                'email' => 'operator@test.com',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('users', [
            'id' => $this->operator->id,
            'name' => 'Updated Operator',
        ]);
    }

    // =========================================================
    // CHANGE PASSWORD — via Livewire table action
    // =========================================================

    public function test_super_admin_can_change_operator_password(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(ListUsers::class)
            ->callTableAction('change_password', $this->operator, data: [
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ])
            ->assertHasNoTableActionErrors();

        $this->operator->refresh();
        $this->assertTrue(password_verify('newpassword123', $this->operator->password));
    }

    public function test_operator_cannot_access_user_management(): void
    {
        $this->actingAs($this->operator);

        $this->get('/admin/users')->assertStatus(403);
        $this->get('/admin/users/create')->assertStatus(403);
        $this->get("/admin/users/{$this->superAdmin->id}/edit")->assertStatus(403);
    }

    // =========================================================
    // DELETE — policy guards
    // =========================================================

    public function test_super_admin_can_delete_operator(): void
    {
        $this->actingAs($this->superAdmin);

        $operatorToDelete = User::create([
            'name' => 'Operator To Delete',
            'email' => 'todelete@test.com',
            'password' => bcrypt('password'),
            'role' => UserRole::OPERATOR,
        ]);

        Livewire::test(ListUsers::class)
            ->callTableAction('delete', $operatorToDelete)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('users', [
            'id' => $operatorToDelete->id,
        ]);
    }

    public function test_super_admin_cannot_delete_self(): void
    {
        $this->actingAs($this->superAdmin);

        $this->assertFalse(
            auth()->user()->can('delete', $this->superAdmin)
        );
    }

    public function test_last_super_admin_cannot_be_deleted(): void
    {
        $this->actingAs($this->superAdmin);

        $this->assertFalse(
            auth()->user()->can('delete', $this->superAdmin)
        );
    }

    // =========================================================
    // POLICY UNIT CHECKS
    // =========================================================

    public function test_user_policy_blocks_operator_from_viewing_users(): void
    {
        $this->assertFalse($this->operator->can('viewAny', User::class));
    }

    public function test_user_policy_allows_super_admin_to_view_users(): void
    {
        $this->assertTrue($this->superAdmin->can('viewAny', User::class));
    }

    public function test_user_policy_blocks_operator_from_creating_users(): void
    {
        $this->assertFalse($this->operator->can('create', User::class));
    }

    public function test_user_policy_blocks_operator_from_editing_users(): void
    {
        $this->assertFalse($this->operator->can('update', $this->superAdmin));
    }

    public function test_user_policy_blocks_operator_from_deleting_users(): void
    {
        $this->assertFalse($this->operator->can('delete', $this->superAdmin));
    }
}
