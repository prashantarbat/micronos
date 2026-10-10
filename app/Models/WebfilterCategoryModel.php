<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * WebfilterCategoryModel — URL category taxonomies & enforcement policies
 */
class WebfilterCategoryModel extends Model
{
    protected $table         = 'webfilter_categories';
    protected $primaryKey    = 'id';

    /**
     * `id` is a VARCHAR(36) primary key (migration 000011), so the database
     * cannot generate one — every writer supplies its own. Declaring this is a
     * correctness statement, not a no-op: leaving CI4's default on means a future
     * insert that omits the id asks the database for a value it cannot produce,
     * and fails the way the transaction model did.
     */
    protected $useAutoIncrement = false;

    protected $allowedFields = [
        'name',
        'action',
        'description',
        'enabled',
        'domain_count',
    ];
    protected $useTimestamps = false;

    /**
     * Get all categories sorted alphabetically or by name
     */
    public function getCategories(): array
    {
        return $this->orderBy('name', 'ASC')->findAll();
    }

    /**
     * Cycle action between 'block', 'allow', and 'monitor'
     */
    public function toggleAction(string $id): ?string
    {
        $category = $this->find($id);
        if (!$category) {
            return null;
        }

        $nextAction = match ($category['action'] ?? 'allow') {
            'block'   => 'allow',
            'allow'   => 'monitor',
            'monitor' => 'block',
            default   => 'block',
        };

        $this->update($id, ['action' => $nextAction]);
        return $nextAction;
    }
}
