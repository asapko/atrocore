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

use Espo\Core\EventManager\Event;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Util;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\Services\Record;
use Treo\Core\Exceptions\NotModified;

class Hierarchy extends Record
{
    public function getTreeData(array $ids): array
    {
        $tree = [];

        $treeBranches = [];
        foreach ($this->getRepository()->where(['id' => $ids])->find() as $entity) {
            $this->createTreeBranches($entity, $treeBranches);
        }

        if (!empty($treeBranches)) {
            foreach ($treeBranches as $entity) {
                $this->prepareTreeNode($entity, $tree, $ids);
            }
            $this->prepareTreeData($tree);
        }

        return ['total' => count($ids), 'tree' => $tree];
    }

    protected function prepareTreeData(array &$tree): void
    {
        $tree = array_values($tree);
        foreach ($tree as &$v) {
            if (!empty($v['children'])) {
                $this->prepareTreeData($v['children']);
            }
        }
    }

    protected function createTreeBranches(Entity $entity, array &$treeBranches): void
    {
        $parents = $entity->get('parents');
        if ($parents === null || count($parents) == 0) {
            $treeBranches[] = $entity;
        } else {
            foreach ($parents as $parent) {
                $parent->child = $entity;
                $this->createTreeBranches($parent, $treeBranches);
            }
        }
    }

    protected function prepareTreeNode($entity, array &$tree, array $ids): void
    {
        $tree[$entity->get('id')]['id'] = $entity->get('id');
        $tree[$entity->get('id')]['name'] = $entity->get('name');
        $tree[$entity->get('id')]['disabled'] = !in_array($entity->get('id'), $ids);
        if (!empty($entity->child)) {
            if (empty($tree[$entity->get('id')]['children'])) {
                $tree[$entity->get('id')]['children'] = [];
            }
            $this->prepareTreeNode($entity->child, $tree[$entity->get('id')]['children'], $ids);
        }
    }

    public function inheritAll(string $id, string $link): bool
    {
        $event = $this->dispatchEvent('beforeInheritAll', new Event(['id' => $id, 'link' => $link]));

        $id = $event->getArgument('id');
        $link = $event->getArgument('link');

        if (empty($id) || empty($link)) {
            throw new BadRequest("'id' and 'link' is required parameters.");
        }

        if (empty($entity = $this->getRepository()->get($id))) {
            throw new NotFound();
        }

        if (in_array($link, $this->getRepository()->getUnInheritedRelations())) {
            return false;
        }

        if (!$this->getAcl()->check($entity, 'edit')) {
            throw new Forbidden();
        }

        if (empty($foreignEntityType = $entity->getRelationParam($link, 'entity'))) {
            throw new Error();
        }

        if (!$this->getAcl()->check($foreignEntityType, in_array($link, $this->noEditAccessRequiredLinkList) ? 'read' : 'edit')) {
            throw new Forbidden();
        }

        $parents = $entity->get('parents');
        if (empty($parents[0])) {
            throw new NotFound();
        }

        $foreignIds = [];
        foreach ($parents as $parent) {
            $foreignIds = array_merge($foreignIds, $parent->getLinkMultipleIdList($link));
        }
        $foreignIds = array_unique($foreignIds);

        if (empty($foreignIds)) {
            throw new BadRequest($this->getInjection('language')->translate('nothingToInherit', 'exceptions'));
        }

        foreach ($foreignIds as $k => $foreignId) {
            if ($k < $this->maxMassLinkCount) {
                $this->linkEntity($id, $link, $foreignId);
            } else {
                $this->getPseudoTransactionManager()->pushLinkEntityJob($this->entityType, $id, $link, $foreignId);
                $this->createPseudoTransactionLinkJobs($id, $link, $foreignId);
            }
        }

        return $this->dispatchEvent('afterUnlinkAll', new Event(['entity' => $entity, 'link' => $link, 'result' => true]))->getArgument('result');
    }

