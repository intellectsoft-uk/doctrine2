<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\ORM\Persisters\Collection;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Mapping\ColumnMetadata;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\ToManyAssociationMetadata;
use Doctrine\ORM\PersistentCollection;

/**
 * Persister for one-to-many collections.
 *
 * @author  Roman Borschel <roman@code-factory.org>
 * @author  Guilherme Blanco <guilhermeblanco@hotmail.com>
 * @author  Alexander <iam.asm89@gmail.com>
 * @since   2.0
 */
class OneToManyPersister extends AbstractCollectionPersister
{
    /**
     * {@inheritdoc}
     */
    public function delete(PersistentCollection $collection)
    {
        // The only valid case here is when you have weak entities. In this
        // scenario, you have @OneToMany with orphanRemoval=true, and replacing
        // the entire collection with a new would trigger this operation.
        $association = $collection->getMapping();

        if (! $association->isOrphanRemoval()) {
            // Handling non-orphan removal should never happen, as @OneToMany
            // can only be inverse side. For owning side one to many, it is
            // required to have a join table, which would classify as a ManyToManyPersister.
            return;
        }

        $targetClass = $this->em->getClassMetadata($association->getTargetEntity());

        return $targetClass->inheritanceType === InheritanceType::JOINED
            ? $this->deleteJoinedEntityCollection($collection)
            : $this->deleteEntityCollection($collection);
    }

    /**
     * {@inheritdoc}
     */
    public function update(PersistentCollection $collection)
    {
        // This can never happen. One to many can only be inverse side.
        // For owning side one to many, it is required to have a join table,
        // then classifying it as a ManyToManyPersister.
        return;
    }

    /**
     * {@inheritdoc}
     */
    public function get(PersistentCollection $collection, $index)
    {
        $association = $collection->getMapping();

        if (! ($association instanceof ToManyAssociationMetadata && $association->getIndexedBy())) {
            throw new \BadMethodCallException("Selecting a collection by index is only supported on indexed collections.");
        }

        $persister = $this->uow->getEntityPersister($association->getTargetEntity());
        $criteria  = [
            $association->getMappedBy()  => $collection->getOwner(),
            $association->getIndexedBy() => $index,
        ];

        return $persister->load($criteria, null, $association, [], null, 1);
    }

    /**
     * {@inheritdoc}
     */
    public function count(PersistentCollection $collection)
    {
        $association = $collection->getMapping();
        $persister   = $this->uow->getEntityPersister($association->getTargetEntity());

        // only works with single id identifier entities. Will throw an
        // exception in Entity Persisters if that is not the case for the
        // 'mappedBy' field.
        $criteria = [
            $association->getMappedBy()  => $collection->getOwner(),
        ];

        return $persister->count($criteria);
    }

    /**
     * {@inheritdoc}
     */
    public function slice(PersistentCollection $collection, $offset, $length = null)
    {
        $association = $collection->getMapping();
        $persister   = $this->uow->getEntityPersister($association->getTargetEntity());

        return $persister->getOneToManyCollection($association, $collection->getOwner(), $offset, $length);
    }

    /**
     * {@inheritdoc}
     */
    public function containsKey(PersistentCollection $collection, $key)
    {
        $association = $collection->getMapping();

        if (! ($association instanceof ToManyAssociationMetadata && $association->getIndexedBy())) {
            throw new \BadMethodCallException("Selecting a collection by index is only supported on indexed collections.");
        }

        $persister = $this->uow->getEntityPersister($association->getTargetEntity());

        // only works with single id identifier entities. Will throw an
        // exception in Entity Persisters if that is not the case for the
        // 'mappedBy' field.
        $criteria  = [
            $association->getMappedBy()  => $collection->getOwner(),
            $association->getIndexedBy() => $key,
        ];

        return (bool) $persister->count($criteria);
    }

     /**
     * {@inheritdoc}
     */
    public function contains(PersistentCollection $collection, $element)
    {
        if ( ! $this->isValidEntityState($element)) {
            return false;
        }

        $association = $collection->getMapping();
        $persister = $this->uow->getEntityPersister($association->getTargetEntity());

        // only works with single id identifier entities. Will throw an
        // exception in Entity Persisters if that is not the case for the
        // 'mappedBy' field.
        $criteria = new Criteria(
            Criteria::expr()->eq($association->getMappedBy(), $collection->getOwner())
        );

        return $persister->exists($element, $criteria);
    }

