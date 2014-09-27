<?php
/**
 * this is the fully managed ORM layer of
 * the dao
 * @TODO allow maybe user defined fields in readModel(s) functions?
 */
namespace CoreORM\Dao;

use CoreORM\Model, CoreORM\Adaptor\Dynamodb AS Adaptor, CoreORM\Model\Dynamodb AS DModel;
use CoreORM\Utility\Assoc;

class Orm extends Base
{
    // dynamo fetch modes
    const FETCH_MODEL_QUERY = 1;
    const FETCH_MODEL_SCAN  = 2;
    // dynamo update modes
    const WRITE_MODE_INSERT = 3;
    const WRITE_MODE_UPDATE = 4;

    /**
     * Read one model
     * this supports relations, just setup in models
     * @param Model $model
     * @param array $option
     * @throws \CoreORM\Exception\Dao
     */
    public function readModel(Model $model, $option = array(
            'useSlave' => false,
            'condition' => array(),
            'bind' => array(),
            'fetchMode' => self::FETCH_MODEL_QUERY
        ))
    {
        $condition = Assoc::get($option, 'condition');
        // check for Dynamo
        if ($model instanceof DModel) {
            $fetchMode = Assoc::get($option, 'fetchMode', self::FETCH_MODEL_QUERY);
            return $this->readDynamoModel($model, $condition, $fetchMode);
        }
        // below are relation db
        $useSlave = Assoc::get($option, 'useSlave', false);
        $bind = Assoc::get($option, 'bind', array());
        // if we have a left join inside, we should not set a limit, otherwise we use 1
        $limit = 1;
        $relations = $model->activeRelations();
        if (!empty($relations)) {
            $limit = 0;
        }
        $sqlGroup = $this->adaptor()->composeReadSQL($model, $condition, $bind, null, $limit);
        $result = $this->fetchAll($sqlGroup['sql'], $sqlGroup['bind'], $useSlave);
        $modelFetched = false;
        $relatedModels = array();
        foreach ($result as $row) {
            if (!$modelFetched) {
                $model->rawSetUp($row, Model::STATE_READ);
                $modelFetched = true;
            }
            // next, with each relation...
            foreach ($relations as $table => $info) {
                $class = $info['class'];
                $setter = $info['setter'];
                if ($info['type'] == Model::RELATION_SINGLE) {
                    if (empty($relatedModels[$table])) {
                        $tmp = new $class($row, Model::STATE_READ);
                        if ($tmp instanceof Model && $tmp->primaryKey(true)) {
                            $model->$setter($tmp);
                            $relatedModels[$table] = true;
                            unset($tmp);
                        }
                    }
                } else {
                    // multiple instance, we just keep adding to it...
                    $tmp = new $class($row, Model::STATE_READ);
                    if ($tmp instanceof Model && $tmp->primaryKey(true)) {
                        $model->$setter($tmp);
                        unset($tmp);
                    }
                }
            }
        }

    }// end readModel


    /**
     * Read multiple model
     * this supports relations, just setup in models
     * by default, if this joins any model the multiple children option will be on.
     * @param Model $model
     * @param array $option
     * @throws \CoreORM\Exception\Dao
     * @internal param array $extraCondition
     * @internal param array $bind
     * @internal param array $orderBy
     * @internal param null $limit
     * @internal param bool $useSlave
     * @return array
     */
    public function readModels(Model $model, $option = array(
            // relation db options
            'extraCondition' => array(),
            'bind' => array(),
            'orderBy' => array(),
            'limit' => null,
            'useSlave' => false,
            // dynamo options
            'fetchMode' => self::FETCH_MODEL_QUERY
        ))
    {
        $condition = Assoc::get($option, 'condition', array());
        $bind = Assoc::get($option, 'bind', array());
        $orderBy = Assoc::get($option, 'orderBy', array());
        $limit = (int) Assoc::get($option, 'limit', 0);
        $useSlave = Assoc::get($option, 'useSlave', false);
        // use a soft limit to compose (using fetch row) - so no limit is to be passed in.
        $sqlGroup = $this->adaptor()->composeReadSQL($model, $condition, $bind, $orderBy);
        $modelFetched = array();
        $relations = $model->activeRelations();
        $relatedModels = array();
        $class = get_class($model);
        $stmt = $this->query($sqlGroup['sql'], $sqlGroup['bind'], $useSlave);
        $cnt = 0;
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            // fetch the main model
            $m = new $class;
            if ($m instanceof Model) {
                $m->rawSetUp($row, Model::STATE_READ);
            }
            $pk = $m->primaryKey(true);
            // fetch models
            if (empty($modelFetched[$pk])) {
                $modelFetched[$pk] = $m;
                // also count plus 1 until reaches limit
                if ($limit > 0) {
                    $cnt ++;
                    if ($cnt >= $limit) {
                        // stop fetching more...
                        break;
                    }
                }
            } else {
                $m = $modelFetched[$pk];
            }
            // next, with each relation...
            foreach ($relations as $table => $info) {
                $rClass = $info['class'];
                $setter = $info['setter'];
                if ($info['type'] == Model::RELATION_SINGLE) {
                    if (empty($relatedModels[$pk][$table])) {
                        $tmp = new $rClass;
                        if ($tmp instanceof Model) {
                            $tmp->rawSetUp($row, Model::STATE_READ);
                            if ($tmp->primaryKey(true)) {
                                $m->$setter($tmp);
                                $relatedModels[$pk][$table] = true;
                            }
                        }
                        unset($tmp);
                    }
                } else {
                    // multiple instance, we just keep adding to it...
                    $tmp = new $rClass;
                    if ($tmp instanceof Model) {
                        $tmp->rawSetUp($row, Model::STATE_READ);
                        $tpk = $tmp->primaryKey(true);
                        if (!empty($tpk)) {
                            $m->$setter($tmp);
                        }
                    }
                    unset($tmp);
                }
            }
        }
        return $modelFetched;

    }// end readModel


    /**
     * NOTE: for security/performance concern
     * we DO NOT allow chain save of the sub models,
     * so this only saves the model itself
     * @param Model $model
     * @param array $option
     * @throws \CoreORM\Exception\Dao
     */
    public function writeModel(Model $model, $option = array(
            'mode' => self::WRITE_MODE_INSERT
        ))
    {
        // shift to dynamo if model is dynamo
        if ($model instanceof DModel) {
            $mode = Assoc::get($option, 'mode', self::WRITE_MODE_INSERT);
            if ($mode == self::WRITE_MODE_INSERT) {
                // insert a new dynamo object
                return $this->putItem($model);
            }
            // otherwise, update
            return $this->updateItem($model);
        }
        // compose sql
        $sqlGroup = $this->adaptor()->composeWriteSQL($model, $this->adaptor()->getType());
        $this->query($sqlGroup['sql'], $sqlGroup['bind']);
        // then gave the model and id
        $this->readModel($model);
        return $model;

    }// end writeModel


    /**
     * delete
     * @param Model $model
     * @return \PDOStatement
     */
    public function deleteModel(Model $model)
    {
        // compose sql
        $sqlGroup = $this->adaptor()->composeDeleteSQL($model, $this->adaptor()->getType());
        return $this->query($sqlGroup['sql'], $sqlGroup['bind']);

    }// end deleteModel


