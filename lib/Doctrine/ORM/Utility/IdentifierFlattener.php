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

namespace Doctrine\ORM\Utility;

use Doctrine\ORM\UnitOfWork;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Common\Persistence\Mapping\ClassMetadataFactory;

/**
 * The IdentifierFlattener utility now houses some of the identifier manipulation logic from unit of work, so that it
 * can be re-used elsewhere.
 *
 * @since       2.5
 * @author      Rob Caiger <rob@clocal.co.uk>
 */
final class IdentifierFlattener
{
    /**
     * The UnitOfWork used to coordinate object-level transactions.
     *
     * @var UnitOfWork
     */
    private $unitOfWork;

    /**
     * The metadata factory, used to retrieve the ORM metadata of entity classes.
     *
     * @var ClassMetadataFactory
     */
    private $metadataFactory;

    /**
     * Initializes a new IdentifierFlattener instance, bound to the given EntityManager.
     *
     * @param UnitOfWork           $unitOfWork
     * @param ClassMetadataFactory $metadataFactory
     */
    public function __construct(UnitOfWork $unitOfWork, ClassMetadataFactory $metadataFactory)
    {
        $this->unitOfWork = $unitOfWork;
        $this->metadataFactory = $metadataFactory;
    }

    /**
     * convert foreign identifiers into scalar foreign key values to avoid object to string conversion failures.
     *
     * @param ClassMetadata $class
     * @param array         $id
     *
     * @return array
     */
    public function flattenIdentifier(ClassMetadata $class, array $id)
    {
        $flatId = [];

        foreach ($class->identifier as $field) {
            if (!isset($class->associationMappings[$field])) {
                $flatId[$field] = $id[$field];

                continue;
            }

            $association = $class->associationMappings[$field];

            if (isset($id[$field]) && is_object($id[$field])) {
                /* @var $targetClassMetadata ClassMetadata */
                $targetClassMetadata = $this->metadataFactory->getMetadataFor($association->getTargetEntity());
                $identifiers         = $this->unitOfWork->isInIdentityMap($id[$field])
                    ? $this->unitOfWork->getEntityIdentifier($id[$field])
                    : $targetClassMetadata->getIdentifierValues($id[$field]);
                $associatedId        = $this->flattenIdentifier($targetClassMetadata, $identifiers);

                $flatId[$field] = implode(' ', $associatedId);

                continue;
            }

            $associatedId = [];

            foreach ($association->getJoinColumns() as $joinColumn) {
                $associatedId[] = $id[$joinColumn->getColumnName()];
            }

            $flatId[$field] = implode(' ', $associatedId);
        }

        return $flatId;
    }
}
