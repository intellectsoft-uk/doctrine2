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

namespace Doctrine\ORM\Cache;

use Doctrine\Common\Util\ClassUtils;

use Doctrine\ORM\Mapping\FetchMode;
use Doctrine\ORM\Mapping\OneToOneAssociationMetadata;
use Doctrine\ORM\Mapping\ToOneAssociationMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Utility\IdentifierFlattener;

/**
 * Default hydrator cache for entities
 *
 * @since   2.5
 * @author  Fabio B. Silva <fabio.bat.silva@gmail.com>
 */
class DefaultEntityHydrator implements EntityHydrator
{
    /**
     * @var \Doctrine\ORM\EntityManager
     */
    private $em;

    /**
     * @var \Doctrine\ORM\UnitOfWork
     */
    private $uow;

    /**
     * The IdentifierFlattener used for manipulating identifiers
     *
     * @var \Doctrine\ORM\Utility\IdentifierFlattener
     */
    private $identifierFlattener;

    /**
     * @var array
     */
    private static $hints = [Query::HINT_CACHE_ENABLED => true];

    /**
     * @param \Doctrine\ORM\EntityManagerInterface $em The entity manager.
     */
    public function __construct(EntityManagerInterface $em)
    {
        $this->em   = $em;
        $this->uow  = $em->getUnitOfWork();
        $this->identifierFlattener = new IdentifierFlattener($em->getUnitOfWork(), $em->getMetadataFactory());
    }

    /**
     * {@inheritdoc}
     */
    public function buildCacheEntry(ClassMetadata $metadata, EntityCacheKey $key, $entity)
    {
        $data = $this->uow->getOriginalEntityData($entity);
        $data = array_merge($data, $metadata->getIdentifierValues($entity)); // why update has no identifier values ?

        foreach ($metadata->associationMappings as $name => $association) {
            if ( ! isset($data[$name])) {
                continue;
            }

            if (! $association instanceof ToOneAssociationMetadata) {
                unset($data[$name]);

                continue;
            }

            $targetEntity        = $association->getTargetEntity();
            $targetClassMetadata = $this->em->getClassMetadata($targetEntity);

            if (! $association->getCache()) {
                $owningAssociation   = ! $association->isOwningSide()
                    ? $targetClassMetadata->associationMappings[$association->getMappedBy()]
                    : $association;
                $associationIds      = $this->identifierFlattener->flattenIdentifier(
                    $targetClassMetadata,
                    $targetClassMetadata->getIdentifierValues($data[$name])
                );

                unset($data[$name]);

                foreach ($associationIds as $fieldName => $fieldValue) {
                    // $fieldName = "name"
                    // $fieldColumnName = "custom_name"
                    if (($property = $targetClassMetadata->getProperty($fieldName)) !== null) {
                        foreach ($owningAssociation->getJoinColumns() as $joinColumn) {
                            // $joinColumnName = "custom_name"
                            // $joinColumnReferencedColumnName = "other_side_of_assoc_column_name"
                            if ($joinColumn->getReferencedColumnName() !== $property->getColumnName()) {
                                continue;
                            }

                            $data[$joinColumn->getColumnName()] = $fieldValue;

                            break;
                        }

                        continue;
                    }

                    $targetAssociation = $targetClassMetadata->associationMappings[$fieldName];

                    foreach ($association->getJoinColumns() as $assocJoinColumn) {
                        foreach ($targetAssociation->getJoinColumns() as $targetAssocJoinColumn) {
                            if ($assocJoinColumn->getReferencedColumnName() !== $targetAssocJoinColumn->getColumnName()) {
                                continue;
                            }

                            $data[$assocJoinColumn->getColumnName()] = $fieldValue;
                        }
                    }
                }

                continue;
            }

            if (! $association->isPrimaryKey()) {
                $targetClass = ClassUtils::getClass($data[$name]);
                $targetId    = $this->uow->getEntityIdentifier($data[$name]);
                $data[$name] = new AssociationCacheEntry($targetClass, $targetId);

                continue;
            }

            // handle association identifier
            $targetId = is_object($data[$name]) && $this->uow->isInIdentityMap($data[$name])
                ? $this->uow->getEntityIdentifier($data[$name])
                : $data[$name];

            // @todo guilhermeblanco From my initial research, we could move the Identifier Flattener to EM and consume
            // @todo guilhermeblanco it here, unifying the hash generation that happens everywhere in the codebase.
            // @TODO - fix it ! handle UnitOfWork#createEntity hash generation
            if ( ! is_array($targetId)) {
                $joinColumns = $association->getJoinColumns();
                $columnName  = $joinColumns[0]->getAliasedName() ?? $joinColumns[0]->getColumnName();

                $data[$columnName] = $targetId;

                $targetId = [$targetClassMetadata->identifier[0] => $targetId];
            }

            $data[$name] = new AssociationCacheEntry($targetEntity, $targetId);
        }

        return new EntityCacheEntry($metadata->name, $data);
    }

    /**
     * {@inheritdoc}
     */
    public function loadCacheEntry(
        ClassMetadata $metadata,
        EntityCacheKey $key,
        EntityCacheEntry $entry,
        $entity = null
    )
    {
        $data  = $entry->data;
        $hints = self::$hints;

        if ($entity !== null) {
            $hints[Query::HINT_REFRESH]         = true;
            $hints[Query::HINT_REFRESH_ENTITY]  = $entity;
        }

        foreach ($metadata->associationMappings as $name => $association) {
            if (! $association->getCache() || ! isset($data[$name])) {
                continue;
            }

            $assocClass     = $data[$name]->class;
            $assocId        = $data[$name]->identifier;
            $isEagerLoad    = (
                $association->getFetchMode() === FetchMode::EAGER ||
                ($association instanceof OneToOneAssociationMetadata && ! $association->isOwningSide())
            );

            if ( ! $isEagerLoad) {
                $data[$name] = $this->em->getReference($assocClass, $assocId);

                continue;
            }

            $targetEntity   = $association->getTargetEntity();
            $assocMetadata  = $this->em->getClassMetadata($targetEntity);
            $assocKey       = new EntityCacheKey($assocMetadata->rootEntityName, $assocId);
            $assocPersister = $this->uow->getEntityPersister($targetEntity);
            $assocRegion    = $assocPersister->getCacheRegion();
            $assocEntry     = $assocRegion->get($assocKey);

            if ($assocEntry === null) {
                return null;
            }

            $data[$name] = $this->uow->createEntity(
                $assocEntry->class,
                $assocEntry->resolveAssociationEntries($this->em),
                $hints
            );
        }

        if ($entity !== null) {
            $this->uow->registerManaged($entity, $key->identifier, $data);
        }

        $result = $this->uow->createEntity($entry->class, $data, $hints);

        $this->uow->hydrationComplete();

        return $result;
    }
}
