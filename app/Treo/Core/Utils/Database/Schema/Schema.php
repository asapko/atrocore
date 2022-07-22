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
 *
 * This software is not allowed to be used in Russia and Belarus.
 */

declare(strict_types=1);

namespace Treo\Core\Utils\Database\Schema;

use Espo\Core\Container;
use Espo\Core\EventManager\Event;

/**
 * Class Schema
 */
class Schema extends \Espo\Core\Utils\Database\Schema\Schema
{
    /**
     * @var Container
     */
    protected $container;

    /**
     * Set container
     *
     * @param Container $container
     *
     * @return $this
     */
    public function setContainer(Container $container)
    {
        $this->container = $container;

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function rebuild($entityList = null)
    {
        if (!$this->getConverter()->process()) {
            return false;
        }

        // get current schema
        $currentSchema = $this->getCurrentSchema();

        // get entityDefs
        $entityDefs = $this
            ->dispatch('Schema', 'prepareEntityDefsBeforeRebuild', new Event(['data' => $this->ormMetadata->getData()]))
            ->getArgument('data');

        // get metadata schema
        $metadataSchema = $this->schemaConverter->process($entityDefs, $entityList);

        // init rebuild actions
        $this->initRebuildActions($currentSchema, $metadataSchema);

        // execute rebuild actions
        $this->executeRebuildActions('beforeRebuild');

        // get queries
        $queries = $this->getDiffSql($currentSchema, $metadataSchema);

        // prepare queries
        $queries = $this->dispatch('Schema', 'prepareQueries', new Event(['queries' => $queries]))->getArgument('queries');

        // run rebuild
        $result = true;
        $connection = $this->getConnection();
        foreach ($queries as $sql) {
            $GLOBALS['log']->info('SCHEMA, Execute Query: ' . $sql);
            try {
                $result &= (bool)$connection->executeQuery($sql);
            } catch (\Exception $e) {
                $GLOBALS['log']->alert('Rebuild database fault: ' . $e);
                $result = false;
            }
        }

        // execute rebuild action
        $this->executeRebuildActions('afterRebuild');

        // after rebuild action
        $result = $this
            ->dispatch('Schema', 'afterRebuild', new Event(['result' => (bool)$result, 'queries' => $queries]))
            ->getArgument('result');

        return $result;
    }

    public function getDiffQueries(): array
    {
        // set strict type
        $this->getPlatform()->strictType = true;

        $fromSchema = $this->getCurrentSchema();
        $toSchema = $this->schemaConverter->process($this->ormMetadata->getData(), null);
        $diff = $this->getComparator()->compare($fromSchema, $toSchema);

        // get queries
        $queries = $diff->toSql($this->getPlatform());

        // prepare queries
        $queries = $this->dispatch('Schema', 'prepareQueries', new Event(['queries' => $queries]))->getArgument('queries');

        // set strict type
        $this->getPlatform()->strictType = false;

        return $queries;
    }

    /**
     * Dispatch an event
     *
     * @param string $target
     * @param string $action
     * @param Event  $event
     *
     * @return mixed
     */
    protected function dispatch(string $target, string $action, Event $event)
    {
        if (!empty($eventManager = $this->getContainer()->get('eventManager'))) {
            return $eventManager->dispatch($target, $action, $event);
        }

        return $event;
    }

    /**
     * Get container
     *
     * @return Container
     */
    protected function getContainer(): Container
    {
        return $this->container;
    }
}
