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

namespace Doctrine\ORM\Query;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Utility\PersisterHelper;

/**
 * A ResultSetMappingBuilder uses the EntityManager to automatically populate entity fields.
 *
 * @author Michael Ridgway <mcridgway@gmail.com>
 * @since 2.1
 */
class ResultSetMappingBuilder extends ResultSetMapping
{
    /**
     * Picking this rename mode will register entity columns as is,
     * as they are in the database. This can cause clashes when multiple
     * entities are fetched that have columns with the same name.
     *
     * @var int
     */
    const COLUMN_RENAMING_NONE = 1;

    /**
     * Picking custom renaming allows the user to define the renaming
     * of specific columns with a rename array that contains column names as
     * keys and result alias as values.
     *
     * @var int
     */
    const COLUMN_RENAMING_CUSTOM = 2;

    /**
     * Incremental renaming uses a result set mapping internal counter to add a
     * number to each column result, leading to uniqueness. This only works if
     * you use {@see generateSelectClause()} to generate the SELECT clause for
     * you.
     *
     * @var int
     */
    const COLUMN_RENAMING_INCREMENT = 3;

    /**
     * @var int
     */
    private $sqlCounter = 0;

    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * Default column renaming mode.
     *
     * @var int
     */
    private $defaultRenameMode;

    /**
     * @param EntityManagerInterface $em
     * @param integer                $defaultRenameMode
     */
    public function __construct(EntityManagerInterface $em, $defaultRenameMode = self::COLUMN_RENAMING_NONE)
    {
        $this->em                = $em;
        $this->defaultRenameMode = $defaultRenameMode;
    }

    /**
     * Adds a root entity and all of its fields to the result set.
     *
     * @param string   $class          The class name of the root entity.
     * @param string   $alias          The unique alias to use for the root entity.
     * @param array    $renamedColumns Columns that have been renamed (tableColumnName => queryColumnName).
     * @param int|null $renameMode     One of the COLUMN_RENAMING_* constants or array for BC reasons (CUSTOM).
     *
     * @return void
     */
    public function addRootEntityFromClassMetadata($class, $alias, $renamedColumns = [], $renameMode = null)
    {
        $renameMode     = $renameMode ?: $this->defaultRenameMode;
        $columnAliasMap = $this->getColumnAliasMap($class, $renameMode, $renamedColumns);

        $this->addEntityResult($class, $alias);
        $this->addAllClassFields($class, $alias, $columnAliasMap);
    }

    /**
     * Adds a joined entity and all of its fields to the result set.
     *
     * @param string   $class          The class name of the joined entity.
     * @param string   $alias          The unique alias to use for the joined entity.
     * @param string   $parentAlias    The alias of the entity result that is the parent of this joined result.
     * @param string   $relation       The association field that connects the parent entity result
     *                                 with the joined entity result.
     * @param array    $renamedColumns Columns that have been renamed (tableColumnName => queryColumnName).
     * @param int|null $renameMode     One of the COLUMN_RENAMING_* constants or array for BC reasons (CUSTOM).
     *
     * @return void
     */
    public function addJoinedEntityFromClassMetadata($class, $alias, $parentAlias, $relation, $renamedColumns = [], $renameMode = null)
    {
        $renameMode     = $renameMode ?: $this->defaultRenameMode;
        $columnAliasMap = $this->getColumnAliasMap($class, $renameMode, $renamedColumns);

        $this->addJoinedEntityResult($class, $alias, $parentAlias, $relation);
        $this->addAllClassFields($class, $alias, $columnAliasMap);
    }

    /**
     * Adds all fields of the given class to the result set mapping (columns and meta fields).
     *
     * @param string $class
     * @param string $alias
     * @param array  $columnAliasMap
     *
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    protected function addAllClassFields($class, $alias, $columnAliasMap = [])
    {
        /** @var ClassMetadata $classMetadata */
        $classMetadata = $this->em->getClassMetadata($class);
        $platform      = $this->em->getConnection()->getDatabasePlatform();

        if ( ! $this->isInheritanceSupported($classMetadata)) {
            throw new \InvalidArgumentException(
                'ResultSetMapping builder does not currently support your inheritance scheme.'
            );
        }

