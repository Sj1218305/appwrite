<?php

namespace Appwrite\Database\Adapter;

use Appwrite\Database\Adapter;
use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Database\Exception\Duplicate;
use Appwrite\Database\Validator\Authorization;
use Exception;
use PDO;
use stdClass;

class Relational extends Adapter
{
    /**
     * @var PDO
     */
    protected $pdo;

    /**
     * @var array
     */
    protected $protected = ['$id' => true, '$collection' => true, '$permissions' => true];

    /**
     * Constructor.
     *
     * Set connection and settings
     *
     * @param Registry $register
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Create Collection
     *
     * @param Document $collection
     * @param string $id
     *
     * @return bool
     */
    public function createCollection(Document $collection, string $id): bool
    {
        $rules = $collection->getAttribute('rules', []);
        $indexes = $collection->getAttribute('indexes', []);
        $columns = [];

        foreach ($rules as $attribute) { /** @var Document $attribute */
            $key = $attribute->getAttribute('key');
            $type = $attribute->getAttribute('type');
            $array = $attribute->getAttribute('array');

            if($array) {
                $this->createAttribute($collection, $key, $type, $array);
                continue;
            }

            $columns[] = $this->getColumn($key, $type, $array);
        }

        $columns = (!empty($columns)) ? implode(",\n", $columns) . ",\n" : '';

        $query = $this->getPDO()->prepare('CREATE TABLE `app_'.$this->getNamespace().'.collection.'.$id.'` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `uid` varchar(45) DEFAULT NULL,
            `createdAt` datetime DEFAULT NULL,
            `updatedAt` datetime DEFAULT NULL,
            `permissions` longtext DEFAULT NULL,
            '.$columns.'
            PRIMARY KEY (`id`),
            UNIQUE KEY `index1` (`uid`)

          ) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb4;
        ');

        if (!$query->execute()) {
            return false;
        }

        return true;
    }

    /**
     * Delete Collection
     *
     * @param Document $collection
     *
     * @return bool
     */
    public function deleteCollection(Document $collection): bool
    {
        $query = $this->getPDO()->prepare('DROP TABLE `app_'.$this->getNamespace().'.collection.'.$collection->getId().'`;');

        if (!$query->execute()) {
            return false;
        }

        $rules = $collection->getAttribute('rules', []);
        
        foreach ($rules as $attribute) { /** @var Document $attribute */
            $key = $attribute->getAttribute('key');
            $array = $attribute->getAttribute('array');

            if($array) {
                $this->deleteAttribute($collection, $key, $array);
            }
        }

        return true;
    }

