<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddManualIocSupportToThreatIntel extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('threat_indicators')) {
            $fields = $this->db->getFieldNames('threat_indicators');
            if (! in_array('comment', $fields, true)) {
                $this->forge->addColumn('threat_indicators', [
                    'comment' => [
                        'type' => 'TEXT',
                        'null' => true,
                    ],
                ]);
            }
        }

        if ($this->db->tableExists('threat_feeds')) {
            $exists = $this->db->table('threat_feeds')->where('id', 'feed_manual_ioc')->countAllResults();
            if (! $exists) {
                $now = date('Y-m-d H:i:s');
                $this->db->table('threat_feeds')->insert([
                    'id'                    => 'feed_manual_ioc',
                    'name'                  => 'Manual Administrator IoC Blacklist',
                    'slug'                  => 'manual-ioc',
                    'feed_type'             => 'manual',
                    'url'                   => null,
                    'api_key'               => null,
                    'min_confidence'        => 100,
                    'update_interval_hours' => 0,
                    'enabled'               => 1,
                    'last_sync_at'          => $now,
                    'last_status'           => 'success',
                    'last_error'            => null,
                    'indicator_count'       => 0,
                    'created_at'            => $now,
                    'updated_at'            => $now,
                ]);
            }
        }
    }

    public function down()
    {
        if ($this->db->tableExists('threat_indicators')) {
            $fields = $this->db->getFieldNames('threat_indicators');
            if (in_array('comment', $fields, true)) {
                $this->forge->dropColumn('threat_indicators', ['comment']);
            }
        }

        if ($this->db->tableExists('threat_feeds')) {
            $this->db->table('threat_feeds')->where('id', 'feed_manual_ioc')->delete();
        }
    }
}
