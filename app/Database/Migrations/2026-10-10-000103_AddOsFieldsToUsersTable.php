<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddOsFieldsToUsersTable extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('users')) {
            $userFields = $this->db->getFieldNames('users');
            $toAdd = [];
            if (! in_array('shell', $userFields, true)) {
                $toAdd['shell'] = [
                    'type'       => 'VARCHAR',
                    'constraint' => 64,
                    'default'    => '/bin/bash',
                ];
            }
            if (! in_array('ssh_keys', $userFields, true)) {
                $toAdd['ssh_keys'] = [
                    'type' => 'TEXT',
                    'null' => true,
                ];
            }
            if (! in_array('uid', $userFields, true)) {
                $toAdd['uid'] = [
                    'type' => 'INT',
                    'null' => true,
                ];
            }
            if (! empty($toAdd)) {
                $this->forge->addColumn('users', $toAdd);
            }
        }
    }

    public function down()
    {
        if ($this->db->tableExists('users')) {
            $userFields = $this->db->getFieldNames('users');
            $toDrop = array_intersect(['shell', 'ssh_keys', 'uid'], $userFields);
            if (! empty($toDrop)) {
                $this->forge->dropColumn('users', $toDrop);
            }
        }
    }
}
