<?php

namespace App\Models;

use CodeIgniter\Model;

class DpiApplicationModel extends Model
{
    protected $table            = 'dpi_applications';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id',
        'category_id',
        'name',
        'proto_id',
        'mark_hex',
        'description',
        'risk_level',
        'ports_hint',
        'is_system',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public function getByCategory(string $categoryId): array
    {
        return $this->where('category_id', $categoryId)
                    ->orderBy('name', 'ASC')
                    ->findAll();
    }

    public function getWithCategory(): array
    {
        return $this->select('dpi_applications.*, dpi_categories.name as category_name, dpi_categories.color as category_color, dpi_categories.icon as category_icon')
                    ->join('dpi_categories', 'dpi_categories.id = dpi_applications.category_id', 'left')
                    ->orderBy('dpi_applications.name', 'ASC')
                    ->findAll();
    }
}
