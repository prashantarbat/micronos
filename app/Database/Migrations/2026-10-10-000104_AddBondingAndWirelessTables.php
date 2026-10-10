<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration 104: Add tables for LACP Link Aggregation (Bonding) and Wireless LAN (Wi-Fi).
 */
class AddBondingAndWirelessTables extends Migration
{
    public function up()
    {
        // 1. Table for LACP & Link Aggregation Bonds (bond0, bond1, etc.)
        if (! $this->db->tableExists('interface_bonds')) {
            $this->forge->addField([
                'id' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32, // e.g. bond0
                ],
                'name' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64, // e.g. Core Switch LACP Trunk
                ],
                'mode' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32,
                    'default'    => '802.3ad', // 802.3ad (LACP), active-backup, balance-xor, balance-rr
                ],
                'slaves' => [
                    'type' => 'TEXT', // JSON array of physical interface names: ["enp1s0", "enp2s0"]
                ],
                'lacp_rate' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 16,
                    'default'    => 'fast', // fast (1s) or slow (30s)
                ],
                'xmit_hash_policy' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32,
                    'default'    => 'layer2+3', // layer2, layer2+3, layer3+4
                ],
                'miimon' => [
                    'type'       => 'INT',
                    'constraint' => 6,
                    'default'    => 100, // MII link monitoring interval in ms
                ],
                'updelay' => [
                    'type'       => 'INT',
                    'constraint' => 6,
                    'default'    => 200, // delay before enabling slave after link up (ms)
                ],
                'downdelay' => [
                    'type'       => 'INT',
                    'constraint' => 6,
                    'default'    => 200, // delay before disabling slave after link down (ms)
                ],
                'mtu' => [
                    'type'       => 'INT',
                    'constraint' => 6,
                    'default'    => 1500,
                ],
                'description' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'null'       => true,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'updated_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->createTable('interface_bonds', true);
        }

        // 2. Table for Wireless LAN interfaces (wlan0, Access Point hostapd & Client wpa_supplicant)
        if (! $this->db->tableExists('wireless_interfaces')) {
            $this->forge->addField([
                'id' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32, // e.g. wlan0
                ],
                'interface' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32, // kernel physical wlan device
                    'default'    => 'wlan0',
                ],
                'name' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64, // e.g. Office Wi-Fi AP
                    'default'    => 'Wireless LAN',
                ],
                'mode' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 16,
                    'default'    => 'ap', // 'ap' (Access Point via hostapd) or 'client' (Station via wpa_supplicant)
                ],
                'ssid' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                    'default'    => 'MicronOS-WiFi',
                ],
                'passphrase' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 128,
                    'default'    => 'M1Cr0N-WiFi-Secure',
                ],
                'security' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32,
                    'default'    => 'wpa2', // wpa2, wpa3, wpa2_wpa3, open
                ],
                'hw_mode' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 8,
                    'default'    => 'g', // 'g' (2.4 GHz 802.11g/n), 'a' (5 GHz 802.11a/n/ac), 'ax' (Wi-Fi 6)
                ],
                'channel' => [
                    'type'       => 'INT',
                    'constraint' => 4,
                    'default'    => 6,
                ],
                'country_code' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 8,
                    'default'    => 'US',
                ],
                'hidden_ssid' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 0,
                ],
                'client_isolation' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 0,
                ],
                'tx_power' => [
                    'type'       => 'INT',
                    'constraint' => 4,
                    'default'    => 20, // dBm
                ],
                'enabled' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 1,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'updated_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->createTable('wireless_interfaces', true);
        }
    }

    public function down()
    {
        $this->forge->dropTable('interface_bonds', true);
        $this->forge->dropTable('wireless_interfaces', true);
    }
}
