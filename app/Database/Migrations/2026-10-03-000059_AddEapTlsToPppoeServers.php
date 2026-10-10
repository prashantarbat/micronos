<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * EAP-TLS authentication for the PPPoE access concentrator.
 * --------------------------------------------------------
 * PAP and CHAP both prove a subscriber knows a secret. EAP-TLS proves it with an
 * X.509 client certificate instead: the subscriber's certificate is validated
 * against a CA the appliance trusts, and no shared secret is sent or stored on
 * either side. That is what makes it worth offering alongside the secret-based
 * methods for links that terminate on managed customer premises equipment.
 *
 * How pppd performs it, which is what these columns feed:
 *
 *  - EAP-TLS is compiled into pppd, not loaded as a plugin — the packaged pppd
 *    provides radius.so and radattr.so but no eap-tls.so — so it is enabled by
 *    the `require-eap` option and by a credentials file.
 *  - On the server side the certificates are NOT read from the `ca`, `cert` and
 *    `key` options; pppd reads them from a per-peer entry in an eap-tls secrets
 *    file. The entry is tab-separated:
 *
 *        client  server  client-cert  server-cert  ca-cert  server-key  [addrs]
 *
 *    where a `-` means "unspecified". A wildcard client with the server's own
 *    certificate, the CA that issued the subscriber certificates, and the
 *    server key is therefore:
 *
 *        *  *  -  /etc/ppp/eaptls/server.crt  /etc/ppp/eaptls/ca.crt  /etc/ppp/eaptls/server.key
 *
 *  - The client certificate field stays `-` by default: pinning one subscriber
 *    certificate is a deliberate restriction, not the general case, and a CA
 *    that issued the subscriber certificates is what actually authorises them.
 *
 * The server private key is encrypted at rest like every other credential.
 * Optionally a CRL can be supplied, which is what makes a revoked subscriber
 * certificate stop working without reissuing the CA.
 */
class AddEapTlsToPppoeServers extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('pppoe_servers')) {
            return;
        }

        $columns = [
            // 0 | 1 — whether EAP-TLS is offered and required.
            'eap_tls'            => ['type' => 'INTEGER', 'null' => false, 'default' => 0, 'after' => 'auth_backend'],
            // PEM certificate bodies, as pasted or imported.
            'eap_ca_cert'        => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'eap_server_cert'    => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'eap_server_key'     => ['type' => 'TEXT', 'null' => true, 'default' => null],
            // Optional: pin one subscriber certificate instead of accepting any
            // certificate signed by the CA.
            'eap_client_cert'    => ['type' => 'TEXT', 'null' => true, 'default' => null],
            // Optional revocation list.
            'eap_crl'            => ['type' => 'TEXT', 'null' => true, 'default' => null],
        ];

        foreach ($columns as $name => $definition) {
            if (! $this->db->fieldExists($name, 'pppoe_servers')) {
                $this->forge->addColumn('pppoe_servers', [$name => $definition]);
            }
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists('pppoe_servers')) {
            return;
        }

        $columns = ['eap_tls', 'eap_ca_cert', 'eap_server_cert', 'eap_server_key', 'eap_client_cert', 'eap_crl'];

        foreach ($columns as $column) {
            if (! $this->db->fieldExists($column, 'pppoe_servers')) {
                continue;
            }

            // CI4's SQLite table rebuild cannot drop an unindexed column through
            // the forge, so the raw statement is used where the driver needs it.
            if ($this->db->DBDriver === 'SQLite3') {
                $table = $this->db->prefixTable('pppoe_servers');
                $this->db->query("ALTER TABLE {$table} DROP COLUMN {$column}");

                continue;
            }

            $this->forge->dropColumn('pppoe_servers', $column);
        }
    }
}