    public function inheritField(string $field, string $id): bool
    {
        $event = $this->dispatchEvent('beforeInheritField', new Event(['id' => $id, 'field' => $field]));

        $id = $event->getArgument('id');
        $field = $event->getArgument('field');

        if (in_array($field, $this->getRepository()->getUnInheritedFields())) {
            return false;
        }

        $entity = $this->getRepository()->get($id);

        if (empty($entity)) {
            throw new NotFound();
        }

        if (!$this->getAcl()->check($entity, 'edit')) {
            throw new Forbidden();
        }

        $parents = $entity->get('parents');
        if (empty($parents[0])) {
            throw new BadRequest('No parents found.');
        }

        $resultInput = new \stdClass();
        foreach ($parents as $parent) {
            $input = new \stdClass();
            switch ($this->getMetadata()->get(['entityDefs', $this->entityType, 'fields', $field, 'type'], 'varchar')) {
                case 'asset':
                case 'image':
                case 'link':
                    $input->{$field . 'Id'} = $parent->get($field . 'Id');
                    $input->{$field . 'Name'} = $parent->get($field . 'Name');
                    break;
                case 'multiEnum':
                case 'enum':
                    $field = $this->getMetadata()->get(['entityDefs', $this->entityType, 'fields', $field, 'multilangField'], $field);
                    $input->$field = $parent->get($field);
                    break;
                case 'currency':
                    $input->$field = $parent->get($field);
                    $input->{$field . 'Currency'} = $parent->get($field . 'Currency');
                    break;
                case 'unit':
                    $input->$field = $parent->get($field);
                    $input->{$field . 'Unit'} = $parent->get($field . 'Unit');
                    break;
                case 'linkMultiple':
                    $input->{$field . 'Ids'} = array_column($parent->get($field)->toArray(), 'id');
                    break;
                default:
                    $input->$field = $parent->get($field);
                    break;
            }

            foreach ($input as $k => $v) {
                if (property_exists($resultInput, $k) && $resultInput->$k !== $v) {
                    throw new BadRequest($this->getInjection('language')->translate('parentRecordsHaveDifferentValues', 'exceptions'));
                }
            }

            $resultInput = clone $input;
        }

        try {
            $this->updateEntity($id, $resultInput);
        } catch (Conflict $e) {
        } catch (NotModified $e) {
        }

        return true;
    }

    public function unlinkAllHierarchically(string $id, string $link): bool
    {
        $event = $this->dispatchEvent('beforeUnlinkAllHierarchically', new Event(['id' => $id, 'link' => $link]));

        $id = $event->getArgument('id');
        $link = $event->getArgument('link');

        if (empty($id) || empty($link)) {
            throw new BadRequest("'id' and 'link' is required parameters.");
        }

        if (in_array($link, $this->getRepository()->getUnInheritedRelations())) {
            return false;
        }

        if (empty($entity = $this->getRepository()->get($id))) {
            throw new NotFound();
        }

        if (!$this->getAcl()->check($entity, 'edit')) {
            throw new Forbidden();
        }

        if (empty($foreignEntityType = $entity->getRelationParam($link, 'entity'))) {
            throw new Error();
        }

        if (!$this->getAcl()->check($foreignEntityType, in_array($link, $this->noEditAccessRequiredLinkList) ? 'read' : 'edit')) {
            throw new Forbidden();
        }

        $foreignIds = $entity->getLinkMultipleIdList($link);

        foreach ($foreignIds as $k => $foreignId) {
            if ($k < $this->maxMassUnlinkCount) {
                $this->unlinkEntity($id, $link, $foreignId);
            } else {
                $this->getPseudoTransactionManager()->pushUnLinkEntityJob($this->entityType, $id, $link, $foreignId);
            }
        }

        return $this->dispatchEvent('afterUnlinkAllHierarchically', new Event(['entity' => $entity, 'link' => $link, 'result' => true]))->getArgument('result');
    }

    public function getRoute(string $id): array
    {
        return $this->getRepository()->getRoute($id);
    }

