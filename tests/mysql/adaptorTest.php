<?php
/**
 * pdo adaptor
 *
 */
require_once __DIR__ . '/../header.php';
use CoreORM\Adaptor\Pdo;
use CoreORM\Adaptor\MySQL;
/**
 * test core
 */
class TestPdo extends PHPUnit_Framework_TestCase
{

    public function testBaseAdaptor()
    {
        new MySQL(array(
            'host' => '127.0.0.1',
            'user' => 'core',
            'pass' => 'test',
            'dbname' => 'coreorm'
        ));

        new MySQL(array(
            'host' => '127.0.0.1',
            'user' => 'core',
            'pass' => 'test',
            'dbname' => 'coreorm'
        ));
        // and both should be using the same PDO...
        $pdos = Pdo::getPdoAdaptor();
        $this->assertEquals(count($pdos), 1);
        // now add yet a new one with different config
        new MySQL(array(
            'host' => '127.0.0.1',
            'user' => 'core',
            'pass' => 'test',
            'dbname' => ''
        ));
        $pdos = Pdo::getPdoAdaptor();
        $this->assertEquals(count($pdos), 2);
    }
}
?>