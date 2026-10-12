<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class UserResetAdmin extends BaseCommand
{
    protected $group       = 'MicronOS';
    protected $name        = 'user:reset-admin';
    protected $description = 'Resets the superadmin Ministrator password to default M1Cr0N.';

    public function run(array $params)
    {
        $db = \Config\Database::connect();

        $newPw = $params[0] ?? 'M1Cr0N';
        $hash = password_hash($newPw, PASSWORD_ARGON2ID);
        $user = $db->table('users')->where('username', 'Ministrator')->get()->getRowArray();

        if ($user) {
            $db->table('users')->where('id', $user['id'])->update([
                'password_hash' => $hash,
                'is_active'     => 1,
                'failed_logins' => 0,
                'locked_until'  => null,
                'totp_secret'   => null,
                'updated_at'    => date('Y-m-d H:i:s')
            ]);
            CLI::write("Success: Superadmin account 'Ministrator' password has been reset to '{$newPw}' (2FA cleared).", 'green');
        } else {
            // Create user
            $userId = 'usr_superadmin_01';
            $db->table('users')->insert([
                'id'            => $userId,
                'username'      => 'Ministrator',
                'email'         => 'admin@micronos.local',
                'password_hash' => $hash,
                'full_name'     => 'System Super Administrator',
                'status'        => 'active',
                'auth_source'   => 'local',
                'created_at'    => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s')
            ]);

            // Assign SuperAdmin role
            $db->table('user_roles')->insert([
                'user_id' => $userId,
                'role_id' => 'role_superadmin'
            ]);

            CLI::write("Success: Superadmin account 'Ministrator' created with password '{$newPw}' and SuperAdmin role assigned.", 'green');
        }
    }
}