    public function getChildren(string $parentId, array $params): array
    {
        $result = [];

        $records = $this->getRepository()->getChildrenArray($parentId, true, $params['offset'], $params['maxSize']);
        if (empty($records)) {
            return $result;
        }

        unset($params['offset']);
        unset($params['maxSize']);
        $selectParams = $this->getSelectParams($params);
        $selectParams['select'] = ['id'];

        $ids = [];
        foreach ($this->getRepository()->find($selectParams) as $entity) {
            if ($this->getAcl()->check($entity, 'read')) {
                $ids[] = $entity->get('id');
            }
        }

        foreach ($records as $record) {
            $result[] = [
                'id'             => $record['id'],
                'name'           => $record['name'],
                'disabled'       => !in_array($record['id'], $ids),
                'load_on_demand' => !empty($record['childrenCount'])
            ];
        }

        return [
            'list' => $result,
            'total' => $this->getRepository()->getChildrenCount($parentId)
        ];
    }

    public function getEntity($id = null)
    {
        $entity = parent::getEntity($id);

        if (!empty($entity)) {
            $entity->set('inheritedFields', $this->getInheritedFields($entity));
        }

        return $entity;
    }

    public function createEntity($attachment)
    {
        $this->prepareChildInputData($attachment);

        return parent::createEntity($attachment);
    }

    public function prepareChildInputData(\stdClass $attachment): void
    {
        if (property_exists($attachment, 'parentsIds') && !empty($attachment->parentsIds[0])) {
            foreach ($this->getDuplicateAttributes($attachment->parentsIds[0]) as $field => $value) {
                if (property_exists($attachment, $field) || in_array($field, $this->getNonInheritedFieldsKeys())) {
                    continue 1;
                }
                $attachment->$field = $value;
            }
            if (empty($this->getMetadata()->get(['scopes', $this->entityType, 'relationInheritance'])) && property_exists($attachment, '_duplicatingEntityId')) {
                unset($attachment->_duplicatingEntityId);
            }
        }
    }

    public function updateEntity($id, $data)
    {
        if (property_exists($data, '_sortedIds') && property_exists($data, '_id')) {
            $this->getRepository()->updateHierarchySortOrder($data->_id, $data->_sortedIds);
            return $this->getEntity($id);
        }

        if (property_exists($data, '_position') && property_exists($data, '_target') && property_exists($data, 'parentId')) {
            if (empty($entity = $this->getRepository()->get($id))) {
                throw new NotFound();
            }
            if (!$this->getAcl()->check($entity, 'edit')) {
                throw new Forbidden();
            }
            $this->getRepository()->updatePositionInTree((string)$id, (string)$data->_position, (string)$data->_target, (string)$data->parentId);
            return $this->getEntity($id);
        }

        if (property_exists($data, '_fieldValueInheritance') && $data->_fieldValueInheritance) {
            return parent::updateEntity($id, $data);
        }

        if (empty($this->getMetadata()->get(['scopes', $this->entityType, 'fieldValueInheritance']))) {
            return parent::updateEntity($id, $data);
        }

        $inTransaction = false;
        if (!$this->getEntityManager()->getPDO()->inTransaction()) {
            $this->getEntityManager()->getPDO()->beginTransaction();
            $inTransaction = true;
        }

        try {
            $entityData = $this->getRepository()->fetchById($id);
            $result = parent::updateEntity($id, $data);
            $this->createPseudoTransactionJobs($entityData, clone $data);
            if ($inTransaction) {
                $this->getEntityManager()->getPDO()->commit();
            }
        } catch (\Throwable $e) {
            if ($inTransaction) {
                $this->getEntityManager()->getPDO()->rollBack();
            }
            throw $e;
        }

        return $result;
    }