/*---------------[dynamo specific functions]-----------------*/

    /**
     * dynamo adaptor
     * @param string $name
     * @return Adaptor
     */
    public function adaptorDynamo($name = null)
    {
        return parent::adaptor($name);

    }// end adaptorDynamo

    /**
     * query one model item
     * @param DModel $item
     * @param array $extraCondition
     * @return \Guzzle\Service\Resource\Model|mixed|\PDOStatement
     */
    public function queryItem(DModel $item, $extraCondition = array())
    {
        return $this->adaptorDynamo()->queryItem($item, $extraCondition);

    }// end queryItem


    /**
     * scan items
     * @param DModel $item
     * @param $extraCondition
     * @return \Guzzle\Service\Resource\Model|mixed
     */
    public function scanItems(DModel $item, $extraCondition = array())
    {
        return $this->adaptorDynamo()->scanItems($item, $extraCondition);

    }// end scanItems


    /**
     * put one item
     * @param DModel $item
     * @return \Guzzle\Service\Resource\Model|mixed
     */
    public function putItem(DModel $item)
    {
        return $this->adaptorDynamo()->putItem($item);

    }


    /**
     * update one item
     * @param DModel $item
     * @return \PDOStatement|void
     */
    public function updateItem(DModel $item)
    {
        return $this->adaptorDynamo()->updateItem($item);

    }


    /**
     * delete one item
     * @param DModel $item
     * @return \Guzzle\Service\Resource\Model|mixed
     * @throws \CoreORM\Exception\Dao
     */
    public function deleteItem(DModel $item)
    {
        return $this->adaptorDynamo()->deleteItem($item);

    }


    /**
     * drop table and all contents...
     * Use with care!!!
     * @param $table
     * @return \Guzzle\Service\Resource\Model
     */
    public function dropTable($table)
    {
        return $this->adaptorDynamo()->dropTable($table);

    }


    /**
     * create table if not exist
     * @param $schema
     * @return bool
     * @throws \Exception
     */
    public function createTableIfNotExists($schema)
    {
        return $this->adaptorDynamo()->createTableIfNotExists($schema);

    }


    /**
     * create table
     * @param $schema
     * @param bool $waitTillFinished
     * @return bool
     */
    public function createTable($schema, $waitTillFinished = true)
    {
        return $this->adaptorDynamo()->createTable($schema, $waitTillFinished);

    }

/*---------------[dynamo object functions]-----------------*/

    /**
     * internal API
     * read dynamo model only
     * @param DModel $model
     * @param array $extraCondition
     * @param $type
     * @return DModel
     */
    protected function readDynamoModel(DModel $model, $extraCondition = array(), $type = self::FETCH)
    {
        $item = null;
        switch ($type) {
            case self::FETCH_MODEL_QUERY:
                $item = $this->adaptorDynamo()->queryItem($model, $extraCondition);
                break;
            case self::FETCH_MODEL_SCAN:
                $item = $this->adaptorDynamo()->scanItems($model, $extraCondition);
                break;
        }
        if (empty($item)) {
            return null;
        }
        $row = (array) current($item->get('Items'));
        foreach ($row as $field => $val) {
            $model->rawSetFieldData($field, current($val));
        }
        return $model;

    }


}// end Orm
