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

namespace Doctrine\ORM\Persisters\Entity;

use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Expr\Comparison;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\AssociationMetadata;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ColumnMetadata;
use Doctrine\ORM\Mapping\FetchMode;
use Doctrine\ORM\Mapping\FieldMetadata;
use Doctrine\ORM\Mapping\GeneratorType;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\JoinColumnMetadata;
use Doctrine\ORM\Mapping\ManyToManyAssociationMetadata;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Mapping\OneToManyAssociationMetadata;
use Doctrine\ORM\Mapping\OneToOneAssociationMetadata;
use Doctrine\ORM\Mapping\ToManyAssociationMetadata;
use Doctrine\ORM\Mapping\ToOneAssociationMetadata;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Persisters\SqlExpressionVisitor;
use Doctrine\ORM\Persisters\SqlValueVisitor;
use Doctrine\ORM\Query;
use Doctrine\ORM\UnitOfWork;
use Doctrine\ORM\Utility\IdentifierFlattener;
use Doctrine\ORM\Utility\PersisterHelper;

/**
 * A BasicEntityPersister maps an entity to a single table in a relational database.
 *
 * A persister is always responsible for a single entity type.
 *
 * EntityPersisters are used during a UnitOfWork to apply any changes to the persistent
 * state of entities onto a relational database when the UnitOfWork is committed,
 * as well as for basic querying of entities and their associations (not DQL).
 *
 * The persisting operations that are invoked during a commit of a UnitOfWork to
 * persist the persistent entity state are:
 *
 *   - {@link addInsert} : To schedule an entity for insertion.
 *   - {@link executeInserts} : To execute all scheduled insertions.
 *   - {@link update} : To update the persistent state of an entity.
 *   - {@link delete} : To delete the persistent state of an entity.
 *
 * As can be seen from the above list, insertions are batched and executed all at once
 * for increased efficiency.
 *
 * The querying operations invoked during a UnitOfWork, either through direct find
 * requests or lazy-loading, are the following:
 *
 *   - {@link load} : Loads (the state of) a single, managed entity.
 *   - {@link loadAll} : Loads multiple, managed entities.
 *   - {@link loadToOneEntity} : Loads a one/many-to-one entity association (lazy-loading).
 *   - {@link loadOneToManyCollection} : Loads a one-to-many entity association (lazy-loading).
 *   - {@link loadManyToManyCollection} : Loads a many-to-many entity association (lazy-loading).
 *
 * The BasicEntityPersister implementation provides the default behavior for
 * persisting and querying entities that are mapped to a single database table.
 *
 * Subclasses can be created to provide custom persisting and querying strategies,
 * i.e. spanning multiple tables.
 *
 * @author Guilherme Blanco <guilhermeblanco@hotmail.com>
 * @author Roman Borschel <roman@code-factory.org>
 * @author Giorgio Sironi <piccoloprincipeazzurro@gmail.com>
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 * @author Alexander <iam.asm89@gmail.com>
 * @author Fabio B. Silva <fabio.bat.silva@gmail.com>
 * @author Rob Caiger <rob@clocal.co.uk>
 * @since 2.0
 */
class BasicEntityPersister implements EntityPersister
{
    /**
     * @var array
     */
    static private $comparisonMap = [
        Comparison::EQ       => '= %s',
        Comparison::IS       => '= %s',
        Comparison::NEQ      => '!= %s',
        Comparison::GT       => '> %s',
        Comparison::GTE      => '>= %s',
        Comparison::LT       => '< %s',
        Comparison::LTE      => '<= %s',
        Comparison::IN       => 'IN (%s)',
        Comparison::NIN      => 'NOT IN (%s)',
        Comparison::CONTAINS => 'LIKE %s',
    ];

    /**
     * Metadata object that describes the mapping of the mapped entity class.
     *
     * @var \Doctrine\ORM\Mapping\ClassMetadata
     */
    protected $class;

    /**
     * The underlying DBAL Connection of the used EntityManager.
     *
     * @var \Doctrine\DBAL\Connection $conn
     */
    protected $conn;

    /**
     * The database platform.
     *
     * @var \Doctrine\DBAL\Platforms\AbstractPlatform
     */
    protected $platform;

    /**
     * The EntityManager instance.
     *
     * @var EntityManagerInterface
     */
    protected $em;

    /**
     * Queued inserts.
     *
     * @var array
     */
    protected $queuedInserts = [];

    /**
     * The map of column names to DBAL columns used when INSERTing or UPDATEing an entity.
     *
     * @var array<ColumnMetadata>
     *
     * @see prepareInsertData($entity)
     * @see prepareUpdateData($entity)
     */
    protected $columns = [];

    /**
     * The INSERT SQL statement used for entities handled by this persister.
     * This SQL is only generated once per request, if at all.
     *
     * @var string
     */
    private $insertSql;

    /**
     * The quote strategy.
     *
     * @var \Doctrine\ORM\Mapping\QuoteStrategy
     */
    protected $quoteStrategy;

    /**
     * The IdentifierFlattener used for manipulating identifiers
     *
     * @var \Doctrine\ORM\Utility\IdentifierFlattener
     */
    private $identifierFlattener;

    /**
     * @var CachedPersisterContext
     */
    protected $currentPersisterContext;

    /**
     * @var CachedPersisterContext
     */
    private $limitsHandlingContext;

    /**
     * @var CachedPersisterContext
     */
    private $noLimitsContext;