    public function linkEntity($id, $link, $foreignId)
    {
        /**
         * Delegate to Update if ManyToOne or OneToOne relation
         */
        if ($this->getMetadata()->get(['entityDefs', $this->entityName, 'links', $link, 'type']) === 'belongsTo') {
            $data = new \stdClass();
            $data->{"{$link}Id"} = $foreignId;
            try {
                $this->updateEntity($id, $data);
            } catch (NotModified $e) {
                // ignore
            }

            return true;
        }

        /**
         * Delegate to Update if OneToMany relation
         */
        if (!empty($linkData = $this->getOneToManyRelationData($link))) {
            $data = new \stdClass();
            $data->{"{$linkData['foreign']}Id"} = $id;
            try {
                $this->getServiceFactory()->create($linkData['entity'])->updateEntity($foreignId, $data);
            } catch (NotModified $e) {
                // ignore
            }

            return true;
        }

        if (empty($this->getMetadata()->get(['scopes', $this->entityType, 'relationInheritance']))) {
            return parent::linkEntity($id, $link, $foreignId);
        }

        if ($this->isPseudoTransaction()) {
            return parent::linkEntity($id, $link, $foreignId);
        }

        $inTransaction = false;
        if (!$this->getEntityManager()->getPDO()->inTransaction()) {
            $this->getEntityManager()->getPDO()->beginTransaction();
            $inTransaction = true;
        }
        try {
            $result = parent::linkEntity($id, $link, $foreignId);
            $this->createPseudoTransactionLinkJobs($id, $link, $foreignId);
            if ($inTransaction) {
                $this->getEntityManager()->getPDO()->commit();
            }
        } catch (\Throwable $e) {
            if ($inTransaction) {
                $this->getEntityManager()->getPDO()->rollBack();
            }
            throw $e;
        }

        return $result;
    }

    public function unlinkEntity($id, $link, $foreignId)
    {
        /**
         * Delegate to Update if ManyToOne or OneToOne relation
         */
        if ($this->getMetadata()->get(['entityDefs', $this->entityName, 'links', $link, 'type']) === 'belongsTo') {
            $data = new \stdClass();
            $data->{"{$link}Id"} = null;
            try {
                $this->updateEntity($id, $data);
            } catch (NotModified $e) {
                // ignore
            }

            return true;
        }

        /**
         * Delegate to Update if OneToMany relation
         */
        if (!empty($linkData = $this->getOneToManyRelationData($link))) {
            $data = new \stdClass();
            $data->{"{$linkData['foreign']}Id"} = null;
            try {
                $this->getServiceFactory()->create($linkData['entity'])->updateEntity($foreignId, $data);
            } catch (NotModified $e) {
                // ignore
            }

            return true;
        }

        if (!empty($this->originUnlinkAction)) {
            return parent::unlinkEntity($id, $link, $foreignId);
        }

        if (empty($this->getMetadata()->get(['scopes', $this->entityType, 'relationInheritance']))) {
            return parent::unlinkEntity($id, $link, $foreignId);
        }

        if ($this->isPseudoTransaction()) {
            return parent::unlinkEntity($id, $link, $foreignId);
        }

        $inTransaction = false;
        if (!$this->getEntityManager()->getPDO()->inTransaction()) {
            $this->getEntityManager()->getPDO()->beginTransaction();
            $inTransaction = true;
        }
        try {
            $result = parent::unlinkEntity($id, $link, $foreignId);
            $this->createPseudoTransactionUnlinkJobs($id, $link, $foreignId);
            if ($inTransaction) {
                $this->getEntityManager()->getPDO()->commit();
            }
        } catch (\Throwable $e) {
            if ($inTransaction) {
                $this->getEntityManager()->getPDO()->rollBack();
            }
            throw $e;
        }

        return $result;
    }

    public function deleteEntity($id)
    {
        $inTransaction = false;
        if (!$this->getEntityManager()->getPDO()->inTransaction()) {
            $this->getEntityManager()->getPDO()->beginTransaction();
            $inTransaction = true;
        }
        try {
            $result = parent::deleteEntity($id);
            foreach ($this->getRepository()->getChildrenRecursivelyArray($id) as $childId) {
                parent::deleteEntity($childId);
            }
            if ($inTransaction) {
                $this->getEntityManager()->getPDO()->commit();
            }
        } catch (\Throwable $e) {
            if ($inTransaction) {
                $this->getEntityManager()->getPDO()->rollBack();
            }
            throw $e;
        }

        return $result;
    }

    public function prepareEntityForOutput(Entity $entity)
    {
        parent::prepareEntityForOutput($entity);

        $entity->set('isRoot', $this->getRepository()->isRoot($entity->get('id')));

        $entity->set('hasChildren',  !empty($children = $entity->get('children'))  && count($children) > 0);

        if ($this->getMetadata()->get(['scopes', $this->entityType, 'multiParents']) !== true) {
            $entity->set('hierarchyRoute', $this->getRepository()->getHierarchyRoute($entity->get('id')));
        }

        if ($entity->has('hierarchySortOrder')) {
            $entity->set('sortOrder', $entity->get('hierarchySortOrder'));
        }
    }

