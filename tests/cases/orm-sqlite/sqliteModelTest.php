<?php
//return;
/**
 * pdo adaptor
 *
 */
require_once __DIR__ . '/../header.php';
$modelDir = realpath(__DIR__ . '/../../support/Example/Model.Sqlite') . '/';
require_once $modelDir . 'User.php';
require_once $modelDir . 'Combination.php';
require_once $modelDir . 'Login.php';
require_once $modelDir . 'File.php';
use CoreORM\Model;
use \Example\Model\User;
use \Example\Model\File;
use \CoreORM\Utility\Debug;
use Example\Model\Combination;
/**
 * test core
 */
class TestSqliteModel extends PHPUnit_Framework_TestCase
{
    /**
     * @var CoreORM\Dao\Orm
     */
    protected $dao;

    public function setUp()
    {
        setDbConfig('database', array(
            'orm_test' => array(
                "dbname" => __DIR__ . '/../../support/tmp/model_test.sqlite',
                "adaptor" => CoreORM\Adaptor\Pdo::ADAPTOR_SQLITE,
            )
        ));
        setDbConfig('default', 'orm_test');
        $this->dao = new \CoreORM\Dao\Orm();
        // clear all and then install all
        $sql = "
        DELETE FROM `attachment`;
        DELETE FROM `user`;
        DELETE FROM `login`;
        DELETE FROM `combined_key_table`;

        INSERT INTO login VALUES(1,'jayf','asfsafadf');
        INSERT INTO login VALUES(2,'brucel','ljalfasdf');
        INSERT INTO attachment VALUES(1,1,'test.jpg',23.2);
        INSERT INTO attachment VALUES(2,1,'abc.pdf',34.03);
        INSERT INTO attachment VALUES(3,2,'low.mov',3020.31);
        INSERT INTO attachment VALUES(4,3,'page.txt',302.1);
        INSERT INTO attachment VALUES(5,2,'flow.diagram',23.3);
        INSERT INTO user VALUES(1,'Name New1408530031','80 Illust Rd. Sydney','1981-03-21');
        INSERT INTO user VALUES(2,'Bruce L','300 Pitt, Sydney','1977-02-21');
        INSERT INTO user VALUES(3,'Fry Steve','1 Infinite Loop, Redmond','1972-11-23');
        ";
        $sqls = explode(';', $sql);
        foreach ($sqls as $sql) {
            $sql = trim($sql);
            if (!empty($sql)) {
                $this->dao->query($sql);
            }
        }
    }

    public function testCRUD()
    {
        // we do the full crud test here...
        // 1st. read
        $user = new User();
        $user->setId(1);
        Debug::bench('readModel', array($user), $this->dao);
        $this->assertNotEmpty($user->getName());
        // 2nd. read all
        // 2.1 read unlimited (also bench it)
        $user = new User();
        $user->shouldJoinAll();
        $users = Debug::bench('readModels', array($user), $this->dao);
        foreach ($users as $u) {
            $this->assertInstanceOf('\Example\Model\User', $u);
            // next, login must be valid (even if empty)
            if ($u instanceof User) {
                $this->assertInstanceOf('\Example\Model\Login', $u->relationGetLogin());
                foreach ($u->relationGetFileList() as $file) {
                    $this->assertInstanceOf('\Example\Model\File', $file);
                }
            }
            // show it off!
            dump($u->toJson(true));
        }
        // 2.2 with limit
        $user = new User();
        $user->shouldJoinAll();
        $users = Debug::bench('readModels', array($user, null, null, null, 1), $this->dao);
        foreach ($users as $u) {
            $this->assertInstanceOf('\Example\Model\User', $u);
            // next, login must be valid (even if empty)
            if ($u instanceof User) {
                $this->assertInstanceOf('\Example\Model\Login', $u->relationGetLogin());
                foreach ($u->relationGetFileList() as $file) {
                    $this->assertInstanceOf('\Example\Model\File', $file);
                }
            }
            // show it off!
            dump($u->toJson(true));
        }
        $this->assertEquals(1, count($users));

        // 3. insert a single new one (and remember to remove it)
        $file = new File();
        $file->setId(100)
             ->setUserId(2)
             ->setSize(123.12)
             ->setFilename('test.file');
        $this->dao->writeModel($file);
        $this->assertNotNull($file->primaryKey());
        // 4. read the file then delete
        $file = new File();
        $file->setUserId(2)
            ->setFilename('test.file');
        $this->dao->readModel($file);
        $this->assertNotNull($file->primaryKey(true));
        // next, delete
        $this->dao->deleteModel($file);
        $file = new File();
        $file->setUserId(2)
            ->setFilename('test.file');
        $this->dao->readModel($file);
        $this->assertEmpty($file->primaryKey(true));
        // partial model test
        $model = new User();
        $model->setId(1)
              ->shouldJoin(new File())
              ->shouldJoin(new \Example\Model\Login())
              ->partialSelect(array(
                    User::FIELD_NAME, User::FIELD_ADDRESS
                ));
        $this->dao->readModel($model);
        $this->assertEmpty($model->getBirthdate(false));
        // and this one should contain multiple files and 1 login item
        $this->assertInstanceOf('Example\Model\Login', $model->relationGetLogin());
        $this->assertTrue(count($model->relationGetFileList()) > 1);
        // now, test update...
        $m = $model->cloneMutable(); // and we don't want to update original
        $NewName = 'Name New' . time();
        if ($m instanceof User) {
            $m->setName($NewName);
            $this->dao->writeModel($m);
        }
        // let's retrieve and figure out
        $newModel = new User();
        $newModel->setId(1);
        $this->dao->readModel($newModel);
        $this->assertEquals($NewName, $newModel->getName());

        // combination key test
        $c = new Combination();
        $c->setId1(1)->setId2(2)->setUserId(1)->setName($NewName);
        $this->dao->writeModel($c);
        // now select out c...
        $c = new Combination();
        $c->setId1(1)->setId2(2)->setUserId(1)->shouldJoinAll();
        $this->dao->readModel($c);
        $this->assertEquals($NewName, $c->getName());
        // next, should contain 2 object
        $this->assertEquals($c->getUserId(), $c->relationGetUser()->getId());
        $this->assertEquals($c->getUserId(), $c->relationGetLogin()->getUserId());
        // then delete it...
        $this->dao->deleteModel($c);
        dump($c->toJson(true));

        // output benchmarks here
        Debug::output(1);

    }

}