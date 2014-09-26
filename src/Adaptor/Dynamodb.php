<?php
namespace CoreORM\Adaptor;
use Aws\DynamoDb\DynamoDbClient, \CoreORM\Model\Dynamodb AS Model, CoreORM\Utility\Debug;
use CoreORM\Exception\Adaptor;
use PhpParser\Node\Expr\BinaryOp\Mod;
use CoreORM\Utility\Assoc;

/**
 * This is a standalone adaptor
 * that only has similar APIs
 * as PDO
 * Class Dynamodb
 * @package CoreORM\Adaptor
 */
class Dynamodb extends Orm
{
    /**
     * client
     * @var DynamoDbClient
     */
    protected $client;

    /**
     * construct the adaptor
     * @param array $options
     */
    public function __construct($options = array())
    {
        // this sets up the internal dynamodb adapter
        // using PDO so this can be purged by PDO if necessary
        $key = md5(serialize($options));
        $adaptor = Pdo::getPdoAdaptor($key);
        if ($adaptor instanceof DynamoDbClient) {
            $this->client = Pdo::getPdoAdaptor($key);
            return;
        }
        // otherwise, start new one
        Pdo::setPdoAdaptor($key, DynamoDbClient::factory($options));
        $this->client = Pdo::getPdoAdaptor($key);

    }// end __construct


    /**
     * name quote
     * @param $name
     * @return mixed|string
     */
    public function nameQuote($name)
    {
        return json_encode($name);

    }


    /**
     * direct query
     * @param string $options
     * @param string $type
     * @return \Guzzle\Service\Resource\Model|\PDOStatement
     */
    public function queryRaw($options, $type = 'query')
    {
        return $this->client->$type($options);

    }

    /**
     * query by type, using debug tool to benchmark
     * @param string $options
     * @param string $type
     * @return \PDOStatement|void
     */
    public function query($options, $type = 'query')
    {
        if (Debug::debug()) {
            return Debug::bench('queryRaw', array($options, $type), $this);
        }
        return $this->queryRaw($options, $type);

    }


    /**
     * query one model item
     * @param Model $item
     * @param array $extraCondition
     * @return \Guzzle\Service\Resource\Model|mixed|\PDOStatement
     */
    public function queryItem(Model $item, $extraCondition = array())
    {
        $condition = $item->queryGetCondition($extraCondition);
        return $this->query($condition, 'query');

    }


    /**
     * scan items
     * @param Model $item
     * @param $extraCondition
     * @return \Guzzle\Service\Resource\Model|mixed
     */
    public function scanItems(Model $item, $extraCondition = array())
    {
        $condition = $item->queryGetCondition($extraCondition);
        return $this->query($condition, 'scan');

    }


    /**
     * put one item
     * @param Model $item
     * @return \Guzzle\Service\Resource\Model|mixed
     */
    public function putItem(Model $item)
    {
        $data = $this->composeData($item);
        return $this->query($data, 'putItem');

    }


    /**
     * delete one item
     * @param Model $item
     * @param array $extraCondition
     * @return \Guzzle\Service\Resource\Model
     */
    public function deleteItem(Model $item, $extraCondition = array())
    {
        $data = $item->queryGetCondition($extraCondition, Model::DELETE);
        return $this->query($data, 'deleteItem');

    }


    /**
     * compose data for aws
     * @param Model $item
     * @return array
     */
    protected function composeData(Model $item)
    {
        $data = array(
            'TableName' => $item->table(),
            'Item' => $this->client->formatAttributes($item->toArray(false)),
        );
        if (Debug::debug()) {
            $data['ReturnConsumedCapacity'] = 'TOTAL';
        }
        return $data;

    }


    /**
     * describe table
     * @param $table
     * @return array|bool|mixed|null
     */
    public function describe($table)
    {
        try {
            $param = array('TableName' => $table);
            $item = $this->query($param, 'describeTable');
            if (empty($item)) {
                return array();
            }
            return $item->get('Table');
        } catch (\Exception $e) {
            return false;
        }

    }


    /**
     * check table existence
     * @param $table
     * @return bool
     */
    public function tableExists($table)
    {
        $info = $this->describe($table);
        return !empty($info);

    }


    /**
     * drop table and all contents...
     * Use with care!!!
     * @param $table
     * @return \Guzzle\Service\Resource\Model
     */
    public function dropTable($table)
    {
        return $item = $this->query(array('TableName' => $table), 'deleteTable');

    }


    /**
     * create table if not exist
     * @param $schema
     * @param bool $waitTillFinish
     * @return bool
     * @throws \Exception
     */
    public function createTableIfNotExists($schema, $waitTillFinish = false)
    {
        $tableName = Assoc::get($schema, 'TableName');
        if (empty($tableName)) {
            throw new \Exception('Schema should contain TableName');
        }
        if ($this->tableExists($tableName)) {
            return true;
        }
        // now, create and wait...
        return $this->createTable($schema, $waitTillFinish);

    }


    /**
     * create table with debug enabled
     * @param $schema
     * @param bool $waitTillFinish
     * @return bool|mixed
     */
    public function createTable($schema, $waitTillFinish = false)
    {
        $success = $this->query($schema, 'createTable');
        if ($waitTillFinish) {
            $this->client->waitUntil('TableExists', array(
                'TableName' => Assoc::get($schema, 'TableName')
            ));
        }
        return $success;

    }

}