    public function findLinkedEntities($id, $link, $params)
    {
        $result = parent::findLinkedEntities($id, $link, $params);
        if (empty($result['total'])) {
            return $result;
        }

        if ($link === 'children') {
            $result['collection'] = $this->sortCollection($result['collection']);
            return $result;
        }

        if ($link === 'parents') {
            return $result;
        }

        /**
         * Mark records as inherited
         */
        if (!in_array($link, $this->getRepository()->getUnInheritedRelations())) {
            $parents = $this->getRepository()->get($id)->get('parents');
            if (!empty($parents[0])) {
                $parentsRelatedIds = [];
                foreach ($parents as $parent) {
                    $ids = $parent->getLinkMultipleIdList($link);
                    if (!empty($ids)) {
                        $parentsRelatedIds = array_merge($parentsRelatedIds, $ids);
                    }
                }
                foreach ($result['collection'] as $entity) {
                    $entity->isInherited = in_array($entity->get('id'), $parentsRelatedIds);
                }
            }
        }

        return $result;
    }

    protected function duplicateParents($entity, $duplicatingEntity): void
    {
        // ignore duplicating for link 'parents'
    }

    protected function duplicateChildren($entity, $duplicatingEntity): void
    {
        // ignore duplicating for link 'children'
    }

    protected function afterUpdateEntity(Entity $entity, $data)
    {
        $entity->set('inheritedFields', $this->getInheritedFields($entity));
    }

    public function createPseudoTransactionJobs(array $parent, \stdClass $data, string $parentTransactionId = null): void
    {
        $children = $this->getRepository()->getChildrenArray($parent['id']);
        foreach ($children as $child) {
            $this->getRepository()->pushLinkMultipleFields($child);
            $inputData = $this->createInputDataForPseudoTransactionJob($parent, $child, clone $data);
            if (!empty((array)$inputData)) {
                $inputData->_fieldValueInheritance = true;
                $transactionId = $this->getPseudoTransactionManager()->pushUpdateEntityJob($this->entityType, $child['id'], $inputData, $parentTransactionId);
                if ($child['childrenCount'] > 0) {
                    $this->createPseudoTransactionJobs($child, clone $inputData, $transactionId);
                }
            }
        }
    }

    public function createPseudoTransactionLinkJobs(string $id, string $link, string $foreignId, string $parentTransactionId = null): void
    {
        if (in_array($link, $this->getRepository()->getUnInheritedRelations())) {
            return;
        }

        if (empty($this->getMetadata()->get(['entityDefs', $this->entityType, 'links', $link, 'relationName']))) {
            return;
        }

        $children = $this->getRepository()->getChildrenArray($id);
        foreach ($children as $child) {
            $transactionId = $this->getPseudoTransactionManager()->pushLinkEntityJob($this->entityType, $child['id'], $link, $foreignId, $parentTransactionId);
            if ($child['childrenCount'] > 0) {
                $this->createPseudoTransactionLinkJobs($child['id'], $link, $foreignId, $transactionId);
            }
        }
    }

    public function createPseudoTransactionUnlinkJobs(string $id, string $link, string $foreignId, string $parentTransactionId = null): void
    {
        if (in_array($link, $this->getRepository()->getUnInheritedRelations())) {
            return;
        }

        if (empty($this->getMetadata()->get(['entityDefs', $this->entityType, 'links', $link, 'relationName']))) {
            return;
        }

        $children = $this->getRepository()->getChildrenArray($id);
        foreach ($children as $child) {
            $transactionId = $this->getPseudoTransactionManager()->pushUnLinkEntityJob($this->entityType, $child['id'], $link, $foreignId, $parentTransactionId);
            if ($child['childrenCount'] > 0) {
                $this->createPseudoTransactionUnlinkJobs($child['id'], $link, $foreignId, $transactionId);
            }
        }
    }