    /**
     * {@inheritdoc}
     */
    public function removeElement(PersistentCollection $collection, $element)
    {
        $association = $collection->getMapping();

        if (! $association->isOrphanRemoval()) {
            // no-op: this is not the owning side, therefore no operations should be applied
            return false;
        }

        if (! $this->isValidEntityState($element)) {
            return false;
        }

        $persister = $this->uow->getEntityPersister($association->getTargetEntity());

        return $persister->delete($element);
    }

    /**
     * {@inheritdoc}
     */
    public function loadCriteria(PersistentCollection $collection, Criteria $criteria)
    {
        throw new \BadMethodCallException("Filtering a collection by Criteria is not supported by this CollectionPersister.");
    }

    /**
     * @param PersistentCollection $collection
     *
     * @return int
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    private function deleteEntityCollection(PersistentCollection $collection)
    {
        $association  = $collection->getMapping();
        $identifier   = $this->uow->getEntityIdentifier($collection->getOwner());
        $sourceClass  = $this->em->getClassMetadata($association->getSourceEntity());
        $targetClass  = $this->em->getClassMetadata($association->getTargetEntity());
        $inverseAssoc = $targetClass->associationMappings[$association->getMappedBy()];
        $columns      = [];
        $parameters   = [];

        foreach ($inverseAssoc->getJoinColumns() as $joinColumn) {
            $columns[]    = $this->platform->quoteIdentifier($joinColumn->getColumnName());
            $parameters[] = $identifier[$sourceClass->fieldNames[$joinColumn->getReferencedColumnName()]];
        }

        $tableName = $targetClass->table->getQuotedQualifiedName($this->platform);
        $statement = 'DELETE FROM ' . $tableName . ' WHERE ' . implode(' = ? AND ', $columns) . ' = ?';

        return $this->conn->executeUpdate($statement, $parameters);
    }

    /**
     * Delete Class Table Inheritance entities.
     * A temporary table is needed to keep IDs to be deleted in both parent and child class' tables.
     *
     * Thanks Steve Ebersole (Hibernate) for idea on how to tackle reliably this scenario, we owe him a beer! =)
     *
     * @param PersistentCollection $collection
     *
     * @return int
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    private function deleteJoinedEntityCollection(PersistentCollection $collection)
    {
        $association = $collection->getMapping();
        $sourceClass = $this->em->getClassMetadata($association->getSourceEntity());
        $targetClass = $this->em->getClassMetadata($association->getTargetEntity());
        $rootClass   = $this->em->getClassMetadata($targetClass->rootEntityName);

        // 1) Build temporary table DDL
        $tempTable         = $this->platform->getTemporaryTableName($rootClass->getTemporaryIdTableName());
        $idColumns         = $rootClass->getIdentifierColumns($this->em);
        $idColumnNameList  = implode(', ', array_keys($idColumns));
        $columnDefinitions = [];

        foreach ($idColumns as $columnName => $column) {
            $type = $column->getType();

            $columnDefinitions[$columnName] = [
                'notnull' => true,
                'type'    => $type,
            ];
        }

        $statement = $this->platform->getCreateTemporaryTableSnippetSQL() . ' ' . $tempTable
            . ' (' . $this->platform->getColumnDeclarationListSQL($columnDefinitions) . ')';

        $this->conn->executeUpdate($statement);

        // 2) Build insert table records into temporary table
        $dql   = ' SELECT t0.' . implode(', t0.', $rootClass->getIdentifierFieldNames())
               . ' FROM ' . $targetClass->name . ' t0 WHERE t0.' . $association->getMappedBy() . ' = :owner';
        $query = $this->em->createQuery($dql)->setParameter('owner', $collection->getOwner());

        $statement  = 'INSERT INTO ' . $tempTable . ' (' . $idColumnNameList . ') ' . $query->getSQL();
        $parameters = array_values($sourceClass->getIdentifierValues($collection->getOwner()));
        $numDeleted = $this->conn->executeUpdate($statement, $parameters);

        // 3) Delete records on each table in the hierarchy
        $classNames = array_merge($targetClass->parentClasses, [$targetClass->name], $targetClass->subClasses);

        foreach (array_reverse($classNames) as $className) {
            $parentClass = $this->em->getClassMetadata($className);
            $tableName   = $parentClass->table->getQuotedQualifiedName($this->platform);
            $statement   = 'DELETE FROM ' . $tableName . ' WHERE (' . $idColumnNameList . ')'
                . ' IN (SELECT ' . $idColumnNameList . ' FROM ' . $tempTable . ')';

            $this->conn->executeUpdate($statement);
        }

        // 4) Drop temporary table
        $statement = $this->platform->getDropTemporaryTableSQL($tempTable);

        $this->conn->executeUpdate($statement);

        return $numDeleted;
    }
}
