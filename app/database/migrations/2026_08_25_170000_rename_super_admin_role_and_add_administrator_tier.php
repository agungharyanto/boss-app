<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * v0.9.2 — two-tier admin RBAC. Renames the existing 'super_admin' Spatie
 * role row to 'superadmin' IN PLACE (UPDATE, not delete+recreate) so every
 * existing model_has_roles/role_has_permissions pivot row (already keyed by
 * role_id, not name) survives untouched — a real user already holding this
 * role keeps holding it, just under its new name, with zero re-assignment
 * needed. A brand-new 'administrator' role is then created and given every
 * permission 'superadmin' currently has — see CLAUDE.md's own "Two-Tier
 * Admin: superadmin vs administrator" section for the full rationale
 * (superadmin is reserved for a future role/permission-management
 * capability that does not exist yet in this codebase; administrator
 * deliberately does not get it automatically once it does).
 *
 * On a genuinely fresh install, `roles` is still empty when this runs
 * (schema migrations run before RolesAndPermissionsSeeder is ever invoked
 * per this project's own documented install order) — every step below is a
 * safe no-op in that case, and the seeder's own updated code creates both
 * roles directly with the right permissions from the start.
 */
return new class extends Migration
{
    public function up(): void
    {
        $superAdminId = DB::table('roles')
            ->where('name', 'super_admin')
            ->where('guard_name', 'web')
            ->value('id');

        if ($superAdminId === null) {
            // Fresh install (roles table empty) or already renamed by a
            // previous run of this migration — nothing to do.
            return;
        }

        DB::table('roles')->where('id', $superAdminId)->update(['name' => 'superadmin']);

        $administratorId = DB::table('roles')
            ->where('name', 'administrator')
            ->where('guard_name', 'web')
            ->value('id');

        if ($administratorId === null) {
            $administratorId = DB::table('roles')->insertGetId([
                'name' => 'administrator',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $superAdminPermissionIds = DB::table('role_has_permissions')
            ->where('role_id', $superAdminId)
            ->pluck('permission_id');

        $alreadyGranted = DB::table('role_has_permissions')
            ->where('role_id', $administratorId)
            ->pluck('permission_id');

        $rows = $superAdminPermissionIds
            ->diff($alreadyGranted)
            ->map(fn ($permissionId) => [
                'permission_id' => $permissionId,
                'role_id' => $administratorId,
            ])
            ->all();

        if ($rows !== []) {
            DB::table('role_has_permissions')->insert($rows);
        }

        // Spatie caches the whole roles/permissions graph — without this,
        // a request served by an already-booted worker could keep using
        // the stale pre-rename cache until it naturally expires.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $administratorId = DB::table('roles')
            ->where('name', 'administrator')
            ->where('guard_name', 'web')
            ->value('id');

        if ($administratorId !== null) {
            DB::table('model_has_roles')->where('role_id', $administratorId)->delete();
            DB::table('role_has_permissions')->where('role_id', $administratorId)->delete();
            DB::table('roles')->where('id', $administratorId)->delete();
        }

        DB::table('roles')
            ->where('name', 'superadmin')
            ->where('guard_name', 'web')
            ->update(['name' => 'super_admin']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