    protected function createInputDataForPseudoTransactionJob(array $parent, array $child, \stdClass $data): \stdClass
    {
        $unInheritedFields = $this->getRepository()->getUnInheritedFields();
        $inputData = new \stdClass();
        foreach ($data as $field => $value) {
            if (in_array($field, $unInheritedFields)) {
                continue 1;
            }
            $underScoredField = Util::toUnderScore($field);
            if (!array_key_exists($underScoredField, $parent)) {
                continue 1;
            }

            $parentValue = $parent[$underScoredField];
            $childValue = $child[$underScoredField];

            if ($this->areValuesEqual($this->getRepository()->get(), $field, $parentValue, $childValue)) {
                $inputData->$field = $value;
            }
        }

        return $inputData;
    }

    protected function sortCollection(EntityCollection $inputCollection): EntityCollection
    {
        $ids = [];
        foreach ($inputCollection as $entity) {
            $ids[$entity->get('id')] = $entity->get('sortOrder');
        }
        asort($ids);

        $collection = new EntityCollection();
        foreach ($ids as $id => $sortOrder) {
            foreach ($inputCollection as $entity) {
                if ($entity->get('id') === $id) {
                    $collection->append($entity);
                    break;
                }
            }
        }

        return $collection;
    }

    protected function getInheritedFields(Entity $entity): array
    {
        $parents = $entity->get('parents');
        if (empty($parents[0])) {
            return [];
        }

        $unInheritedFields = $this->getRepository()->getUnInheritedFields();

        $inheritedFields = [];
        foreach ($parents as $parent) {
            foreach ($this->getMetadata()->get(['entityDefs', $this->entityType, 'fields'], []) as $field => $fieldData) {
                if (in_array($field, $inheritedFields) || in_array($field, $unInheritedFields)) {
                    continue 1;
                }

                if (!empty($fieldData['notStorable'])) {
                    continue 1;
                }

                switch ($fieldData['type']) {
                    case 'asset':
                    case 'image':
                    case 'link':
                        if ($this->areValuesEqual($this->getRepository()->get(), $field . 'Id', $parent->get($field . 'Id'), $entity->get($field . 'Id'))) {
                            $inheritedFields[] = $field;
                        }
                        break;
                    case 'currency':
                        if (
                            $this->areValuesEqual($this->getRepository()->get(), $field, $parent->get($field), $entity->get($field))
                            && $this->areValuesEqual($this->getRepository()->get(), $field . 'Currency', $parent->get($field . 'Currency'), $entity->get($field . 'Currency'))
                        ) {
                            $inheritedFields[] = $field;
                        }
                        break;
                    case 'unit':
                        if (
                            $this->areValuesEqual($this->getRepository()->get(), $field, $parent->get($field), $entity->get($field))
                            && $this->areValuesEqual($this->getRepository()->get(), $field . 'Unit', $parent->get($field . 'Unit'), $entity->get($field . 'Unit'))
                        ) {
                            $inheritedFields[] = $field;
                        }
                        break;
                    case 'linkMultiple':
                        if (!in_array($field, $this->getRepository()->getUnInheritedFields())) {
                            $parentIds = $parent->getLinkMultipleIdList($field);
                            sort($parentIds);
                            $entityIds = $entity->getLinkMultipleIdList($field);
                            sort($entityIds);
                            if ($this->areValuesEqual($this->getRepository()->get(), $field . 'Ids', $parentIds, $entityIds)) {
                                $inheritedFields[] = $field;
                            }
                        }
                        break;
                    default:
                        if ($this->areValuesEqual($this->getRepository()->get(), $field, $parent->get($field), $entity->get($field))) {
                            $inheritedFields[] = $field;
                        }
                        break;
                }
            }
        }

        return $inheritedFields;
    }

    protected function getNonInheritedFieldsKeys(): array
    {
        $result = [];

        $unInheritedFields = $this->getRepository()->getUnInheritedFields();

        foreach ($this->getMetadata()->get(['entityDefs', $this->entityType, 'fields'], []) as $field => $fieldData) {
            if (!in_array($field, $unInheritedFields)) {
                continue 1;
            }

            if ($fieldData['type'] === 'linkMultiple' || !empty($fieldData['notStorable'])) {
                continue 1;
            }

            if ($fieldData['type'] === 'link') {
                $result[] = $field . 'Id';
                $result[] = $field . 'Name';
            } else {
                $result[] = $field;
            }
        }

        return $result;

    }
}
