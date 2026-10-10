<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * WirelessModel — Manages Wi-Fi Access Point (hostapd) and Client (wpa_supplicant) configurations.
 */
class WirelessModel extends Model
{
    protected $table            = 'wireless_interfaces';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'id',
        'interface',
        'name',
        'mode',
        'ssid',
        'passphrase',
        'security',
        'hw_mode',
        'channel',
        'country_code',
        'hidden_ssid',
        'client_isolation',
        'tx_power',
        'enabled',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public const MODES = [
        'ap'     => 'Access Point (Host Wi-Fi Network via hostapd)',
        'client' => 'Client / Station (Connect to Upstream Wi-Fi via wpa_supplicant)',
    ];

    public const SECURITY_PROTOCOLS = [
        'wpa2'      => 'WPA2-PSK (AES/CCMP — Universal standard)',
        'wpa3'      => 'WPA3-SAE (Simultaneous Authentication of Equals — Enhanced Security)',
        'wpa2_wpa3' => 'WPA2/WPA3 Mixed Transition Mode',
        'open'      => 'Open (No Encryption — Captive Portal / Guest)',
    ];

    public const HW_MODES = [
        'g'  => '2.4 GHz (802.11b/g/n)',
        'a'  => '5.0 GHz (802.11a/n/ac)',
        'ax' => 'Wi-Fi 6 (802.11ax Dual-Band)',
    ];
}
