<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class UserResourceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
    }

    public function test_admin_can_list_users_and_non_admin_cannot(): void
    {
        $operator = User::factory()->create();

        $this->get('/admin/users')->assertOk()->assertSee($operator->email);
        $this->get('/admin/users/create')->assertOk();

        $this->actingAs($operator)->get('/admin/users')->assertForbidden();
    }

    public function test_admin_can_create_user_with_12_char_password(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Nova Operadora',
                'email' => 'nova@example.com',
                'password' => 'exactly12chr',
                'is_admin' => false,
                'is_active' => true,
                'monthly_cost_limit' => 150.5,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->where('email', 'nova@example.com')->firstOrFail();

        $this->assertSame('Nova Operadora', $user->name);
        $this->assertFalse($user->is_admin);
        $this->assertTrue($user->is_active);
        $this->assertSame('150.50', $user->monthly_cost_limit);
        $this->assertNotSame('exactly12chr', $user->password);
        $this->assertTrue(Hash::check('exactly12chr', $user->password));
    }

    public function test_password_shorter_than_12_is_rejected(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Senha Curta',
                'email' => 'curta@example.com',
                'password' => 'only11chars',
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);

        $this->assertDatabaseMissing('users', ['email' => 'curta@example.com']);
    }

    public function test_password_is_required_on_create_and_optional_on_edit(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Sem Senha',
                'email' => 'sem-senha@example.com',
                'password' => '',
            ])
            ->call('create')
            ->assertHasFormErrors(['password' => 'required']);

        $user = User::factory()->create();
        $originalHash = $user->password;

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['name' => 'Renomeado', 'password' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        $this->assertSame('Renomeado', $user->name);
        $this->assertSame($originalHash, $user->password);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['password' => 'brand-new-password'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
    }

    public function test_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Duplicado',
                'email' => 'taken@example.com',
                'password' => 'a-long-enough-password',
            ])
            ->call('create')
            ->assertHasFormErrors(['email' => 'unique']);
    }

    public function test_cannot_remove_last_admin_flag(): void
    {
        // $this->admin is the only active administrator.
        Livewire::test(EditUser::class, ['record' => $this->admin->getRouteKey()])
            ->fillForm(['is_admin' => false])
            ->call('save')
            ->assertHasFormErrors(['is_admin']);

        Livewire::test(EditUser::class, ['record' => $this->admin->getRouteKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasFormErrors(['is_active']);

        $this->admin->refresh();
        $this->assertTrue($this->admin->is_admin);
        $this->assertTrue($this->admin->is_active);

        // With a second active admin the change is allowed.
        User::factory()->admin()->create();

        Livewire::test(EditUser::class, ['record' => $this->admin->getRouteKey()])
            ->fillForm(['is_admin' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($this->admin->fresh()->is_admin);
    }

    public function test_user_changes_are_audited(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Auditada',
                'email' => 'auditada@example.com',
                'password' => 'a-long-enough-password',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->where('email', 'auditada@example.com')->firstOrFail();

        $created = AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::USER_CREATED))->firstOrFail();
        $this->assertTrue($created->user->is($this->admin));
        $this->assertTrue($created->subject->is($user));
        $this->assertSame('auditada@example.com', $created->properties['email']);
        $this->assertStringNotContainsString('a-long-enough-password', json_encode($created->properties));

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['name' => 'Auditada Editada', 'password' => 'another-long-password'])
            ->call('save')
            ->assertHasNoFormErrors();

        $updated = AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::USER_UPDATED))->firstOrFail();
        $this->assertTrue($updated->subject->is($user));
        $this->assertContains('name', $updated->properties['changed']);
        $this->assertContains('password', $updated->properties['changed']);
        $this->assertArrayNotHasKey('password', $updated->properties['changes']);
        $this->assertSame('Auditada Editada', $updated->properties['changes']['name']);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $deactivated = AuditLog::query()->where('audit_action_id', AuditAction::idFor(AuditAction::USER_DEACTIVATED))->firstOrFail();
        $this->assertTrue($deactivated->user->is($this->admin));
        $this->assertTrue($deactivated->subject->is($user));
        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_table_filters_by_admin_and_active_flags(): void
    {
        $inactive = User::factory()->inactive()->create(['name' => 'Inativa Pessoa']);
        $operator = User::factory()->create(['name' => 'Operador Comum']);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$this->admin, $inactive, $operator])
            ->filterTable('is_admin', true)
            ->assertCanSeeTableRecords([$this->admin])
            ->assertCanNotSeeTableRecords([$inactive, $operator])
            ->resetTableFilters()
            ->filterTable('is_active', false)
            ->assertCanSeeTableRecords([$inactive])
            ->assertCanNotSeeTableRecords([$this->admin, $operator])
            ->resetTableFilters()
            ->searchTable('Operador')
            ->assertCanSeeTableRecords([$operator])
            ->assertCanNotSeeTableRecords([$this->admin, $inactive]);
    }
}
