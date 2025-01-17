<?php
/*
 * This file is part of EspoCRM and/or AtroCore.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2019 Yuri Kuznetsov, Taras Machyshyn, Oleksiy Avramenko
 * Website: http://www.espocrm.com
 *
 * AtroCore is EspoCRM-based Open Source application.
 * Copyright (C) 2020 AtroCore UG (haftungsbeschränkt).
 *
 * AtroCore as well as EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * AtroCore as well as EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word
 * and "AtroCore" word.
 */

namespace Espo\ORM;

use Espo\Core\Exceptions\Error;

class EntityManager
{
    /**
     * @var \PDO
     */
    protected $pdo;

    protected $entityFactory;

    protected $repositoryFactory;

    protected $mappers = array();

    protected $metadata;

    protected $repositoryHash = array();

    protected $params = array();

    protected $query;

    protected $driverPlatformMap = ['pdo_mysql' => 'Mysql', 'mysqli' => 'Mysql'];

    public function __construct($params)
    {
        $this->params = $params;

        $this->metadata = new Metadata();

        if (empty($this->params['platform'])) {
            if (empty($this->params['driver'])) {
                throw new \Exception('No database driver specified.');
            }
            $driver = $this->params['driver'];
            if (empty($this->driverPlatformMap[$driver])) {
                throw new \Exception("Database driver '{$driver}' is not supported.");
            }
            $this->params['platform'] = $this->driverPlatformMap[$this->params['driver']];
        }

        if (!empty($params['metadata'])) {
            $this->setMetadata($params['metadata']);
        }

        $entityFactoryClassName = '\\Espo\\ORM\\EntityFactory';
        if (!empty($params['entityFactoryClassName'])) {
            $entityFactoryClassName = $params['entityFactoryClassName'];
        }
        $this->entityFactory = new $entityFactoryClassName($this, $this->metadata);

        $repositoryFactoryClassName = '\\Espo\\ORM\\RepositoryFactory';
        if (!empty($params['repositoryFactoryClassName'])) {
            $repositoryFactoryClassName = $params['repositoryFactoryClassName'];
        }
        $this->repositoryFactory = new $repositoryFactoryClassName($this, $this->entityFactory);

        $this->init();
    }

    public function getQuery()
    {
        if (empty($this->query)) {
            $platform = $this->params['platform'];
            $className = '\\Espo\\ORM\\DB\\Query\\' . ucfirst($platform);
            $this->query = new $className($this->getPDO(), $this->entityFactory);
        }
        return $this->query;
    }

    protected function getMapperClassName($name)
    {
        $className = null;

        switch ($name) {
            case 'RDB':
                $className = $this->getMysqlMapperClassName();
                break;
        }

        return $className;
    }

    protected function getMysqlMapperClassName(): string
    {
        return \Espo\ORM\DB\MysqlMapper::class;
    }

    public function getMapper($name = 'RDB')
    {
        if (substr($name, 0, 1) == '\\') {
            $className = $name;
        } else {
            $className = $this->getMapperClassName($name);
        }

        if (empty($this->mappers[$className])) {
            $this->mappers[$className] = new $className($this->getPDO(), $this->entityFactory, $this->getQuery());
        }
        return $this->mappers[$className];
    }

    public function getEntity($name, $id = null)
    {
        if (!$this->hasRepository($name)) {
            throw new Error("ORM: Repository '{$name}' does not exist.");
        }

        return $this->getRepository($name)->get($id);
    }

    public function saveEntity(Entity $entity, array $options = array())
    {
        $entityType = $entity->getEntityType();
        return $this->getRepository($entityType)->save($entity, $options);
    }

    public function removeEntity(Entity $entity, array $options = array())
    {
        $entityType = $entity->getEntityType();
        return $this->getRepository($entityType)->remove($entity, $options);
    }

    public function getRepository($name)
    {
        if (!$this->hasRepository($name)) {
            // TODO Throw error
        }

        if (empty($this->repositoryHash[$name])) {
            $this->repositoryHash[$name] = $this->repositoryFactory->create($name);
        }
        return $this->repositoryHash[$name];
    }

    public function setMetadata(array $data)
    {
        $this->metadata->setData($data);
    }

    public function hasRepository($name)
    {
        return $this->getMetadata()->has($name);
    }

    public function getMetadata()
    {
        return $this->metadata;
    }

    public function getOrmMetadata()
    {
        return $this->getMetadata();
    }

    /**
     * @return \PDO
     */
    public function getPDO()
    {
        if (empty($this->pdo)) {
            $this->pdo = $this->params['pdo'];
        }

        return $this->pdo;
    }

    public function normalizeRepositoryName($name)
    {
        return $name;
    }

    public function normalizeEntityName($name)
    {
        return $name;
    }

    public function createCollection($entityName, $data = array())
    {
        $seed = $this->getEntity($entityName);
        $collection = new EntityCollection($data, $seed, $this->entityFactory);
        return $collection;
    }

    public function getEntityFactory()
    {
        return $this->entityFactory;
    }

    /**
     * @param string $sql
     * @param array  $inputParams
     *
     * @return \PDOStatement
     */
    public function nativeQuery(string $sql, array $inputParams = []):\PDOStatement
    {
        // prepare params
        $params = null;
        if (!empty($inputParams)) {
            $params = [];
            foreach ($inputParams as $key => $value) {
                $params[':' . $key] = $value;
            }
        }

        $sth = $this
            ->getPDO()
            ->prepare($sql);
        $sth->execute($params);

        return $sth;
    }

    protected function init()
    {
    }
}