    /**
     * Initializes a new <tt>BasicEntityPersister</tt> that uses the given EntityManager
     * and persists instances of the class described by the given ClassMetadata descriptor.
     *
     * @param EntityManagerInterface $em
     * @param ClassMetadata          $class
     */
    public function __construct(EntityManagerInterface $em, ClassMetadata $class)
    {
        $this->em                    = $em;
        $this->class                 = $class;
        $this->conn                  = $em->getConnection();
        $this->platform              = $this->conn->getDatabasePlatform();
        $this->quoteStrategy         = $em->getConfiguration()->getQuoteStrategy();
        $this->identifierFlattener   = new IdentifierFlattener($em->getUnitOfWork(), $em->getMetadataFactory());
        $this->noLimitsContext       = $this->currentPersisterContext = new CachedPersisterContext(
            $class,
            new Query\ResultSetMapping(),
            false
        );
        $this->limitsHandlingContext = new CachedPersisterContext(
            $class,
            new Query\ResultSetMapping(),
            true
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getClassMetadata()
    {
        return $this->class;
    }

    /**
     * {@inheritdoc}
     */
    public function getResultSetMapping()
    {
        return $this->currentPersisterContext->rsm;
    }

    /**
     * {@inheritdoc}
     */
    public function addInsert($entity)
    {
        $this->queuedInserts[spl_object_hash($entity)] = $entity;
    }

    /**
     * {@inheritdoc}
     */
    public function getInserts()
    {
        return $this->queuedInserts;
    }

    /**
     * {@inheritdoc}
     */
    public function executeInserts()
    {
        if ( ! $this->queuedInserts) {
            return [];
        }

        $postInsertIds  = [];
        $idGenerator    = $this->class->idGenerator;
        $isPostInsertId = $idGenerator->isPostInsertGenerator();

        $stmt       = $this->conn->prepare($this->getInsertSQL());
        $tableName  = $this->class->getTableName();

        foreach ($this->queuedInserts as $entity) {
            $insertData = $this->prepareInsertData($entity);

            if (isset($insertData[$tableName])) {
                $paramIndex = 1;

                foreach ($insertData[$tableName] as $columnName => $value) {
                    $type = $this->columns[$columnName]->getType();

                    $stmt->bindValue($paramIndex++, $value, $type);
                }
            }

            $stmt->execute();

            if ($isPostInsertId) {
                $generatedId = $idGenerator->generate($this->em, $entity);
                $id          = [$this->class->identifier[0] => $generatedId];

                $postInsertIds[] = [
                    'generatedId' => $generatedId,
                    'entity'      => $entity,
                ];
            } else {
                $id = $this->class->getIdentifierValues($entity);
            }

            if ($this->class->isVersioned()) {
                $this->assignDefaultVersionValue($entity, $id);
            }
        }

        $stmt->closeCursor();
        $this->queuedInserts = [];

        return $postInsertIds;
    }

    /**
     * Retrieves the default version value which was created
     * by the preceding INSERT statement and assigns it back in to the
     * entities version field.
     *
     * @param object $entity
     * @param array  $id
     *
     * @return void
     */
    protected function assignDefaultVersionValue($entity, array $id)
    {
        $versionProperty = $this->class->versionProperty;
        $versionValue    = $this->fetchVersionValue($versionProperty, $id);

        $versionProperty->setValue($entity, $versionValue);
    }

    /**
     * Fetches the current version value of a versioned entity.
     *
     * @param \Doctrine\ORM\Mapping\VersionFieldMetadata $versionProperty
     * @param array                                      $id
     *
     * @return mixed
     */
    protected function fetchVersionValue(FieldMetadata $versionProperty, array $id)
    {
        $versionedClass = $versionProperty->getDeclaringClass();
        $tableName      = $versionedClass->table->getQuotedQualifiedName($this->platform);
        $columnName     = $this->platform->quoteIdentifier($versionProperty->getColumnName());
        $identifier     = $this->quoteStrategy->getIdentifierColumnNames($versionedClass, $this->platform);

        // FIXME: Order with composite keys might not be correct
        $sql = 'SELECT ' . $columnName
             . ' FROM '  . $tableName
             . ' WHERE ' . implode(' = ? AND ', $identifier) . ' = ?';

        $flattenedId = $this->identifierFlattener->flattenIdentifier($versionedClass, $id);
        $value       = $this->conn->fetchColumn($sql, array_values($flattenedId));
        $versionType = $versionProperty->getType();

        return $versionType->convertToPHPValue($value, $this->platform);
    }

    /**
     * {@inheritdoc}
     */
    public function update($entity)
    {
        $tableName  = $this->class->getTableName();
        $updateData = $this->prepareUpdateData($entity);

        if ( ! isset($updateData[$tableName]) || ! ($data = $updateData[$tableName])) {
            return;
        }

        $isVersioned     = $this->class->isVersioned();
        $quotedTableName = $this->class->table->getQuotedQualifiedName($this->platform);

        $this->updateTable($entity, $quotedTableName, $data, $isVersioned);

        if ($isVersioned) {
            $id = $this->em->getUnitOfWork()->getEntityIdentifier($entity);

            $this->assignDefaultVersionValue($entity, $id);
        }
    }

    /**
     * Performs an UPDATE statement for an entity on a specific table.
     * The UPDATE can optionally be versioned, which requires the entity to have a version field.
     *
     * @param object  $entity          The entity object being updated.
     * @param string  $quotedTableName The quoted name of the table to apply the UPDATE on.
     * @param array   $updateData      The map of columns to update (column => value).
     * @param boolean $versioned       Whether the UPDATE should be versioned.
     *
     * @return void
     *
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    protected final function updateTable($entity, $quotedTableName, array $updateData, $versioned = false)
    {
        $set    = [];
        $types  = [];
        $params = [];

        foreach ($updateData as $columnName => $value) {
            $column           = $this->columns[$columnName];
            $quotedColumnName = $this->platform->quoteIdentifier($column->getColumnName());
            $type             = $column->getType();
            $placeholder      = $type->convertToDatabaseValueSQL('?', $this->platform);

            $set[]    = $quotedColumnName . ' = ' . $placeholder;
            $params[] = $value;
            $types[]  = $type;
        }

        $where      = [];
        $identifier = $this->em->getUnitOfWork()->getEntityIdentifier($entity);

        foreach ($this->class->identifier as $idField) {
            $params[] = $identifier[$idField];

            if ( ! isset($this->class->associationMappings[$idField])) {
                $property = $this->class->getProperty($idField);

                $types[] = $property->getType();
                $where[] = $this->platform->quoteIdentifier($property->getColumnName());

                continue;
            }

            $association = $this->class->associationMappings[$idField];
            $targetClass = $this->em->getClassMetadata($association->getTargetEntity());
            $joinColumns = $association->getJoinColumns();
            $joinColumn  = reset($joinColumns);

            if (! $joinColumn->getType()) {
                $joinColumn->setType(
                    PersisterHelper::getTypeOfColumn($joinColumn->getReferencedColumnName(), $targetClass, $this->em)
                );
            }

            $where[] = $joinColumn->getColumnName();
            $types[] = $joinColumn->getType();
        }

        if ($versioned) {
            $versionProperty   = $this->class->versionProperty;
            $versionColumnType = $versionProperty->getType();
            $versionColumnName = $this->platform->quoteIdentifier($versionProperty->getColumnName());

            $where[]    = $versionColumnName;
            $types[]    = $versionColumnType;
            $params[]   = $versionProperty->getValue($entity);

            switch ($versionColumnType->getName()) {
                case Type::SMALLINT:
                case Type::INTEGER:
                case Type::BIGINT:
                    $set[] = $versionColumnName . ' = ' . $versionColumnName . ' + 1';
                    break;

                case Type::DATETIME:
                    $set[] = $versionColumnName . ' = CURRENT_TIMESTAMP';
                    break;
            }
        }

        $sql = 'UPDATE ' . $quotedTableName
             . ' SET ' . implode(', ', $set)
             . ' WHERE ' . implode(' = ? AND ', $where) . ' = ?';

        $result = $this->conn->executeUpdate($sql, $params, $types);

        if ($versioned && ! $result) {
            throw OptimisticLockException::lockFailed($entity);
        }
    }

    /**
     * @todo Add check for platform if it supports foreign keys/cascading.
     *
     * @param array $identifier
     *
     * @return void
     */
    protected function deleteJoinTableRecords($identifier)
    {
        foreach ($this->class->associationMappings as $association) {
            if (! ($association instanceof ManyToManyAssociationMetadata)) {
                continue;
            }

            // @Todo this only covers scenarios with no inheritance or of the same level. Is there something
            // like self-referential relationship between different levels of an inheritance hierarchy? I hope not!
            $selfReferential   = $association->getTargetEntity() === $association->getSourceEntity();
            $owningAssociation = $association;
            $otherColumns      = [];
            $otherKeys         = [];
            $keys              = [];

            if ( ! $owningAssociation->isOwningSide()) {
                $class       = $this->em->getClassMetadata($association->getTargetEntity());
                $owningAssociation = $class->associationMappings[$association->getMappedBy()];
            }

            $joinTable     = $owningAssociation->getJoinTable();
            $joinTableName = $joinTable->getQuotedQualifiedName($this->platform);
            $joinColumns   = $association->isOwningSide()
                ? $joinTable->getJoinColumns()
                : $joinTable->getInverseJoinColumns()
            ;

            if ($selfReferential) {
                $otherColumns = ! $association->isOwningSide()
                    ? $joinTable->getJoinColumns()
                    : $joinTable->getInverseJoinColumns()
                ;
            }

            $isOnDeleteCascade = false;

            foreach ($joinColumns as $joinColumn) {
                $keys[] = $this->platform->quoteIdentifier($joinColumn->getColumnName());

                if ($joinColumn->isOnDeleteCascade()) {
                    $isOnDeleteCascade = true;
                }
            }

            foreach ($otherColumns as $joinColumn) {
                $otherKeys[] = $this->platform->quoteIdentifier($joinColumn->getColumnName());

                if ($joinColumn->isOnDeleteCascade()) {
                    $isOnDeleteCascade = true;
                }
            }

            if ($isOnDeleteCascade) {
                continue;
            }

            $this->conn->delete($joinTableName, array_combine($keys, $identifier));

            if ($selfReferential) {
                $this->conn->delete($joinTableName, array_combine($otherKeys, $identifier));
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete($entity)
    {
        $class      = $this->class;
        $unitOfWork = $this->em->getUnitOfWork();
        $identifier = $unitOfWork->getEntityIdentifier($entity);
        $tableName  = $class->table->getQuotedQualifiedName($this->platform);

        $types = [];
        $id = [];

        foreach ($class->identifier as $field) {
            if (($property = $class->getProperty($field)) !== null) {
                $columnName       = $property->getColumnName();
                $quotedColumnName = $this->platform->quoteIdentifier($columnName);

                $id[$quotedColumnName] = $identifier[$field];
                $types[] = $property->getType();

                continue;
            }

            $association = $class->associationMappings[$field];
            $targetClass = $this->em->getClassMetadata($association->getTargetEntity());
            $joinColumns = $association instanceof ManyToManyAssociationMetadata
                ? $association->getTable()->getJoinColumns()
                : $association->getJoinColumns()
            ;

            $associationValue = null;

            if (($value = $identifier[$field]) !== null) {
                // @todo guilhermeblanco Make sure we do not have flat association values.
                if (! is_array($value)) {
                    $value = [$targetClass->identifier[0] => $value];
                }

                $associationValue = $value;
            }

            foreach ($joinColumns as $joinColumn) {
                $joinColumnName       = $joinColumn->getColumnName();
                $referencedColumnName = $joinColumn->getReferencedColumnName();
                $quotedJoinColumnName = $this->platform->quoteIdentifier($joinColumnName);
                $targetField          = $targetClass->fieldNames[$referencedColumnName];

                if (! $joinColumn->getType()) {
                    $joinColumn->setType(
                        PersisterHelper::getTypeOfColumn($referencedColumnName, $targetClass, $this->em)
                    );
                }

                $id[$quotedJoinColumnName] = $associationValue ? $associationValue[$targetField] : null;
                $types[] = $joinColumn->getType();
            }
        }

        $this->deleteJoinTableRecords($identifier);

        return (bool) $this->conn->delete($tableName, $id, $types);
    }

    /**
     * Prepares the changeset of an entity for database insertion (UPDATE).
     *
     * The changeset is obtained from the currently running UnitOfWork.
     *
     * During this preparation the array that is passed as the second parameter is filled with
     * <columnName> => <value> pairs, grouped by table name.
     *
     * Example:
     * <code>
     * array(
     *    'foo_table' => array('column1' => 'value1', 'column2' => 'value2', ...),
     *    'bar_table' => array('columnX' => 'valueX', 'columnY' => 'valueY', ...),
     *    ...
     * )
     * </code>
     *
     * @param object $entity The entity for which to prepare the data.
     *
     * @return array The prepared data.
     */
    protected function prepareUpdateData($entity)
    {
        $uow                 = $this->em->getUnitOfWork();
        $result              = [];
        $versionPropertyName = $this->class->isVersioned()
            ? $this->class->versionProperty->getName()
            : null
        ;

        foreach ($uow->getEntityChangeSet($entity) as $field => $change) {
            if ($versionPropertyName === $field) {
                continue;
            }

            /*if (isset($this->class->embeddedClasses[$field])) {
                continue;
            }*/

            $owningTable = $this->getOwningTable($field);
            $newVal      = $change[1];

            if (($property = $this->class->getProperty($field)) !== null) {
                // @todo this should be used instead
                //$tableName  = $property->getTableName();
                $columnName = $property->getColumnName();

                $this->columns[$columnName] = $property;

                $result[$owningTable][$columnName] = $newVal;

                continue;
            }

            $association = $this->class->associationMappings[$field];

            // Only owning side of x-1 associations can have a FK column.
            if (! $association->isOwningSide() || ! $association instanceof ToOneAssociationMetadata) {
                continue;
            }

            if ($newVal !== null) {
                $oid = spl_object_hash($newVal);

                if (isset($this->queuedInserts[$oid]) || $uow->isScheduledForInsert($newVal)) {
                    // The associated entity $newVal is not yet persisted, so we must
                    // set $newVal = null, in order to insert a null value and schedule an
                    // extra update on the UnitOfWork.
                    $uow->scheduleExtraUpdate($entity, [$field => [null, $newVal]]);

                    $newVal = null;
                }
            }

            $newValId = null;

            if ($newVal !== null) {
                $newValId = $uow->getEntityIdentifier($newVal);
            }

            $targetClass = $this->em->getClassMetadata($association->getTargetEntity());

            foreach ($association->getJoinColumns() as $joinColumn) {
                $columnName           = $joinColumn->getColumnName();
                $referencedColumnName = $joinColumn->getReferencedColumnName();
                $targetField          = $targetClass->fieldNames[$referencedColumnName];

                if (! $joinColumn->getType()) {
                    $joinColumn->setType(
                        PersisterHelper::getTypeOfColumn($referencedColumnName, $targetClass, $this->em)
                    );
                }

                $this->columns[$columnName] = $joinColumn;

                $result[$owningTable][$columnName] = $newValId ? $newValId[$targetField] : null;
            }
        }

        return $result;
    }

    /**
     * Prepares the data changeset of a managed entity for database insertion (initial INSERT).
     * The changeset of the entity is obtained from the currently running UnitOfWork.
     *
     * The default insert data preparation is the same as for updates.
     *
     * @param object $entity The entity for which to prepare the data.
     *
     * @return array The prepared data for the tables to update.
     *
     * @see prepareUpdateData
     */
    protected function prepareInsertData($entity)
    {
        return $this->prepareUpdateData($entity);
    }

    /**
     * {@inheritdoc}
     */
    public function getOwningTable($fieldName)
    {
        return $this->class->getTableName();
    }

    /**
     * {@inheritdoc}
     */
    public function load(
        array $criteria,
        $entity = null,
        AssociationMetadata $association = null,
        array $hints = [],
        $lockMode = null,
        $limit = null,
        array $orderBy = []
    )
    {
        $this->switchPersisterContext(null, $limit);

        $sql = $this->getSelectSQL($criteria, $association, $lockMode, $limit, null, $orderBy);

        list($params, $types) = $this->expandParameters($criteria);

        $stmt = $this->conn->executeQuery($sql, $params, $types);

        if ($entity !== null) {
            $hints[Query::HINT_REFRESH]         = true;
            $hints[Query::HINT_REFRESH_ENTITY]  = $entity;
        }

        $hydrator = $this->em->newHydrator($this->currentPersisterContext->selectJoinSql ? Query::HYDRATE_OBJECT : Query::HYDRATE_SIMPLEOBJECT);
        $entities = $hydrator->hydrateAll($stmt, $this->currentPersisterContext->rsm, $hints);

        return $entities ? $entities[0] : null;
    }

    /**
     * {@inheritdoc}
     */
    public function loadById(array $identifier, $entity = null)
    {
        return $this->load($identifier, $entity);
    }

    /**
     * {@inheritdoc}
     */
    public function loadToOneEntity(ToOneAssociationMetadata $association, $sourceEntity, array $identifier = [])
    {
        $unitOfWork   = $this->em->getUnitOfWork();
        $targetEntity = $association->getTargetEntity();

        if (($foundEntity = $unitOfWork->tryGetById($identifier, $targetEntity)) != false) {
            return $foundEntity;
        }

        $targetClass = $this->em->getClassMetadata($targetEntity);

        if ($association->isOwningSide()) {
            $inversedBy            = $association->getInversedBy();
            $isInverseSingleValued = $inversedBy && ! $targetClass->isCollectionValuedAssociation($inversedBy);

            // Mark inverse side as fetched in the hints, otherwise the UoW would
            // try to load it in a separate query (remember: to-one inverse sides can not be lazy).
            $hints = [];

            if ($isInverseSingleValued) {
                $hints['fetched']["r"][$inversedBy] = true;
            }

            /* cascade read-only status
            if ($this->em->getUnitOfWork()->isReadOnly($sourceEntity)) {
                $hints[Query::HINT_READ_ONLY] = true;
            }
            */

            $entity = $this->load($identifier, null, $association, $hints);

            // Complete bidirectional association, if necessary
            if ($entity !== null && $isInverseSingleValued) {
                $targetClass->reflFields[$inversedBy]->setValue($entity, $sourceEntity);
            }

            return $entity;
        }

        // @todo guilhermeblanco $association->getDeclaringClass()???
        $mappedBy         = $association->getMappedBy();
        $sourceClass      = $this->em->getClassMetadata($association->getSourceEntity());
        $owningAssoc      = $targetClass->getAssociationMapping($mappedBy);
        $targetTableAlias = $this->getSQLTableAlias($targetClass->getTableName());

        foreach ($owningAssoc->getJoinColumns() as $joinColumn) {
            $sourceKeyColumn = $joinColumn->getReferencedColumnName();
            $targetKeyColumn = $joinColumn->getColumnName();

            if ( ! isset($sourceClass->fieldNames[$sourceKeyColumn])) {
                throw MappingException::joinColumnMustPointToMappedField(
                    $sourceClass->name, $sourceKeyColumn
                );
            }

            $field = $sourceClass->fieldNames[$sourceKeyColumn];
            $value = $sourceClass->reflFields[$field]->getValue($sourceEntity);

            // unset the old value and set the new sql aliased value here. By definition
            // unset($identifier[$targetKeyColumn] works here with how UnitOfWork::createEntity() calls this method.
            $identifier[$targetTableAlias . "." . $targetKeyColumn] = $value;

            unset($identifier[$targetKeyColumn]);
        }

        $entity = $this->load($identifier, null, $association);

        if ($entity !== null) {
            $targetClass->reflFields[$mappedBy]->setValue($entity, $sourceEntity);
        }

        return $entity;
    }

    /**
     * {@inheritdoc}
     */
    public function refresh(array $id, $entity, $lockMode = null)
    {
        $sql = $this->getSelectSQL($id, null, $lockMode);
        list($params, $types) = $this->expandParameters($id);
        $stmt = $this->conn->executeQuery($sql, $params, $types);

        $hydrator = $this->em->newHydrator(Query::HYDRATE_OBJECT);
        $hydrator->hydrateAll($stmt, $this->currentPersisterContext->rsm, [Query::HINT_REFRESH => true]);
    }

    /**
     * {@inheritDoc}
     */
    public function count($criteria = [])
    {
        $sql = $this->getCountSQL($criteria);

        list($params, $types) = ($criteria instanceof Criteria)
            ? $this->expandCriteriaParameters($criteria)
            : $this->expandParameters($criteria);

        return (int) $this->conn->executeQuery($sql, $params, $types)->fetchColumn();
    }

    /**
     * {@inheritdoc}
     */
    public function loadCriteria(Criteria $criteria)
    {
        $orderBy = $criteria->getOrderings();
        $limit   = $criteria->getMaxResults();
        $offset  = $criteria->getFirstResult();
        $query   = $this->getSelectSQL($criteria, null, null, $limit, $offset, $orderBy);

        list($params, $types) = $this->expandCriteriaParameters($criteria);

        $stmt         = $this->conn->executeQuery($query, $params, $types);
        $rsm          = $this->currentPersisterContext->rsm;
        $hints        = [UnitOfWork::HINT_DEFEREAGERLOAD => true];
        $hydratorType = $this->currentPersisterContext->selectJoinSql ? Query::HYDRATE_OBJECT : Query::HYDRATE_SIMPLEOBJECT;
        $hydrator     = $this->em->newHydrator($hydratorType);

        return $hydrator->hydrateAll($stmt, $rsm, $hints);
    }

    /**
     * {@inheritdoc}
     */
    public function expandCriteriaParameters(Criteria $criteria)
    {
        $expression = $criteria->getWhereExpression();
        $sqlParams  = [];
        $sqlTypes   = [];

        if ($expression === null) {
            return [$sqlParams, $sqlTypes];
        }

        $valueVisitor = new SqlValueVisitor();

        $valueVisitor->dispatch($expression);

        list($params, $types) = $valueVisitor->getParamsAndTypes();

        foreach ($params as $param) {
            $sqlParams = array_merge($sqlParams, $this->getValues($param));
        }

        foreach ($types as $type) {
            list ($field, $value) = $type;
            $sqlTypes = array_merge($sqlTypes, $this->getTypes($field, $value, $this->class));
        }

        return [$sqlParams, $sqlTypes];
    }

    /**
     * {@inheritdoc}
     */
    public function loadAll(array $criteria = [], array $orderBy = [], $limit = null, $offset = null)
    {
        $this->switchPersisterContext($offset, $limit);

        $sql = $this->getSelectSQL($criteria, null, null, $limit, $offset, $orderBy);

        list($params, $types) = $this->expandParameters($criteria);

        $stmt         = $this->conn->executeQuery($sql, $params, $types);
        $rsm          = $this->currentPersisterContext->rsm;
        $hints        = [UnitOfWork::HINT_DEFEREAGERLOAD => true];
        $hydratorType = $this->currentPersisterContext->selectJoinSql ? Query::HYDRATE_OBJECT : Query::HYDRATE_SIMPLEOBJECT;
        $hydrator     = $this->em->newHydrator($hydratorType);

        return $hydrator->hydrateAll($stmt, $rsm, $hints);
    }

    /**
     * {@inheritdoc}
     */
    public function getManyToManyCollection(
        ManyToManyAssociationMetadata $association,
        $sourceEntity,
        $offset = null,
        $limit = null
    )
    {
        $this->switchPersisterContext($offset, $limit);

        $stmt = $this->getManyToManyStatement($association, $sourceEntity, $offset, $limit);

        return $this->loadArrayFromStatement($association, $stmt);
    }

    /**
     * {@inheritdoc}
     */
    public function loadManyToManyCollection(
        ManyToManyAssociationMetadata $association,
        $sourceEntity,
        PersistentCollection $collection
    )
    {
        $stmt = $this->getManyToManyStatement($association, $sourceEntity);

        return $this->loadCollectionFromStatement($association, $stmt, $collection);
    }

    /**
     * Loads an array of entities from a given DBAL statement.
     *
     * @param ToManyAssociationMetadata $association
     * @param \Doctrine\DBAL\Statement  $stmt
     *
     * @return array
     */
    private function loadArrayFromStatement(ToManyAssociationMetadata $association, $stmt)
    {
        $rsm = $this->currentPersisterContext->rsm;

        if ($association->getIndexedBy()) {
            $rsm = clone ($this->currentPersisterContext->rsm); // this is necessary because the "default rsm" should be changed.
            $rsm->addIndexBy('r', $association->getIndexedBy());
        }

        $hydrator = $this->em->newHydrator(Query::HYDRATE_OBJECT);
        $hints    = [UnitOfWork::HINT_DEFEREAGERLOAD => true];

        return $hydrator->hydrateAll($stmt, $rsm, $hints);
    }

    /**
     * Hydrates a collection from a given DBAL statement.
     *
     * @param ToManyAssociationMetadata $association
     * @param \Doctrine\DBAL\Statement  $stmt
     * @param PersistentCollection      $collection
     *
     * @return array
     */
    private function loadCollectionFromStatement(ToManyAssociationMetadata $association, $stmt, $collection)
    {
        $rsm = $this->currentPersisterContext->rsm;

        if ($association->getIndexedBy()) {
            $rsm = clone ($this->currentPersisterContext->rsm); // this is necessary because the "default rsm" should be changed.
            $rsm->addIndexBy('r', $association->getIndexedBy());
        }

        $hydrator = $this->em->newHydrator(Query::HYDRATE_OBJECT);
        $hints    = [
            UnitOfWork::HINT_DEFEREAGERLOAD => true,
            'collection' => $collection
        ];

        return $hydrator->hydrateAll($stmt, $rsm, $hints);
    }

    /**
     * @param ManyToManyAssociationMetadata $association
     * @param object                        $sourceEntity
     * @param int|null                      $offset
     * @param int|null                      $limit
     *
     * @return \Doctrine\DBAL\Driver\Statement
     *
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function getManyToManyStatement(
        ManyToManyAssociationMetadata $association,
        $sourceEntity,
        $offset = null,
        $limit = null
    )
    {
        $this->switchPersisterContext($offset, $limit);

        /** @var ClassMetadata $sourceClass */
        $sourceClass    = $this->em->getClassMetadata($association->getSourceEntity());
        $class          = $sourceClass;
        $owningAssoc    = $association;
        $criteria       = [];
        $parameters     = [];

        if (! $association->isOwningSide()) {
            $class       = $this->em->getClassMetadata($association->getTargetEntity());
            $owningAssoc = $class->associationMappings[$association->getMappedBy()];
        }

        $joinTable     = $owningAssoc->getJoinTable();
        $joinTableName = $joinTable->getQuotedQualifiedName($this->platform);
        $joinColumns   = $association->isOwningSide()
            ? $joinTable->getJoinColumns()
            : $joinTable->getInverseJoinColumns()
        ;

        foreach ($joinColumns as $joinColumn) {
            $quotedColumnName = $this->platform->quoteIdentifier($joinColumn->getColumnName());
            $fieldName        = $sourceClass->fieldNames[$joinColumn->getReferencedColumnName()];
            $value            = $sourceClass->reflFields[$fieldName]->getValue($sourceEntity);

            if (isset($sourceClass->associationMappings[$fieldName])) {
                $targetMapping = $sourceClass->associationMappings[$fieldName];
                $targetClass   = $this->em->getClassMetadata($targetMapping->getTargetEntity());

                $id    = $this->em->getUnitOfWork()->getEntityIdentifier($value);
                $value = $id[$targetClass->identifier[0]];
            }

            // @todo guilhermeblanco How could we handle composite primary keys here?
            $criteria[$joinTableName . '.' . $quotedColumnName] = $value;
            $parameters[] = [
                'value' => $value,
                'field' => $fieldName,
                'class' => $sourceClass,
            ];
        }

        $sql = $this->getSelectSQL($criteria, $association, null, $limit, $offset);

        list($params, $types) = $this->expandToManyParameters($parameters);

        return $this->conn->executeQuery($sql, $params, $types);
    }

    /**
     * {@inheritdoc}
     */
    public function getSelectSQL(
        $criteria,
        AssociationMetadata $association = null,
        $lockMode = null,
        $limit = null,
        $offset = null,
        array $orderBy = []
    )
    {
        $this->switchPersisterContext($offset, $limit);

        $lockSql    = '';
        $joinSql    = '';
        $orderBySql = '';

        if ($association instanceof ManyToManyAssociationMetadata) {
            $joinSql = $this->getSelectManyToManyJoinSQL($association);
        }

        if ($association instanceof ToManyAssociationMetadata && $association->getOrderBy()) {
            $orderBy = $association->getOrderBy();
        }

        if ($orderBy) {
            $orderBySql = $this->getOrderBySQL($orderBy, $this->getSQLTableAlias($this->class->getTableName()));
        }

        $conditionSql = ($criteria instanceof Criteria)
            ? $this->getSelectConditionCriteriaSQL($criteria)
            : $this->getSelectConditionSQL($criteria, $association);

        switch ($lockMode) {
            case LockMode::PESSIMISTIC_READ:
                $lockSql = ' ' . $this->platform->getReadLockSql();
                break;

            case LockMode::PESSIMISTIC_WRITE:
                $lockSql = ' ' . $this->platform->getWriteLockSql();
                break;
        }

        $columnList = $this->getSelectColumnsSQL();
        $tableAlias = $this->getSQLTableAlias($this->class->getTableName());
        $filterSql  = $this->generateFilterConditionSQL($this->class, $tableAlias);
        $tableName  = $this->class->table->getQuotedQualifiedName($this->platform);

        if ('' !== $filterSql) {
            $conditionSql = $conditionSql
                ? $conditionSql . ' AND ' . $filterSql
                : $filterSql;
        }

        $select = 'SELECT ' . $columnList;
        $from   = ' FROM ' . $tableName . ' '. $tableAlias;
        $join   = $this->currentPersisterContext->selectJoinSql . $joinSql;
        $where  = ($conditionSql ? ' WHERE ' . $conditionSql : '');
        $lock   = $this->platform->appendLockHint($from, $lockMode);
        $query  = $select
            . $lock
            . $join
            . $where
            . $orderBySql;

        return $this->platform->modifyLimitQuery($query, $limit, $offset) . $lockSql;
    }

    /**
     * {@inheritDoc}
     */
    public function getCountSQL($criteria = [])
    {
        $tableName  = $this->class->table->getQuotedQualifiedName($this->platform);
        $tableAlias = $this->getSQLTableAlias($this->class->getTableName());

        $conditionSql = ($criteria instanceof Criteria)
            ? $this->getSelectConditionCriteriaSQL($criteria)
            : $this->getSelectConditionSQL($criteria);

        $filterSql = $this->generateFilterConditionSQL($this->class, $tableAlias);

        if ('' !== $filterSql) {
            $conditionSql = $conditionSql
                ? $conditionSql . ' AND ' . $filterSql
                : $filterSql;
        }

        $sql = 'SELECT COUNT(*) '
            . 'FROM ' . $tableName . ' ' . $tableAlias
            . (empty($conditionSql) ? '' : ' WHERE ' . $conditionSql);

        return $sql;
    }

    /**
     * Gets the ORDER BY SQL snippet for ordered collections.
     *
     * @param array  $orderBy
     * @param string $baseTableAlias
     *
     * @return string
     *
     * @throws \Doctrine\ORM\ORMException
     */
    protected final function getOrderBySQL(array $orderBy, $baseTableAlias)
    {
        if (! $orderBy) {
            return '';
        }

        $orderByList = [];

        foreach ($orderBy as $fieldName => $orientation) {
            $orientation = strtoupper(trim($orientation));

            if (! in_array($orientation, ['ASC', 'DESC'])) {
                throw ORMException::invalidOrientation($this->class->name, $fieldName);
            }

            if (($property = $this->class->getProperty($fieldName)) !== null) {
                $tableAlias = $this->getSQLTableAlias($property->getTableName());
                $columnName = $this->platform->quoteIdentifier($property->getColumnName());

                $orderByList[] = $tableAlias . '.' . $columnName . ' ' . $orientation;

                continue;
            }

            if (isset($this->class->associationMappings[$fieldName])) {
                $association = $this->class->associationMappings[$fieldName];

                if ( ! $association->isOwningSide()) {
                    throw ORMException::invalidFindByInverseAssociation($this->class->name, $fieldName);
                }

                $class      = $this->class->isInheritedAssociation($fieldName)
                    ? $association->getDeclaringClass()
                    : $this->class;
                $tableAlias = $this->getSQLTableAlias($class->getTableName());

                foreach ($association->getJoinColumns() as $joinColumn) {
                    /* @var JoinColumnMetadata $joinColumn */
                    $quotedColumnName = $this->platform->quoteIdentifier($joinColumn->getColumnName());

                    $orderByList[] = $tableAlias . '.' . $quotedColumnName . ' ' . $orientation;
                }

                continue;
            }

            throw ORMException::unrecognizedField($fieldName);
        }

        return ' ORDER BY ' . implode(', ', $orderByList);
    }

    /**
     * Gets the SQL fragment with the list of columns to select when querying for
     * an entity in this persister.
     *
     * Subclasses should override this method to alter or change the select column
     * list SQL fragment. Note that in the implementation of BasicEntityPersister
     * the resulting SQL fragment is generated only once and cached in {@link selectColumnListSql}.
     * Subclasses may or may not do the same.
     *
     * @return string The SQL fragment.
     */
    protected function getSelectColumnsSQL()
    {
        if ($this->currentPersisterContext->selectColumnListSql !== null) {
            return $this->currentPersisterContext->selectColumnListSql;
        }


        $this->currentPersisterContext->rsm->addEntityResult($this->class->name, 'r'); // r for root
        $this->currentPersisterContext->selectJoinSql    = '';

        $eagerAliasCounter = 0;
        $columnList        = [];

        foreach ($this->class->fieldNames as $field) {
            if (! $this->class->hasField($field)) {
                continue;
            }

            $columnList[] = $this->getSelectColumnSQL($field, $this->class);
        }

        foreach ($this->class->associationMappings as $assocField => $association) {
            $assocColumnSQL = $this->getSelectColumnAssociationSQL($assocField, $association, $this->class);

            if ($assocColumnSQL) {
                $columnList[] = $assocColumnSQL;
            }

            $isAssocToOneInverseSide = $association instanceof ToOneAssociationMetadata && ! $association->isOwningSide();
            $isAssocFromOneEager     = ! $association instanceof ManyToManyAssociationMetadata && $association->getFetchMode() === FetchMode::EAGER;

            if ( ! ($isAssocFromOneEager || $isAssocToOneInverseSide)) {
                continue;
            }

            if ($association instanceof ToManyAssociationMetadata && $this->currentPersisterContext->handlesLimits) {
                continue;
            }

            $eagerEntity = $this->em->getClassMetadata($association->getTargetEntity());

            if ($eagerEntity->inheritanceType !== InheritanceType::NONE) {
                continue; // now this is why you shouldn't use inheritance
            }

            $assocAlias = 'e' . ($eagerAliasCounter++);
            $this->currentPersisterContext->rsm->addJoinedEntityResult($association->getTargetEntity(), $assocAlias, 'r', $assocField);

            foreach ($eagerEntity->fieldNames as $field) {
                $columnSQL = $eagerEntity->hasField($field)
                    ? $this->getSelectColumnSQL($field, $eagerEntity, $assocAlias)
                    : $this->getSelectColumnAssociationSQL(
                        $field, $eagerEntity->associationMappings[$field], $eagerEntity, $assocAlias
                    )
                ;

                if ($columnSQL) {
                    $columnList[] = $columnSQL;
                }
            }

            $owningAssociation = $association;
            $joinCondition     = [];

            if ($association instanceof ToManyAssociationMetadata && $association->getIndexedBy()) {
                $this->currentPersisterContext->rsm->addIndexBy($assocAlias, $association->getIndexedBy());
            }

            if (! $association->isOwningSide()) {
                $eagerEntity       = $this->em->getClassMetadata($association->getTargetEntity());
                $owningAssociation = $eagerEntity->getAssociationMapping($association->getMappedBy());
            }

            $joinTableAlias = $this->getSQLTableAlias($eagerEntity->getTableName(), $assocAlias);
            $joinTableName  = $eagerEntity->table->getQuotedQualifiedName($this->platform);

            $this->currentPersisterContext->selectJoinSql .= ' ' . $this->getJoinSQLForAssociation($association);

            $sourceClass      = $this->em->getClassMetadata($owningAssociation->getSourceEntity());
            $targetClass      = $this->em->getClassMetadata($owningAssociation->getTargetEntity());
            $targetTableAlias = $this->getSQLTableAlias($targetClass->getTableName(), $association->isOwningSide() ? $assocAlias : '');
            $sourceTableAlias = $this->getSQLTableAlias($sourceClass->getTableName(), $association->isOwningSide() ? '' : $assocAlias);

            foreach ($owningAssociation->getJoinColumns() as $joinColumn) {
                $joinCondition[] = sprintf(
                    '%s.%s = %s.%s',
                    $sourceTableAlias,
                    $this->platform->quoteIdentifier($joinColumn->getColumnName()),
                    $targetTableAlias,
                    $this->platform->quoteIdentifier($joinColumn->getReferencedColumnName())
                );
            }

            // Add filter SQL
            if ($filterSql = $this->generateFilterConditionSQL($eagerEntity, $targetTableAlias)) {
                $joinCondition[] = $filterSql;
            }

            $this->currentPersisterContext->selectJoinSql .= ' ' . $joinTableName . ' ' . $joinTableAlias . ' ON ';
            $this->currentPersisterContext->selectJoinSql .= implode(' AND ', $joinCondition);
        }

        $this->currentPersisterContext->selectColumnListSql = implode(', ', $columnList);

        return $this->currentPersisterContext->selectColumnListSql;
    }

    /**
     * Gets the SQL join fragment used when selecting entities from an association.
     *
     * @param string              $field
     * @param AssociationMetadata $association
     * @param ClassMetadata       $class
     * @param string              $alias
     *
     * @return string
     */
    protected function getSelectColumnAssociationSQL($field, AssociationMetadata $association, ClassMetadata $class, $alias = 'r')
    {
        if (! ($association->isOwningSide() && $association instanceof ToOneAssociationMetadata)) {
            return '';
        }

        $columnList    = [];
        $targetClass   = $this->em->getClassMetadata($association->getTargetEntity());
        $sqlTableAlias = $this->getSQLTableAlias($class->getTableName(), ($alias == 'r' ? '' : $alias));

        foreach ($association->getJoinColumns() as $joinColumn) {
            $columnName       = $joinColumn->getColumnName();
            $quotedColumnName = $this->platform->quoteIdentifier($columnName);
            $resultColumnName = $this->getSQLColumnAlias($columnName);

            if (! $joinColumn->getType()) {
                $joinColumn->setType(
                    PersisterHelper::getTypeOfColumn($joinColumn->getReferencedColumnName(), $targetClass, $this->em)
                );
            }

            $this->currentPersisterContext->rsm->addMetaResult(
                $alias,
                $resultColumnName,
                $columnName,
                $association->isPrimaryKey(),
                $joinColumn->getType()
            );

            $columnList[] = sprintf('%s.%s AS %s', $sqlTableAlias, $quotedColumnName, $resultColumnName);
        }

        return implode(', ', $columnList);
    }

    /**
     * Gets the SQL join fragment used when selecting entities from a
     * many-to-many association.
     *
     * @param ManyToManyAssociationMetadata $manyToMany
     *
     * @return string
     */
    protected function getSelectManyToManyJoinSQL(ManyToManyAssociationMetadata $association)
    {
        $conditions        = [];
        $owningAssociation = $association;
        $sourceTableAlias  = $this->getSQLTableAlias($this->class->getTableName());

        if (! $association->isOwningSide()) {
            $targetEntity      = $this->em->getClassMetadata($association->getTargetEntity());
            $owningAssociation = $targetEntity->associationMappings[$association->getMappedBy()];
        }

        $joinTable     = $owningAssociation->getJoinTable();
        $joinTableName = $joinTable->getQuotedQualifiedName($this->platform);
        $joinColumns   = $association->isOwningSide() ? $joinTable->getInverseJoinColumns() : $joinTable->getJoinColumns();

        foreach ($joinColumns as $joinColumn) {
            $conditions[] = sprintf(
                '%s.%s = %s.%s',
                $sourceTableAlias,
                $this->platform->quoteIdentifier($joinColumn->getReferencedColumnName()),
                $joinTableName,
                $this->platform->quoteIdentifier($joinColumn->getColumnName())
            );
        }

        return ' INNER JOIN ' . $joinTableName . ' ON ' . implode(' AND ', $conditions);
    }

    /**
     * {@inheritdoc}
     */
    public function getInsertSQL()
    {
        if ($this->insertSql !== null) {
            return $this->insertSql;
        }

        $columns   = $this->getInsertColumnList();
        $tableName = $this->class->table->getQuotedQualifiedName($this->platform);

        if (empty($columns)) {
            $property        = $this->class->getProperty($this->class->identifier[0]);
            $identityColumn  = $this->platform->quoteIdentifier($property->getColumnName());

            $this->insertSql = $this->platform->getEmptyIdentityInsertSQL($tableName, $identityColumn);

            return $this->insertSql;
        }

        $quotedColumns = [];
        $values        = [];

        foreach ($columns as $columnName) {
            $column = $this->columns[$columnName];

            $quotedColumns[] = $this->platform->quoteIdentifier($column->getColumnName());
            $values[]        = $column->getType()->convertToDatabaseValueSQL('?', $this->platform);
        }

        $quotedColumns = implode(', ', $quotedColumns);
        $values        = implode(', ', $values);

        $this->insertSql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $tableName, $quotedColumns, $values);

        return $this->insertSql;
    }

    /**
     * Gets the list of columns to put in the INSERT SQL statement.
     *
     * Subclasses should override this method to alter or change the list of
     * columns placed in the INSERT statements used by the persister.
     *
     * @return array The list of columns.
     */
    protected function getInsertColumnList()
    {
        $columns             = [];
        $versionPropertyName = $this->class->isVersioned()
            ? $this->class->versionProperty->getName()
            : null
        ;

        foreach ($this->class->reflFields as $name => $field) {
            if ($versionPropertyName === $name) {
                continue;
            }

            /*if (isset($this->class->embeddedClasses[$name])) {
                continue;
            }*/

            if (isset($this->class->associationMappings[$name])) {
                $association = $this->class->associationMappings[$name];

                if ($association->isOwningSide() && $association instanceof ToOneAssociationMetadata) {
                    $targetClass = $this->em->getClassMetadata($association->getTargetEntity());

                    foreach ($association->getJoinColumns() as $joinColumn) {
                        $columnName           = $joinColumn->getColumnName();
                        $referencedColumnName = $joinColumn->getReferencedColumnName();

                        if (! $joinColumn->getType()) {
                            $joinColumn->setType(
                                PersisterHelper::getTypeOfColumn($referencedColumnName, $targetClass, $this->em)
                            );
                        }

                        $columns[] = $columnName;

                        $this->columns[$columnName] = $joinColumn;
                    }
                }

                continue;
            }

            if ($this->class->generatorType !== GeneratorType::IDENTITY || $this->class->identifier[0] !== $name) {
                $property   = $this->class->getProperty($name);
                $columnName = $property->getColumnName();

                $columns[] = $columnName;

                $this->columns[$columnName] = $property;
            }
        }

        return $columns;
    }

    /**
     * Gets the SQL snippet of a qualified column name for the given field name.
     *
     * @param string        $field The field name.
     * @param ClassMetadata $class The class that declares this field. The table this class is
     *                             mapped to must own the column for the given field.
     * @param string        $alias
     *
     * @return string
     */
    protected function getSelectColumnSQL($field, ClassMetadata $class, $alias = 'r')
    {
        $property     = $class->getProperty($field);
        $columnAlias  = $this->getSQLColumnAlias($property->getColumnName());
        $sql          = sprintf(
            '%s.%s',
            $this->getSQLTableAlias($property->getTableName(), ($alias == 'r' ? '' : $alias)),
            $this->platform->quoteIdentifier($property->getColumnName())
        );

        $this->currentPersisterContext->rsm->addFieldResult($alias, $columnAlias, $field, $class->name);

        return $property->getType()->convertToPHPValueSQL($sql, $this->platform) . ' AS ' . $columnAlias;
    }

    /**
     * Gets the SQL table alias for the given class name.
     *
     * @param string $tableName
     * @param string $assocName
     *
     * @return string The SQL table alias.
     */
    protected function getSQLTableAlias($tableName, $assocName = '')
    {
        if ($tableName) {
            $tableName .= '#' . $assocName;
        }

        if (isset($this->currentPersisterContext->sqlTableAliases[$tableName])) {
            return $this->currentPersisterContext->sqlTableAliases[$tableName];
        }

        $tableAlias = 't' . $this->currentPersisterContext->sqlAliasCounter++;

        $this->currentPersisterContext->sqlTableAliases[$tableName] = $tableAlias;

        return $tableAlias;
    }

    /**
     * {@inheritdoc}
     */
    public function lock(array $criteria, $lockMode)
    {
        $lockSql      = '';
        $conditionSql = $this->getSelectConditionSQL($criteria);

        switch ($lockMode) {
            case LockMode::PESSIMISTIC_READ:
                $lockSql = $this->platform->getReadLockSql();

                break;
            case LockMode::PESSIMISTIC_WRITE:

                $lockSql = $this->platform->getWriteLockSql();
                break;
        }

        $lock  = $this->getLockTablesSql($lockMode);
        $where = ($conditionSql ? ' WHERE ' . $conditionSql : '') . ' ';
        $sql = 'SELECT 1 '
             . $lock
             . $where
             . $lockSql;

        list($params, $types) = $this->expandParameters($criteria);

        $this->conn->executeQuery($sql, $params, $types);
    }

    /**
     * Gets the FROM and optionally JOIN conditions to lock the entity managed by this persister.
     *
     * @param integer $lockMode One of the Doctrine\DBAL\LockMode::* constants.
     *
     * @return string
     */
    protected function getLockTablesSql($lockMode)
    {
        $tableName = $this->class->table->getQuotedQualifiedName($this->platform);

        return $this->platform->appendLockHint(
            'FROM ' . $tableName . ' ' . $this->getSQLTableAlias($this->class->getTableName()),
            $lockMode
        );
    }

    /**
     * Gets the Select Where Condition from a Criteria object.
     *
     * @param \Doctrine\Common\Collections\Criteria $criteria
     *
     * @return string
     */
    protected function getSelectConditionCriteriaSQL(Criteria $criteria)
    {
        $expression = $criteria->getWhereExpression();

        if ($expression === null) {
            return '';
        }

        $visitor = new SqlExpressionVisitor($this, $this->class);

        return $visitor->dispatch($expression);
    }

    /**
     * {@inheritdoc}
     */
    public function getSelectConditionStatementSQL(
        $field,
        $value,
        AssociationMetadata $association = null,
        $comparison = null
    )
    {
        $selectedColumns = [];
        $columns         = $this->getSelectConditionStatementColumnSQL($field, $association);

        if (in_array($comparison, [Comparison::IN, Comparison::NIN]) && count($columns) > 1) {
            // @todo try to support multi-column IN expressions. Example: (col1, col2) IN (('val1A', 'val2A'), ...)
            throw ORMException::cantUseInOperatorOnCompositeKeys();
        }

        foreach ($columns as $column) {
            $placeholder = '?';

            if (($property = $this->class->getProperty($field)) !== null) {
                $placeholder = $property->getType()->convertToDatabaseValueSQL($placeholder, $this->platform);
            }

            if (null !== $comparison) {
                // special case null value handling
                if (($comparison === Comparison::EQ || $comparison === Comparison::IS) && null ===$value) {
                    $selectedColumns[] = $column . ' IS NULL';

                    continue;
                }

                if ($comparison === Comparison::NEQ && null === $value) {
                    $selectedColumns[] = $column . ' IS NOT NULL';

                    continue;
                }

                $selectedColumns[] = $column . ' ' . sprintf(self::$comparisonMap[$comparison], $placeholder);

                continue;
            }

            if (is_array($value)) {
                $in = sprintf('%s IN (%s)', $column, $placeholder);

                if (false !== array_search(null, $value, true)) {
                    $selectedColumns[] = sprintf('(%s OR %s IS NULL)', $in, $column);

                    continue;
                }

                $selectedColumns[] = $in;

                continue;
            }

            if (null === $value) {
                $selectedColumns[] = sprintf('%s IS NULL', $column);

                continue;
            }

            $selectedColumns[] = sprintf('%s = %s', $column, $placeholder);
        }

        return implode(' AND ', $selectedColumns);
    }

    /**
     * Builds the left-hand-side of a where condition statement.
     *
     * @param string                   $field
     * @param AssociationMetadata|null $association
     *
     * @return string[]
     *
     * @throws \Doctrine\ORM\ORMException
     */
    private function getSelectConditionStatementColumnSQL($field, AssociationMetadata $association = null)
    {
        if (($property = $this->class->getProperty($field)) !== null) {
            $tableAlias = $this->getSQLTableAlias($property->getTableName());
            $columnName = $this->platform->quoteIdentifier($property->getColumnName());

            return [$tableAlias . '.' . $columnName];
        }

        if (isset($this->class->associationMappings[$field])) {
            $owningAssociation = $this->class->associationMappings[$field];
            $columns = [];

            // Many-To-Many requires join table check for joinColumn
            if ($owningAssociation instanceof ManyToManyAssociationMetadata) {
                if (! $owningAssociation->isOwningSide()) {
                    $owningAssociation = $association;
                }

                $joinTable     = $owningAssociation->getJoinTable();
                $joinTableName = $joinTable->getQuotedQualifiedName($this->platform);
                $joinColumns   = $association->isOwningSide()
                    ? $joinTable->getJoinColumns()
                    : $joinTable->getInverseJoinColumns()
                ;

                foreach ($joinColumns as $joinColumn) {
                    $quotedColumnName = $this->platform->quoteIdentifier($joinColumn->getColumnName());

                    $columns[] = $joinTableName . '.' . $quotedColumnName;
                }

            } else {
                if (! $owningAssociation->isOwningSide()) {
                    throw ORMException::invalidFindByInverseAssociation($this->class->name, $field);
                }

                $class      = $this->class->isInheritedAssociation($field)
                    ? $owningAssociation->getDeclaringClass()
                    : $this->class
                ;
                $tableAlias = $this->getSQLTableAlias($class->getTableName());

                foreach ($owningAssociation->getJoinColumns() as $joinColumn) {
                    $quotedColumnName = $this->platform->quoteIdentifier($joinColumn->getColumnName());

                    $columns[] = $tableAlias . '.' . $quotedColumnName;
                }
            }

            return $columns;
        }

        if ($association !== null && strpos($field, ' ') === false && strpos($field, '(') === false) {
            // very careless developers could potentially open up this normally hidden api for userland attacks,
            // therefore checking for spaces and function calls which are not allowed.

            // found a join column condition, not really a "field"
            return [$field];
        }

        throw ORMException::unrecognizedField($field);
    }

    /**
     * Gets the conditional SQL fragment used in the WHERE clause when selecting
     * entities in this persister.
     *
     * Subclasses are supposed to override this method if they intend to change
     * or alter the criteria by which entities are selected.
     *
     * @param array                    $criteria
     * @param AssociationMetadata|null $association
     *
     * @return string
     */
    protected function getSelectConditionSQL(array $criteria, AssociationMetadata $association = null)
    {
        $conditions = [];

        foreach ($criteria as $field => $value) {
            $conditions[] = $this->getSelectConditionStatementSQL($field, $value, $association);
        }

        return implode(' AND ', $conditions);
    }

    /**
     * {@inheritdoc}
     */
    public function getOneToManyCollection(
        OneToManyAssociationMetadata $association,
        $sourceEntity,
        $offset = null,
        $limit = null
    )
    {
        $this->switchPersisterContext($offset, $limit);

        $stmt = $this->getOneToManyStatement($association, $sourceEntity, $offset, $limit);

        return $this->loadArrayFromStatement($association, $stmt);
    }

    /**
     * {@inheritdoc}
     */
    public function loadOneToManyCollection(
        OneToManyAssociationMetadata $association,
        $sourceEntity,
        PersistentCollection $collection
    )
    {
        $stmt = $this->getOneToManyStatement($association, $sourceEntity);

        return $this->loadCollectionFromStatement($association, $stmt, $collection);
    }

    /**
     * Builds criteria and execute SQL statement to fetch the one to many entities from.
     *
     * @param OneToManyAssociationMetadata $association
     * @param object                       $sourceEntity
     * @param int|null                     $offset
     * @param int|null                     $limit
     *
     * @return \Doctrine\DBAL\Statement
     */
    private function getOneToManyStatement(
        OneToManyAssociationMetadata $association,
        $sourceEntity,
        $offset = null,
        $limit = null
    )
    {
        $this->switchPersisterContext($offset, $limit);

        $criteria    = [];
        $parameters  = [];
        $owningAssoc = $this->class->associationMappings[$association->getMappedBy()];
        $sourceClass = $this->em->getClassMetadata($association->getSourceEntity());
        $class       = $owningAssoc->getDeclaringClass();
        $tableAlias  = $this->getSQLTableAlias($class->getTableName());

        foreach ($owningAssoc->getJoinColumns() as $joinColumn) {
            $quotedColumnName = $this->platform->quoteIdentifier($joinColumn->getColumnName());
            $fieldName        = $sourceClass->fieldNames[$joinColumn->getReferencedColumnName()];
            $value            = $sourceClass->reflFields[$fieldName]->getValue($sourceEntity);

            if (isset($sourceClass->associationMappings[$fieldName])) {
                $targetMapping = $sourceClass->associationMappings[$fieldName];
                $targetClass   = $this->em->getClassMetadata($targetMapping->getTargetEntity());

                $value = $this->em->getUnitOfWork()->getEntityIdentifier($value);
                $value = $value[$targetClass->identifier[0]];
            }

            // @todo guilhermeblanco How could we handle composite primary keys here?
            $criteria[$tableAlias . "." . $quotedColumnName] = $value;
            $parameters[] = [
                'value' => $value,
                'field' => $fieldName,
                'class' => $sourceClass,
            ];
        }

        $sql                  = $this->getSelectSQL($criteria, $association, null, $limit, $offset);
        list($params, $types) = $this->expandToManyParameters($parameters);

        return $this->conn->executeQuery($sql, $params, $types);
    }

    /**
     * {@inheritdoc}
     */
    public function expandParameters($criteria)
    {
        $params = [];
        $types  = [];

        foreach ($criteria as $field => $value) {
            if ($value === null) {
                continue; // skip null values.
            }

            $types  = array_merge($types, $this->getTypes($field, $value, $this->class));
            $params = array_merge($params, $this->getValues($value));
        }

        return [$params, $types];
    }

    /**
     * Expands the parameters from the given criteria and use the correct binding types if found,
     * specialized for OneToMany or ManyToMany associations.
     *
     * @param mixed[][] $criteria an array of arrays containing following:
     *                             - field to which each criterion will be bound
     *                             - value to be bound
     *                             - class to which the field belongs to
     *
     *
     * @return array
     */
    private function expandToManyParameters($criteria)
    {
        $params = [];
        $types  = [];

        foreach ($criteria as $criterion) {
            if ($criterion['value'] === null) {
                continue; // skip null values.
            }

            $types  = array_merge($types, $this->getTypes($criterion['field'], $criterion['value'], $criterion['class']));
            $params = array_merge($params, $this->getValues($criterion['value']));
        }

        return [$params, $types];
    }

    /**
     * Infers field types to be used by parameter type casting.
     *
     * @param string $field
     * @param mixed  $value
     *
     * @return array
     *
     * @throws \Doctrine\ORM\Query\QueryException
     */
    private function getTypes($field, $value, ClassMetadata $class)
    {
        $property    = $class->getProperty($field);
        $hasProperty = $property !== null;
        $types       = [];

        switch (true) {
            case $hasProperty:
                $types = array_merge($types, [$property->getType()]);
                break;

            case (isset($class->associationMappings[$field])):
                $association = $class->associationMappings[$field];
                $class       = $this->em->getClassMetadata($association->getTargetEntity());

                if (! $association->isOwningSide()) {
                    $association = $class->associationMappings[$association->getMappedBy()];
                    $class       = $this->em->getClassMetadata($association->getTargetEntity());
                }

                $joinColumns = $association instanceof ManyToManyAssociationMetadata
                    ? $association->getJoinTable()->getInverseJoinColumns()
                    : $association->getJoinColumns()
                ;

                foreach ($joinColumns as $joinColumn) {
                    if (! $joinColumn->getType()) {
                        $joinColumn->setType(
                            PersisterHelper::getTypeOfColumn($joinColumn->getReferencedColumnName(), $class, $this->em)
                        );
                    }

                    $types[] = $joinColumn->getType();
                }

                break;

            default:
                $types[] = null;
                break;
        }

        if (is_array($value)) {
            return array_map(function ($type) {
                return $type->getBindingType() + Connection::ARRAY_PARAM_OFFSET;
            }, $types);
        }

        return $types;
    }

    /**
     * Retrieves the parameters that identifies a value.
     *
     * @param mixed $value
     *
     * @return array
     */
    private function getValues($value)
    {
        if (is_array($value)) {
            $newValue = [];

            foreach ($value as $itemValue) {
                $newValue = array_merge($newValue, $this->getValues($itemValue));
            }

            return [$newValue];
        }

        if (is_object($value) && $this->em->getMetadataFactory()->hasMetadataFor(ClassUtils::getClass($value))) {
            $class = $this->em->getClassMetadata(get_class($value));

            if ($class->isIdentifierComposite) {
                $newValue = [];

                foreach ($class->getIdentifierValues($value) as $innerValue) {
                    $newValue = array_merge($newValue, $this->getValues($innerValue));
                }

                return $newValue;
            }
        }

        return [$this->getIndividualValue($value)];
    }

    /**
     * Retrieves an individual parameter value.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private function getIndividualValue($value)
    {
        if ( ! is_object($value) || ! $this->em->getMetadataFactory()->hasMetadataFor(ClassUtils::getClass($value))) {
            return $value;
        }

        return $this->em->getUnitOfWork()->getSingleIdentifierValue($value);
    }

    /**
     * {@inheritdoc}
     */
    public function exists($entity, Criteria $extraConditions = null)
    {
        $criteria = $this->class->getIdentifierValues($entity);

        if ( ! $criteria) {
            return false;
        }

        $alias = $this->getSQLTableAlias($this->class->getTableName());

        $sql = 'SELECT 1 '
             . $this->getLockTablesSql(null)
             . ' WHERE ' . $this->getSelectConditionSQL($criteria);

        list($params, $types) = $this->expandParameters($criteria);

        if (null !== $extraConditions) {
            $sql                                 .= ' AND ' . $this->getSelectConditionCriteriaSQL($extraConditions);
            list($criteriaParams, $criteriaTypes) = $this->expandCriteriaParameters($extraConditions);

            $params = array_merge($params, $criteriaParams);
            $types  = array_merge($types, $criteriaTypes);
        }

        if ($filterSql = $this->generateFilterConditionSQL($this->class, $alias)) {
            $sql .= ' AND ' . $filterSql;
        }

        return (bool) $this->conn->fetchColumn($sql, $params, 0, $types);
    }

    /**
     * Generates the appropriate join SQL for the given association.
     *
     * @param AssociationMetadata $association
     *
     * @return string LEFT JOIN if one of the columns is nullable, INNER JOIN otherwise.
     */
    protected function getJoinSQLForAssociation(AssociationMetadata $association)
    {
        if (! $association->isOwningSide()) {
            return 'LEFT JOIN';
        }

        // if one of the join columns is nullable, return left join
        foreach ($association->getJoinColumns() as $joinColumn) {
             if (! $joinColumn->isNullable()) {
                 continue;
             }

            return 'LEFT JOIN';
        }

        return 'INNER JOIN';
    }

    /**
     * {@inheritdoc}
     */
    public function getSQLColumnAlias($columnName)
    {
        return $this->quoteStrategy->getColumnAlias(
            $columnName,
            $this->currentPersisterContext->sqlAliasCounter++,
            $this->platform
        );
    }

    /**
     * Generates the filter SQL for a given entity and table alias.
     *
     * @param ClassMetadata $targetEntity     Metadata of the target entity.
     * @param string        $targetTableAlias The table alias of the joined/selected table.
     *
     * @return string The SQL query part to add to a query.
     */
    protected function generateFilterConditionSQL(ClassMetadata $targetEntity, $targetTableAlias)
    {
        $filterClauses = [];

        foreach ($this->em->getFilters()->getEnabledFilters() as $filter) {
            if ('' !== $filterExpr = $filter->addFilterConstraint($targetEntity, $targetTableAlias)) {
                $filterClauses[] = '(' . $filterExpr . ')';
            }
        }

        $sql = implode(' AND ', $filterClauses);

        return $sql ? "(" . $sql . ")" : ""; // Wrap again to avoid "X or Y and FilterConditionSQL"
    }

    /**
     * Switches persister context according to current query offset/limits
     *
     * This is due to the fact that to-many associations cannot be fetch-joined when a limit is involved
     *
     * @param null|int $offset
     * @param null|int $limit
     */
    protected function switchPersisterContext($offset, $limit)
    {
        if (null === $offset && null === $limit) {
            $this->currentPersisterContext = $this->noLimitsContext;

            return;
        }

        $this->currentPersisterContext = $this->limitsHandlingContext;
    }
}