        foreach ($classMetadata->fieldNames as $columnName => $propertyName) {
            $columnAlias = $platform->getSQLResultCasing($columnAliasMap[$columnName]);

            if ($classMetadata->hasField($propertyName)) {
                if (isset($this->fieldMappings[$columnAlias])) {
                    throw new \InvalidArgumentException(
                        "The column '$columnName' conflicts with another column in the mapper."
                    );
                }

                $this->addFieldResult($alias, $columnAlias, $propertyName);

                continue;
            }

            if (isset($this->metaMappings[$columnAlias])) {
                throw new \InvalidArgumentException("The column '$columnAlias' conflicts with another column in the mapper.");
            }

            $association = $classMetadata->associationMappings[$propertyName];
            $columnType  = PersisterHelper::getTypeOfColumn($columnName, $classMetadata, $this->em);

            $this->addMetaResult($alias, $columnAlias, $columnName, $association->isPrimaryKey(), $columnType);
        }
    }

    /**
     * Checks if inheritance if supported.
     *
     * @param ClassMetadata $metadata
     *
     * @return boolean
     */
    private function isInheritanceSupported(ClassMetadata $metadata)
    {
        if ($metadata->inheritanceType === InheritanceType::SINGLE_TABLE
            && in_array($metadata->name, $metadata->discriminatorMap, true)) {
            return true;
        }

        return ! in_array($metadata->inheritanceType, [InheritanceType::SINGLE_TABLE, InheritanceType::JOINED]);
    }

    /**
     * Gets column alias for a given column.
     *
     * @param string $columnName
     * @param int    $mode
     * @param array  $customRenameColumns
     *
     * @return string
     */
    private function getColumnAlias($columnName, $mode, array $customRenameColumns)
    {
        switch ($mode) {
            case self::COLUMN_RENAMING_INCREMENT:
                return $columnName . $this->sqlCounter++;

            case self::COLUMN_RENAMING_CUSTOM:
                return isset($customRenameColumns[$columnName])
                    ? $customRenameColumns[$columnName] : $columnName;

            case self::COLUMN_RENAMING_NONE:
                return $columnName;

        }
    }

    /**
     * Retrieves a class columns and join columns aliases that are used in the SELECT clause.
     *
     * This depends on the renaming mode selected by the user.
     *
     * @param string $className
     * @param int    $mode
     * @param array  $customRenameColumns
     *
     * @return array
     */
    private function getColumnAliasMap($className, $mode, array $customRenameColumns)
    {
        if ($customRenameColumns) { // for BC with 2.2-2.3 API
            $mode = self::COLUMN_RENAMING_CUSTOM;
        }

        $columnAlias   = [];
        $classMetadata = $this->em->getClassMetadata($className);

        foreach ($classMetadata->fieldNames as $columnName => $propertyName) {
            $columnAlias[$columnName] = $this->getColumnAlias($columnName, $mode, $customRenameColumns);
        }

        return $columnAlias;
    }

    /**
     * Adds the mappings of the results of native SQL queries to the result set.
     *
     * @param ClassMetadata $class
     * @param array         $queryMapping
     *
     * @return ResultSetMappingBuilder
     */
    public function addNamedNativeQueryMapping(ClassMetadata $class, array $queryMapping)
    {
        if (isset($queryMapping['resultClass'])) {
            return $this->addNamedNativeQueryResultClassMapping($class, $queryMapping['resultClass']);
        }

        return $this->addNamedNativeQueryResultSetMapping($class, $queryMapping['resultSetMapping']);
    }

    /**
     * Adds the class mapping of the results of native SQL queries to the result set.
     *
     * @param ClassMetadata $class
     * @param string        $resultClassName
     *
     * @return  ResultSetMappingBuilder
     */
    public function addNamedNativeQueryResultClassMapping(ClassMetadata $class, $resultClassName)
    {
        $classMetadata  = $this->em->getClassMetadata($resultClassName);
        $shortName      = $classMetadata->reflClass->getShortName();
        $alias          = strtolower($shortName[0]).'0';

        $this->addEntityResult($class->name, $alias);

        if ($classMetadata->discriminatorColumn) {
            $discrColumn     = $classMetadata->discriminatorColumn;
            $discrColumnName = $discrColumn->getColumnName();
            $discrColumnType = $discrColumn->getType();

            $this->setDiscriminatorColumn($alias, $discrColumnName);
            $this->addMetaResult($alias, $discrColumnName, $discrColumnName, false, $discrColumnType);
        }

        foreach ($classMetadata->fieldNames as $columnName => $propertyName) {
            if ($classMetadata->hasField($propertyName)) {
                $this->addFieldResult($alias, $columnName, $propertyName);

                continue;
            }

            $association = $classMetadata->associationMappings[$propertyName];
            $columnType  = PersisterHelper::getTypeOfColumn($columnName, $classMetadata, $this->em);

            $this->addMetaResult($alias, $columnName, $columnName, $association->isPrimaryKey(), $columnType);
        }

        return $this;
    }

    /**
     * Adds the result set mapping of the results of native SQL queries to the result set.
     *
     * @param ClassMetadata $class
     * @param string        $resultSetMappingName
     *
     * @return ResultSetMappingBuilder
     */
    public function addNamedNativeQueryResultSetMapping(ClassMetadata $class, $resultSetMappingName)
    {
        $counter        = 0;
        $resultMapping  = $class->getSqlResultSetMapping($resultSetMappingName);
        $rootShortName  = $class->reflClass->getShortName();
        $rootAlias      = strtolower($rootShortName[0]) . $counter;

        if (isset($resultMapping['entities'])) {
            foreach ($resultMapping['entities'] as $key => $entityMapping) {
                $classMetadata  = $this->em->getClassMetadata($entityMapping['entityClass']);

                if ($class->reflClass->name == $classMetadata->reflClass->name) {
                    $this->addEntityResult($classMetadata->name, $rootAlias);
                    $this->addNamedNativeQueryEntityResultMapping($classMetadata, $entityMapping, $rootAlias);
                } else {
                    $shortName    = $classMetadata->reflClass->getShortName();
                    $joinAlias    = strtolower($shortName[0]) . ++ $counter;
                    $associations = $class->getAssociationsByTargetClass($classMetadata->name);

                    $this->addNamedNativeQueryEntityResultMapping($classMetadata, $entityMapping, $joinAlias);

                    foreach ($associations as $relation => $association) {
                        $this->addJoinedEntityResult($association->getTargetEntity(), $joinAlias, $rootAlias, $relation);
                    }
                }

            }
        }

        if (isset($resultMapping['columns'])) {
            foreach ($resultMapping['columns'] as $entityMapping) {
                $type = isset($class->fieldNames[$entityMapping['name']])
                    ? $class->fieldNames[$entityMapping['name']]['type']
                    : Type::getType('string');

                $this->addScalarResult($entityMapping['name'], $entityMapping['name'], $type);
            }
        }

        return $this;
    }

    /**
     * Adds the entity result mapping of the results of native SQL queries to the result set.
     *
     * @param ClassMetadata $classMetadata
     * @param array         $entityMapping
     * @param string        $alias
     *
     * @return ResultSetMappingBuilder
     *
     * @throws MappingException
     * @throws \InvalidArgumentException
     */
    public function addNamedNativeQueryEntityResultMapping(ClassMetadata $classMetadata, array $entityMapping, $alias)
    {
        if (isset($entityMapping['discriminatorColumn']) && $entityMapping['discriminatorColumn']) {
            $discrColumn     = $classMetadata->discriminatorColumn;
            $discrColumnName = $entityMapping['discriminatorColumn'];
            $discrColumnType = $discrColumn->getType();

            $this->setDiscriminatorColumn($alias, $discrColumnName);
            $this->addMetaResult($alias, $discrColumnName, $discrColumnName, false, $discrColumnType);
        }

        if (isset($entityMapping['fields']) && !empty($entityMapping['fields'])) {
            foreach ($entityMapping['fields'] as $field) {
                $fieldName = $field['name'];
                $relation  = null;

                if (strpos($fieldName, '.') !== false) {
                    list($relation, $fieldName) = explode('.', $fieldName);
                }

                if (isset($classMetadata->associationMappings[$relation])) {
                    if ($relation) {
                        $association = $classMetadata->associationMappings[$relation];
                        $joinAlias   = $alias.$relation;
                        $parentAlias = $alias;

                        $this->addJoinedEntityResult($association->getTargetEntity(), $joinAlias, $parentAlias, $relation);
                        $this->addFieldResult($joinAlias, $field['column'], $fieldName);
                    } else {
                        $this->addFieldResult($alias, $field['column'], $fieldName, $classMetadata->name);
                    }
                } else {
                    if ( ! $classMetadata->getProperty($fieldName)) {
                        throw new \InvalidArgumentException("Entity '".$classMetadata->name."' has no field '".$fieldName."'. ");
                    }

                    $this->addFieldResult($alias, $field['column'], $fieldName, $classMetadata->name);
                }
            }
        } else {
            foreach ($classMetadata->fieldNames as $columnName => $propertyName) {
                if (! $classMetadata->hasField($propertyName)) {
                    continue;
                }

                $this->addFieldResult($alias, $columnName, $propertyName);
            }
        }

        return $this;
    }

    /**
     * Generates the Select clause from this ResultSetMappingBuilder.
     *
     * Works only for all the entity results. The select parts for scalar
     * expressions have to be written manually.
     *
     * @param array $tableAliases
     *
     * @return string
     */
    public function generateSelectClause($tableAliases = [])
    {
        $sql = "";

        foreach ($this->columnOwnerMap as $columnName => $dqlAlias) {
            $tableAlias = isset($tableAliases[$dqlAlias])
                ? $tableAliases[$dqlAlias] : $dqlAlias;

            if ($sql) {
                $sql .= ", ";
            }

            $sql .= $tableAlias . ".";

            if (isset($this->fieldMappings[$columnName])) {
                $class = $this->em->getClassMetadata($this->declaringClasses[$columnName]);
                $field = $this->fieldMappings[$columnName];
                $sql  .= $class->getProperty($field)->getColumnName();
            } else if (isset($this->metaMappings[$columnName])) {
                $sql .= $this->metaMappings[$columnName];
            } else if (isset($this->discriminatorColumns[$dqlAlias])) {
                $sql .= $this->discriminatorColumns[$dqlAlias];
            }

            $sql .= " AS " . $columnName;
        }

        return $sql;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->generateSelectClause([]);
    }
}
