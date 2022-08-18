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

namespace Espo\Core\Templates\Services;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Utils\Language;
use Espo\Core\Utils\Util;
use Espo\ORM\Entity;
use Espo\Services\Record;

class Relationship extends Record
{
    public function createRelationshipEntitiesViaIds(string $entityTypeFrom, string $entityIdFrom, string $entityTypeTo, array $entityIdsTo): bool
    {
        foreach ([$entityTypeFrom, $entityTypeTo] as $v) {
            if (!in_array($v, $this->getMetadata()->get(['scopes', $this->entityType, 'relationshipEntities'], []))) {
                throw new BadRequest('Relationship entity is required.');
            }
        }

        foreach ($entityIdsTo as $idTo) {
            $input[lcfirst($entityTypeFrom) . 'Id'] = $entityIdFrom;
            $input[lcfirst($entityTypeTo) . 'Id'] = $idTo;
            try {
                $this->createEntity(json_decode(json_encode($input)));
            } catch (\Throwable $e) {
                $GLOBALS['log']->error('CreateRelationshipEntitiesViaIds failed: ' . $e->getMessage());
            }
        }

        return true;
    }

    public function deleteAll(string $entityType, string $entityId): bool
    {
        if (!in_array($entityType, $this->getMetadata()->get(['scopes', $this->entityType, 'relationshipEntities'], []))) {
            throw new BadRequest('Relationship entity is required.');
        }

        foreach ($this->getRepository()->where([lcfirst($entityType) . 'Id' => $entityId])->find() as $entity) {
            $this->getRepository()->remove($entity);
        }

        return true;
    }

    protected function storeEntity(Entity $entity)
    {
        try {
            $result = $this->getRepository()->save($entity, $this->getDefaultRepositoryOptions());
        } catch (\PDOException $e) {
            if (!empty($e->errorInfo[1]) && $e->errorInfo[1] == 1062) {
                return true;
            }
            throw $e;
        }

        return $result;
    }
}
