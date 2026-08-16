<?php
// ============================================================
// permissions.php — the authoritative role permission matrix.
// This is the SOURCE OF TRUTH for server-side enforcement.
// A parallel copy for UI purposes (hiding nav links) lives in
// permissions.js — keep both in sync if this changes.
//
// Roles: System Admin, Barangay Captain, Desk Officer, Data Encoder
// ============================================================

const PERMISSIONS = [
    // key                => [System Admin, Barangay Captain, Desk Officer, Data Encoder]
    'view_records'        => ['System Admin' => true,  'Barangay Captain' => true,  'Desk Officer' => true,  'Data Encoder' => true],
    'add_blotter'         => ['System Admin' => true,  'Barangay Captain' => true,  'Desk Officer' => true,  'Data Encoder' => true],
    'edit_records'        => ['System Admin' => true,  'Barangay Captain' => true,  'Desk Officer' => true,  'Data Encoder' => false],
    'delete_records'      => ['System Admin' => true,  'Barangay Captain' => true,  'Desk Officer' => false, 'Data Encoder' => false],
    'generate_reports'    => ['System Admin' => true,  'Barangay Captain' => true,  'Desk Officer' => true,  'Data Encoder' => false],
    'view_analytics'      => ['System Admin' => true,  'Barangay Captain' => true,  'Desk Officer' => true,  'Data Encoder' => false],
    'manage_users'        => ['System Admin' => true,  'Barangay Captain' => true,  'Desk Officer' => false, 'Data Encoder' => false],
    'retrain_ml'          => ['System Admin' => true,  'Barangay Captain' => true,  'Desk Officer' => false, 'Data Encoder' => false],
    'import_data'         => ['System Admin' => true,  'Barangay Captain' => true,  'Desk Officer' => false, 'Data Encoder' => true],
    'system_settings'     => ['System Admin' => true,  'Barangay Captain' => false, 'Desk Officer' => false, 'Data Encoder' => false],
];

/** Does the given role have the given permission? Unknown role/permission => false (fail closed). */
function role_can(string $role, string $permission): bool {
    return PERMISSIONS[$permission][$role] ?? false;
}

/** Require a permission for the current session's role, or respond 403 and exit. Call require_login() first. */
function require_permission(string $permission): void {
    $role = $_SESSION['role'] ?? '';
    if (!role_can($role, $permission)) {
        json_error('You do not have permission to perform this action.', 403);
    }
}
