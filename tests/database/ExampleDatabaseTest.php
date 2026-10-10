<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Database\Seeds\ExampleSeeder;
use Tests\Support\Models\ExampleModel;

/**
 * @internal
 */
final class ExampleDatabaseTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $seed = ExampleSeeder::class;

    // This sample test owns only its own `factories` table. Migrating the
    // whole application here would run every appliance migration's down()
    // (which is not exercised in production), so it is created directly.
    protected $migrate = false;
    protected $refresh = false;

    protected function setUp(): void
    {
        $db = \Config\Database::connect('tests');

        if (! $db->tableExists('factories')) {
            $forge = \Config\Database::forge('tests');
            $forge->addField('id');
            $forge->addField([
                'name'       => ['type' => 'varchar', 'constraint' => 31],
                'uid'        => ['type' => 'varchar', 'constraint' => 31],
                'class'      => ['type' => 'varchar', 'constraint' => 63],
                'icon'       => ['type' => 'varchar', 'constraint' => 31],
                'summary'    => ['type' => 'varchar', 'constraint' => 255],
                'created_at' => ['type' => 'datetime', 'null' => true],
                'updated_at' => ['type' => 'datetime', 'null' => true],
                'deleted_at' => ['type' => 'datetime', 'null' => true],
            ]);
            $forge->createTable('factories', true);
        }

        parent::setUp();
    }

    public function testModelFindAll(): void
    {
        $model = new ExampleModel();

        // Get every row created by ExampleSeeder
        $objects = $model->findAll();

        // Make sure the count is as expected
        $this->assertCount(3, $objects);
    }

    public function testSoftDeleteLeavesRow(): void
    {
        $model = new ExampleModel();
        $this->setPrivateProperty($model, 'useSoftDeletes', true);
        $this->setPrivateProperty($model, 'tempUseSoftDeletes', true);

        /** @var stdClass $object */
        $object = $model->first();
        $model->delete($object->id);

        // The model should no longer find it
        $this->assertNull($model->find($object->id));

        // ... but it should still be in the database
        $result = $model->builder()->where('id', $object->id)->get()->getResult();

        $this->assertCount(1, $result);
    }
}
