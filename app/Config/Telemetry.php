<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Install check-in: once at boot and then daily, the appliance tells
 * micronos.microninfo.com that it exists (php spark micron:checkin, run by
 * micron-checkin.timer). What is sent is listed in
 * App\Libraries\InstallCheckin::payload() and docs/INSTALL_CHECKIN.md.
 *
 * The administrator turns it off under System > Networking > Install check-in
 * (system_settings.telemetry_enabled). `$allowed = false` here (or
 * `telemetry.allowed = false` in .env) is a hard off for builds and labs that
 * must never call out, whatever the setting says.
 */
class Telemetry extends BaseConfig
{
    public bool $allowed = true;

    public string $endpoint = 'https://micronos.microninfo.com/api/v1/checkin';

    /** Seconds before the request is abandoned; the next timer run tries again. */
    public int $timeout = 15;
}
