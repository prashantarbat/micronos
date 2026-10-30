<?php

namespace App\Libraries;

use App\Models\ZtnaSettingsModel;

/**
 * GatewayIdentity — who is using the Clientless Gateway portal.
 *
 * Gateway users sign in on the gateway's own login page
 * (`/vpn/clientless-gateway/login`). That sign-in is kept under its own session
 * key, `ztna_user`, and deliberately never sets `user_id`: AuthFilter treats
 * `user_id` as a management-UI login, and a portal user must not be able to
 * reach the appliance's admin pages with the session they got from the portal.
 *
 * An administrator who is already signed in to the management UI can still
 * open the portal from there; that identity is derived from the admin session.
 */
final class GatewayIdentity
{
    public const SESSION_KEY = 'ztna_user';

    /**
     * The current gateway user, or null when nobody is signed in or the
     * gateway session has been idle longer than the configured timeout.
     *
     * @return array{user_id: string, username: string, email: string, roles: list<string>, is_superadmin: bool, via: string}|null
     */
    public static function current(): ?array
    {
        $session = session();
        $user    = $session->get(self::SESSION_KEY);

        if (is_array($user) && ($user['username'] ?? '') !== '') {
            $timeout = self::idleTimeoutSeconds();
            if (time() - (int) ($user['last_seen'] ?? 0) > $timeout) {
                $session->remove(self::SESSION_KEY);

                return null;
            }

            $user['last_seen'] = time();
            $session->set(self::SESSION_KEY, $user);

            return self::shape($user, 'gateway');
        }

        if ($session->get('user_id')) {
            $roles = $session->get('roles');
            if (! is_array($roles)) {
                $roles = self::roleNames((string) $session->get('user_id'));
            }

            return self::shape([
                'user_id'       => (string) $session->get('user_id'),
                'username'      => (string) $session->get('username'),
                'email'         => (string) ($session->get('email') ?? ''),
                'roles'         => $roles,
                'is_superadmin' => (bool) $session->get('is_superadmin'),
            ], 'admin');
        }

        return null;
    }

    /** Record a completed gateway sign-in. */
    public static function signIn(array $user): array
    {
        $roles = self::roleNames((string) $user['id']);
        $isSuperadmin = strtolower((string) $user['username']) === 'ministrator'
            || in_array('superadmin', array_map('strtolower', $roles), true);

        $identity = [
            'user_id'       => (string) $user['id'],
            'username'      => (string) $user['username'],
            'email'         => (string) ($user['email'] ?? ''),
            'roles'         => $roles,
            'is_superadmin' => $isSuperadmin,
            'signed_in_at'  => time(),
            'last_seen'     => time(),
        ];

        $session = session();
        $session->regenerate(true); // session fixation
        $session->set(self::SESSION_KEY, $identity);

        return self::shape($identity, 'gateway');
    }

    /** Sign the gateway user out without touching a management-UI login. */
    public static function signOut(): void
    {
        session()->remove(self::SESSION_KEY);
        session()->remove('ztna_pending');
    }

    /** Role names (as stored in ztna_resources.allowed_roles) for a user. */
    public static function roleNames(string $userId): array
    {
        if ($userId === '') {
            return [];
        }

        try {
            $rows = (new \App\Models\UserModel())->getUserRoles($userId);
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($r) => (string) ($r['name'] ?? ''), $rows)));
    }

    private static function idleTimeoutSeconds(): int
    {
        try {
            $mins = (int) ((new ZtnaSettingsModel())->getSettings()['session_timeout_mins'] ?? 60);
        } catch (\Throwable) {
            $mins = 60;
        }

        return max(5, $mins) * 60;
    }

    private static function shape(array $user, string $via): array
    {
        $username = (string) ($user['username'] ?? '');

        return [
            'user_id'       => (string) ($user['user_id'] ?? ''),
            'username'      => $username,
            'email'         => (string) (($user['email'] ?? '') ?: "{$username}@micronos.local"),
            'roles'         => array_values(array_map('strval', (array) ($user['roles'] ?? []))),
            'is_superadmin' => (bool) ($user['is_superadmin'] ?? false),
            'via'           => $via,
        ];
    }
}
