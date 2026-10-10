<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ACME (RFC 8555) state for the PKI module.
 *
 * The existing PKI store models a certificate authority whose private key this
 * appliance holds. An ACME certificate is the opposite: a *public* CA issues it
 * and the appliance never sees a signing key. Keeping ACME in the same
 * `pki_certificates` table (source='acme', ca_id pointing at an authority row
 * that carries the issuer's chain but no private key) means everything that
 * already consumes certificates — WireGuard, OpenVPN, IPsec, Squid, the Web GUI
 * — picks up a Let's Encrypt certificate without a second code path.
 *
 * Two pieces of state are genuinely new and do not belong on the certificate
 * row, because they survive a renewal that replaces the certificate:
 *
 *  - `pki_acme_accounts`: the ACME *account* is per-directory and long-lived. It
 *    holds the account key pair, which is the sole proof of ownership of every
 *    order the account will ever make. Losing it does not revoke anything, but
 *    it does mean re-registering, so it is stored rather than regenerated.
 *
 *  - `pki_acme_orders`: the in-flight order, including the challenge token that
 *    the public HTTP-01 route must serve while the CA validates. One row per
 *    certificate, reused on each renewal, so the row is also the record of which
 *    directory and challenge type a certificate was issued through.
 *
 * Permission `cert.acme.manage` is separate from the certificate permissions
 * because requesting a certificate from a public CA is an *outbound* action that
 * publishes a domain name and a validation token to a third party — a materially
 * different act from issuing from the local CA.
 */
class CreateAcmeCertificateState extends Migration
{
    public const PERMISSION = 'cert.acme.manage';

    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'directory' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'contact_email' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'account_url' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'private_key' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 24,
                'default'    => 'unregistered',
            ],
            'last_error' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('directory');
        $this->forge->createTable('pki_acme_accounts', true);

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'cert_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'authority_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'directory' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'domains' => [
                'type' => 'TEXT',
            ],
            'challenge_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'http-01',
            ],
            'cert_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'server',
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 24,
                'default'    => 'pending',
            ],
            'order_url' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'finalize_url' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'authz_url' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'challenge_url' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'cert_url' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'token' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'reason' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'last_error' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('cert_id');
        $this->forge->addKey('token');
        $this->forge->createTable('pki_acme_orders', true);

        $this->seedPermission();
    }

    public function down(): void
    {
        $this->forge->dropTable('pki_acme_orders', true);
        $this->forge->dropTable('pki_acme_accounts', true);

        if ($this->db->tableExists('permissions')) {
            $this->db->table('permissions')->where('key', self::PERMISSION)->delete();
        }
    }

    private function seedPermission(): void
    {
        if (! $this->db->tableExists('permissions')) {
            return;
        }

        if ($this->db->table('permissions')->where('key', self::PERMISSION)->countAllResults() > 0) {
            return;
        }

        $data     = random_bytes(16);
        $data[6]  = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8]  = chr((ord($data[8]) & 0x3f) | 0x80);

        $this->db->table('permissions')->insert([
            'id'          => vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4)),
            'key'         => self::PERMISSION,
            'label'       => 'Manage ACME / Let\'s Encrypt Certificates',
            'module'      => 'Certificates',
            'description' => 'Request and renew publicly-trusted certificates via ACME',
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }
}
