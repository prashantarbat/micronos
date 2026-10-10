<?php

namespace App\Models;

use CodeIgniter\Model;

class DpiCategoryModel extends Model
{
    protected $table            = 'dpi_categories';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'name',
        'description',
        'icon',
        'color',
        'default_action',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public function getAllWithAppCounts(): array
    {
        $categories = $this->orderBy('name', 'ASC')->findAll();
        $db = \Config\Database::connect();
        
        $counts = $db->table('dpi_applications')
            ->select('category_id, COUNT(*) as app_count')
            ->groupBy('category_id')
            ->get()
            ->getResultArray();
            
        $countMap = [];
        foreach ($counts as $row) {
            $countMap[$row['category_id']] = (int) $row['app_count'];
        }

        foreach ($categories as &$cat) {
            $cat['app_count'] = $countMap[$cat['id']] ?? 0;
        }

        return $categories;
    }
}