    /**
     * Create Attribute
     *
     * @param Document $collection
     * @param string $id
     * @param string $type
     * @param bool $array
     *
     * @return bool
     */
    public function createAttribute(Document $collection, string $id, string $type, bool $array = false): bool
    {
        if($array) {
            $query = $this->getPDO()->prepare('CREATE TABLE `app_'.$this->getNamespace().'.collection.'.$collection->getId().'.'.$id.'` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `uid` varchar(45) DEFAULT NULL,
                '.$this->getColumn($id, $type, $array).',
                PRIMARY KEY (`id`),
                KEY `index1` (`uid`)
              ) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8mb4;
            ;');
        }
        else {
            $query = $this->getPDO()->prepare('ALTER TABLE `app_'.$this->getNamespace().'.collection.'.$collection->getId().'`
                ADD COLUMN '.$this->getColumn($id, $type, $array).';');
        }
        
        if (!$query->execute()) {
            return false;
        }

        return true;
    }

    /**
     * Delete Attribute
     *
     * @param Document $collection
     * @param string $id
     * @param bool $array
     *
     * @return bool
     */
    public function deleteAttribute(Document $collection, string $id, bool $array = false): bool
    {
        if($array) {
            $query = $this->getPDO()->prepare('DROP TABLE `app_'.$this->getNamespace().'.collection.'.$collection->getId().'.'.$id.'`;');
        }
        else {
            $query = $this->getPDO()->prepare('ALTER TABLE `app_'.$this->getNamespace().'.collection.'.$collection->getId().'`
                DROP COLUMN `col_'.$id.'`;');
        }

        if (!$query->execute()) {
            return false;
        }

        return true;
    }

    /**
     * Create Index
     *
     * @param Document $collection
     * @param string $id
     * @param string $type
     * @param array $attributes
     *
     * @return bool
     */
    public function createIndex(Document $collection, string $id, string $type, array $attributes): bool
    {
        $columns = [];

        foreach ($attributes as $attribute) {
            $columns[] = '`col_'.$attribute.'`(32) ASC'; // TODO custom size limit per type
        }

        $index = '';

        switch ($type) {
            case Database::INDEX_KEY:
                $index = 'INDEX';
                break;

            case Database::INDEX_FULLTEXT:
                $index = 'FULLTEXT INDEX';
                break;

            case Database::INDEX_UNIQUE:
                $index = 'UNIQUE INDEX';
                break;

            case Database::INDEX_SPATIAL:
                $index = 'SPATIAL INDEX';
                break;
            
            default:
                throw new Exception('Unsupported indext type');
                break;
        }

        $query = $this->getPDO()->prepare('ALTER TABLE `app_'.$this->getNamespace().'.collection.'.$collection->getId().'`
            ADD '.$index.' `index_'.$id.'` ('.implode(',', $columns).');');

        if (!$query->execute()) {
            return false;
        }

        return true;
    }

    /**
     * Delete Index
     *
     * @param Document $collection
     * @param string $id
     *
     * @return bool
     */
    public function deleteIndex(Document $collection, string $id): bool
    {
        $query = $this->getPDO()->prepare('ALTER TABLE `app_'.$this->getNamespace().'.collection.'.$collection->getId().'`
            DROP INDEX `index_'.$id.'`;');

        if (!$query->execute()) {
            return false;
        }

        return true;
    }

    /**
     * Get Document.
     *
     * @param Document $collection
     * @param string $id
     *
     * @return array
     *
     * @throws Exception
     */
    public function getDocument(Document $collection, $id)
    {
        // Get fields abstraction
        $st = $this->getPDO()->prepare('SELECT * FROM `app_'.$this->getNamespace().'.collection.'.$collection->getId().'` documents
            WHERE documents.uid = :uid;
        ');

        $st->bindValue(':uid', $id, PDO::PARAM_STR);

        $st->execute();

        $document = $st->fetch();

        if (empty($document)) { // Not Found
            return [];
        }

        $rules = $collection->getAttribute('rules', []);
        $data = [];

        $data['$id'] = (isset($document['uid'])) ? $document['uid'] : null;
        $data['$collection'] = $collection->getId();
        $data['$permissions'] = (isset($document['permissions'])) ? json_decode($document['permissions'], true) : new stdClass;

        foreach($rules as $i => $rule) { /** @var Document $rule */
            $key = $rule->getAttribute('key');
            $type = $rule->getAttribute('type');
            $array = $rule->getAttribute('array');
            $list = $rule->getAttribute('list', []);
            $value = (isset($document['col_'.$key])) ? $document['col_'.$key] : null;

            if(array_key_exists($key, $this->protected)) {
                continue;
            }

            if($array) {                
                $st = $this->getPDO()->prepare('SELECT * FROM `app_'.$this->getNamespace().'.collection.'.$collection->getId().'.'.$key.'` documents
                    WHERE documents.uid = :uid;
                ');

                $st->bindValue(':uid', $id, PDO::PARAM_STR);

                $st->execute();

                $elements = $st->fetchAll();

                $value = [];

                foreach ($elements as $element) {
                    $value[] = (isset($element['col_'.$key])) ? $element['col_'.$key] : null;
                }
            }

            switch($type) {
                case Database::VAR_DOCUMENT:
                    if($array) {
                        foreach($value as $i => $element) {
                            $value[$i] = $this->getDatabase()->getDocument(array_pop(array_reverse($list)), $element);
                        }
                    }
                    else {
                        $value = $this->getDatabase()->getDocument(array_pop(array_reverse($list)), $value);
                    }
                    break;
            }

            $data[$key] = $value;
        }

        return $data;
    }

    /**
     * Create Document.
     *
     * @param Document $collection
     * @param array $data
     * @param array $unique
     *
     * @throws \Exception
     *
     * @return array
     */
    public function createDocument(Document $collection, array $data, array $unique = [])
    {
        $data['$id'] = $this->getId();
        $data['$permissions'] = (!isset($data['$permissions'])) ? [] : $data['$permissions'];
        $columns = [];
        $rules = $collection->getAttribute('rules', []);

        foreach($rules as $i => $rule) {
            $key = $rule->getAttribute('key');
            $type = $rule->getAttribute('type');
            $array = $rule->getAttribute('array');

            if(array_key_exists($key, $this->protected) || $array) {
                continue;
            }

            $columns[] = '`col_'.$key.'` = :col_'.$i;
        }

        $columns = (!empty($columns)) ? ', '.implode(', ', $columns) : '';

        /**
         * Check Unique Keys
         */
        //throw new Duplicate('Duplicated Property');
        
        $st = $this->getPDO()->prepare('INSERT INTO  `app_'.$this->getNamespace().'.collection.'.$collection->getId().'`
            SET uid = :uid, createdAt = :createdAt, updatedAt = :updatedAt, permissions = :permissions'.$columns.';
        ');

        $st->bindValue(':uid', $data['$id'], PDO::PARAM_STR);
        $st->bindValue(':createdAt', \date('Y-m-d H:i:s', \time()), PDO::PARAM_STR);
        $st->bindValue(':updatedAt', \date('Y-m-d H:i:s', \time()), PDO::PARAM_STR);
        $st->bindValue(':permissions', \json_encode($data['$permissions']), PDO::PARAM_STR);
        
        foreach($rules as $i => $rule) { /** @var Document $rule */
            $key = $rule->getAttribute('key');
            $type = $rule->getAttribute('type');
            $array = $rule->getAttribute('array');
            $list = $rule->getAttribute('list', []);
            $value = (isset($data[$key])) ? $data[$key] : null;

            if(array_key_exists($key, $this->protected)) {
                continue;
            }

            switch($type) {
                case Database::VAR_DOCUMENT:
                    if($array) {
                        if(!is_array($value)) {
                            continue 2;
                        }
                        
                        foreach ($value as $i => $element) { // TODO CHECK IF CREATE OR UPDATE
                            $value[$i] = $this->getDatabase()
                                ->createDocument(array_pop(array_reverse($list)), $element)->getId();
                        }
                    }
                    else {
                        $id = (isset($value['$id'])) ? $value['$id'] : null;  // TODO CHECK IF CREATE OR UPDATE
                        $value = ($id)
                            ? $this->getDatabase()->createDocument(array_pop(array_reverse($list)), $value)->getId()
                            : $this->getDatabase()->updateDocument(array_pop(array_reverse($list)), $id, $value)->getId();
                    }
                    break;
            }

            if($array) {
                if(!is_array($value)) {
                    continue;
                }
                
                foreach ($value as $i => $element) {
                    $stArray = $this->getPDO()->prepare('INSERT INTO  `app_'.$this->getNamespace().'.collection.'.$collection->getId().'.'.$key.'`
                        SET uid = :uid, `col_'.$key.'` = :col_x;
                    ');
            
                    $stArray->bindValue(':uid', $data['$id'], PDO::PARAM_STR);
                    $stArray->bindValue(':col_x', $element, $this->getDataType($type));
                    $stArray->execute();
                }

                continue;
            }
            
            if(!$array) {
                $st->bindValue(':col_'.$i, $value, $this->getDataType($type));
            }
        }

        $st->execute();

        //TODO remove this dependency (check if related to nested documents)
        // $this->getRedis()->expire($this->getNamespace().':document-'.$data['$id'], 0);
        // $this->getRedis()->expire($this->getNamespace().':document-'.$data['$id'], 0);

        return $data;
    }

    /**
     * Update Document.
     *
     * @param Document $collection
     * @param string $id
     * @param array $data
     *
     * @return array
     *
     * @throws Exception
     */
    public function updateDocument(Document $collection, string $id, array $data)
    {
        return $this->createDocument($collection, $data);
    }

    /**
     * Delete Document.
     *
     * @param Document $collection
     * @param string $id
     *
     * @return array
     *
     * @throws Exception
     */
    public function deleteDocument(Document $collection, string $id)
    {
        $st1 = $this->getPDO()->prepare('DELETE FROM `'.$this->getNamespace().'.database.documents`
            WHERE uid = :id
		');

        $st1->bindValue(':id', $id, PDO::PARAM_STR);

        $st1->execute();

        $st2 = $this->getPDO()->prepare('DELETE FROM `'.$this->getNamespace().'.database.properties`
            WHERE documentUid = :id
		');

        $st2->bindValue(':id', $id, PDO::PARAM_STR);

        $st2->execute();

        $st3 = $this->getPDO()->prepare('DELETE FROM `'.$this->getNamespace().'.database.relationships`
            WHERE start = :id OR end = :id
		');

        $st3->bindValue(':id', $id, PDO::PARAM_STR);

        $st3->execute();

        return [];
    }

    /**
     * Create Namespace.
     *
     * @param $namespace
     *
     * @throws Exception
     *
     * @return bool
     */
    public function createNamespace($namespace)
    {
        if (empty($namespace)) {
            throw new Exception('Empty namespace');
        }

        $audit = 'app_'.$namespace.'.audit.audit';
        $abuse = 'app_'.$namespace.'.abuse.abuse';

        /**
         * 1. Itterate default collections
         * 2. Create collection
         * 3. Create all regular and array fields
         * 4. Create all indexes
         * 5. Create audit / abuse tables
         */

        foreach($this->getMocks() as $collection) { /** @var Document $collection */
            $this->createCollection($collection, $collection->getId());
        }

        try {
            $this->getPDO()->prepare('CREATE TABLE `'.$audit.'` LIKE `template.audit.audit`;')->execute();
            $this->getPDO()->prepare('CREATE TABLE `'.$abuse.'` LIKE `template.abuse.abuse`;')->execute();
        } catch (Exception $e) {
            throw $e;
        }

        return true;
    }

    /**
     * Delete Namespace.
     *
     * @param $namespace
     *
     * @throws Exception
     *
     * @return bool
     */
    public function deleteNamespace($namespace)
    {
        if (empty($namespace)) {
            throw new Exception('Empty namespace');
        }

        $audit = 'app_'.$namespace.'.audit.audit';
        $abuse = 'app_'.$namespace.'.abuse.abuse';

        foreach($this->getMocks() as $collection) { /** @var Document $collection */
            $this->deleteCollection($collection, $collection->getId());
        }

        // TODO Delete all custom collections

        try {
            $this->getPDO()->prepare('DROP TABLE `'.$audit.'`;')->execute();
            $this->getPDO()->prepare('DROP TABLE `'.$abuse.'`;')->execute();
        } catch (Exception $e) {
            throw $e;
        }

        return true;
    }

    /**
     * Find
     *
     * @param array $options
     *
     * @throws Exception
     *
     * @return array
     */
    public function find(array $options)
    {
        return [];
    }

    /**
     * Count
     *
     * @param array $options
     *
     * @throws Exception
     *
     * @return int
     */
    public function count(array $options)
    {
        return 0;
    }

    /**
     * Parse Filter.
     *
     * @param string $filter
     *
     * @return array
     *
     * @throws Exception
     */
    protected function parseFilter($filter)
    {
        $operatorsMap = ['!=', '>=', '<=', '=', '>', '<']; // Do not edit order of this array

        //FIXME bug with >= <= operators

        $operator = null;

        foreach ($operatorsMap as $node) {
            if (\strpos($filter, $node) !== false) {
                $operator = $node;
                break;
            }
        }

        if (empty($operator)) {
            throw new Exception('Invalid operator');
        }

        $filter = \explode($operator, $filter);

        if (\count($filter) != 2) {
            throw new Exception('Invalid filter expression');
        }

        return [
            'key' => $filter[0],
            'value' => $filter[1],
            'operator' => $operator,
        ];
    }

    /**
     * Get PDO Data Type.
     *
     * @param $type
     *
     * @return string
     *
     * @throws \Exception
     */
    protected function getDataType($type)
    {
        switch ($type) {
            case Database::VAR_TEXT:
            case Database::VAR_URL:
            case Database::VAR_KEY:
            case Database::VAR_IPV4:
            case Database::VAR_IPV6:
            case Database::VAR_EMAIL:
            case Database::VAR_FLOAT:
            case Database::VAR_NUMERIC:
                return PDO::PARAM_STR;
                break;

            case Database::VAR_DOCUMENT:
                return PDO::PARAM_STR;
                break;

            case Database::VAR_INTEGER:
                return PDO::PARAM_INT;
                break;
            
            case Database::VAR_BOOLEAN:
                return PDO::PARAM_BOOL;
                break;

            default:
                throw new Exception('Unsupported attribute: '.$type);
                break;
        }
    }

    /**
     * Get Column
     * 
     * @var string $key
     * @var string $type
     * 
     * @return string
     */
    protected function getColumn(string $key, string $type, bool $array): string
    {
        switch ($type) {
            case Database::VAR_TEXT:
            case Database::VAR_URL:
                return '`col_'.$key.'` TEXT NULL';
                break;

            case Database::VAR_KEY:
            case Database::VAR_DOCUMENT:
                return '`col_'.$key.'` VARCHAR(36) NULL';
                break;

            case Database::VAR_IPV4:
                //return '`col_'.$key.'` INT UNSIGNED NULL';
                return '`col_'.$key.'` VARCHAR(15) NULL';
                break;

            case Database::VAR_IPV6:
                //return '`col_'.$key.'` BINARY(16) NULL';
                return '`col_'.$key.'` VARCHAR(39) NULL';
                break;

            case Database::VAR_EMAIL:
                return '`col_'.$key.'` VARCHAR(255) NULL';
                break;

            case Database::VAR_INTEGER:
                return '`col_'.$key.'` INT NULL';
                break;
            
            case Database::VAR_FLOAT:
            case Database::VAR_NUMERIC:
                return '`col_'.$key.'` FLOAT NULL';
                break;

            case Database::VAR_BOOLEAN:
                return '`col_'.$key.'` BOOLEAN NULL';
                break;

            default:
                throw new Exception('Unsupported attribute: '.$type);
                break;
        }
    }

    /**
     * @return PDO
     *
     * @throws Exception
     */
    protected function getPDO()
    {
        return $this->pdo;
    }
}