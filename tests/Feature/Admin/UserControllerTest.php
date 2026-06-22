<?php

use App\Models\User;

test('admin can delete another user', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $user))
        ->assertRedirect(route('admin.users.index'))
        ->assertSessionHas('status', 'Usuário excluído com sucesso.');

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
});

test('non admin cannot delete a user', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $target = User::factory()->create();

    $this->actingAs($user)
        ->delete(route('admin.users.destroy', $target))
        ->assertForbidden();

    $this->assertDatabaseHas('users', ['id' => $target->id]);
});

test('admin cannot delete their own account', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $admin))
        ->assertRedirect(route('admin.users.index'))
        ->assertSessionHas('error', 'Você não pode excluir a própria conta.');

    $this->assertDatabaseHas('users', ['id' => $admin->id]);
});

test('users page shows delete action only for other users', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();

    $this->withoutVite();

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee(route('admin.users.destroy', $user), escape: false)
        ->assertDontSee(route('admin.users.destroy', $admin), escape: false);
});
