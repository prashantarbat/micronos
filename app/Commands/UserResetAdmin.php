<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\DefaultCredentials;

class UserResetAdmin extends BaseCommand
{
    protected $group       = 'MicronOS';
    protected $name        = 'user:reset-admin';
    protected $description = 'Resets the superadmin Ministrator password (there is no default password).';
    protected $usage       = 'user:reset-admin [password] [--from-env] [--generate]';
    protected $options     = [
        '--from-env' => 'Read the password from the MICRON_ADMIN_PW environment variable (keeps it out of the process list).',
        '--generate' => 'Generate a random password and print it once.',
    ];

    public function run(array $params)
    {
        $db = \Config\Database::connect();

        $generated = false;
        if (CLI::getOption('from-env') !== null || array_key_exists('from-env', $params)) {
            $newPw = (string) (getenv('MICRON_ADMIN_PW') ?: '');
        } elseif (CLI::getOption('generate') !== null || array_key_exists('generate', $params)) {
            $newPw     = DefaultCredentials::generate();
            $generated = true;
        } else {
            $newPw = (string) ($params[0] ?? '');
        }

        if ($newPw === '') {
            CLI::error('A new password is required: pass it as an argument, use --from-env, or --generate.');
            return EXIT_USER_INPUT;
        }
        $problem = DefaultCredentials::problemWith($newPw);
        if ($problem !== null) {
            CLI::error("The new password {$problem}.");
            return EXIT_USER_INPUT;
        }
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
            CLI::write("Success: Superadmin account 'Ministrator' password has been reset (2FA cleared).", 'green');
        } else {
            // Create user
            $userId = 'usr_superadmin_01';
            $db->table('users')->insert([
                'id'            => $userId,
                'username'      => 'Ministrator',
                'email'         => 'admin@micronos.local',
                'password_hash' => $hash,
                'auth_source'   => 'local',
                'role'          => 'SuperAdmin',
                'is_active'     => 1,
                'created_at'    => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s')
            ]);

            // Assign SuperAdmin role
            $role = $db->table('roles')->where('name', 'SuperAdmin')->get()->getRowArray();
            if ($role !== null) {
                $db->table('user_roles')->insert([
                    'user_id' => $userId,
                    'role_id' => $role['id'],
                ]);
            }

            CLI::write("Success: Superadmin account 'Ministrator' created and SuperAdmin role assigned.", 'green');
        }

        if ($generated) {
            CLI::write("New password (shown once): {$newPw}", 'yellow');
        }
    }
}
