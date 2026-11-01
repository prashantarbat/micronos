<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * SystemSettingsModel — singleton row (id = 1) in the system_settings table.
 * Always use get() to retrieve and save() to persist.
 */
class SystemSettingsModel extends Model
{
    protected $table         = 'system_settings';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        // Identity
        'hostname', 'domain', 'micron_id',
        // Organisation
        'org_name', 'org_contact_name', 'org_email', 'org_phone',
        'org_address', 'org_city', 'org_state', 'org_country',
        'org_latitude', 'org_longitude', 'org_notes',
        // Time
        'timezone', 'ntp_servers', 'ntp_enabled', 'ntp_sync_interval',
        // WebGUI
        'webgui_protocol', 'webgui_port', 'webgui_redirect_http',
        'webgui_session_timeout', 'webgui_login_banner',
        'webgui_login_banner_enabled', 'webgui_login_banner_title',
        'webgui_sticky_header_enabled',
        'webgui_sticky_left', 'webgui_sticky_center', 'webgui_sticky_right',
        'webgui_sticky_blink_enabled',
        'webgui_sticky_bg', 'webgui_sticky_fg',
        'webgui_max_login_attempts', 'webgui_lockout_duration', 'webgui_cert_id',
        // SSH
        'ssh_enabled', 'ssh_port', 'ssh_auth_methods', 'ssh_root_login', 'ssh_max_auth_tries',
        // Notifications
        'notif_email_enabled', 'notif_smtp_host', 'notif_smtp_port', 'notif_smtp_tls',
        'notif_smtp_user', 'notif_smtp_pass', 'notif_smtp_from', 'notif_smtp_to',
        // Networking
        'ipv6_enabled',
        // Install check-in (micron:checkin writes the last_* columns itself)
        'telemetry_enabled',
        // Licence agreement acceptance (Eula controller)
        'eula_version', 'eula_accepted_at', 'eula_accepted_by',
        // Misc
        // console_speed (fixed 115200) and language (English only) are kept as
        // columns for older rows but are no longer writable from the Web UI.
        'dns_default_servers', 'theme', 'layout', 'updated_at',
    ];

    /**
     * Defaults used when the table row doesn't exist or fields are missing.
     */
    private array $defaults = [
        'hostname'  => 'micronos-fw01',
        'domain'    => 'firewall.local',
        'micron_id' => '',

        'org_name'         => '',
        'org_contact_name' => '',
        'org_email'        => '',
        'org_phone'        => '',
        'org_address'      => '',
        'org_city'         => '',
        'org_state'        => '',
        'org_country'      => '',
        'org_latitude'     => '',
        'org_longitude'    => '',
        'org_notes'        => '',

        'timezone'          => 'UTC',
        'ntp_servers'       => 'pool.ntp.org, time.google.com',
        'ntp_enabled'       => 1,
        'ntp_sync_interval' => '3600',

        'webgui_protocol'           => 'https',
        'webgui_port'               => 443,
        'webgui_redirect_http'      => 1,
        'webgui_session_timeout'    => 30,
        'webgui_login_banner'       => '',
        'webgui_login_banner_enabled' => 0,
        'webgui_login_banner_title'   => '',
        'webgui_sticky_header_enabled' => 0,
        'webgui_sticky_left'   => '{org_address}, {org_city}',
        'webgui_sticky_center' => '{ip}',
        'webgui_sticky_right'  => '{hostname}',
        'webgui_sticky_blink_enabled' => 0,
        // Empty string = follow the active theme's colours.
        'webgui_sticky_bg'    => '',
        'webgui_sticky_fg'    => '',
        'webgui_max_login_attempts' => 5,
        'webgui_lockout_duration'   => 15,
        'webgui_cert_id'            => 0,

        'ssh_enabled'        => 1,
        'ssh_port'           => 22,
        'ssh_auth_methods'   => 'key,password',
        'ssh_root_login'     => 0,
        'ssh_max_auth_tries' => 3,

        'notif_email_enabled' => 0,
        'notif_smtp_host'     => '',
        'notif_smtp_port'     => 587,
        'notif_smtp_tls'      => 'starttls',
        'notif_smtp_user'     => '',
        'notif_smtp_pass'     => '',
        'notif_smtp_from'     => '',
        'notif_smtp_to'       => '',

        'console_speed'       => '115200',
        'dns_default_servers' => '1.1.1.1, 8.8.8.8',
        // IPv6 is off until an administrator turns it on (System > Networking).
        'ipv6_enabled'        => 0,
        // Install check-in to micronos.microninfo.com: on until turned off
        // (System > Networking > Install check-in).
        'telemetry_enabled'     => 1,
        'telemetry_last_at'     => null,
        'telemetry_last_status' => '',
        // Licence agreement: not accepted until an administrator does so.
        'eula_version'          => '',
        'eula_accepted_at'      => null,
        'eula_accepted_by'      => '',
        'theme'               => 'micronos',
        'language'            => 'en',
        'layout'              => 'topnav',
    ];

    /** Return the singleton settings row, merged with defaults. */
    public function getSettings(): array
    {
        try {
            $row = $this->find(1);
            return array_merge($this->defaults, $row ?? []);
        } catch (\Throwable $e) {
            return $this->defaults;
        }
    }

    /**
     * Whether the IPv6 stack is enabled appliance-wide.
     */
    public function ipv6Enabled(): bool
    {
        return (int) ($this->getSettings()['ipv6_enabled'] ?? 0) === 1;
    }

    /** Serial console speed used by the bootloader, kernel and getty. */
    public const CONSOLE_BAUD = 115200;

    /** Persist updated fields to the singleton row. */
    public function saveSettings(array $data): bool
    {
        // Fields the Web UI no longer offers are ignored if a stale form posts them.
        unset($data['language'], $data['console_speed']);
        $data['updated_at'] = date('Y-m-d H:i:s');
        $exists = $this->find(1);
        if ($exists) {
            return $this->update(1, $data);
        }
        $data['id'] = 1;
        return (bool) $this->insert($data);
    }
}
