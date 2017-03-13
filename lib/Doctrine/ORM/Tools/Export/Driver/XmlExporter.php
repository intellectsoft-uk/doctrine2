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

namespace Doctrine\ORM\Tools\Export\Driver;

use Doctrine\ORM\Mapping\AssociationMetadata;
use Doctrine\ORM\Mapping\ChangeTrackingPolicy;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\GeneratorType;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\JoinColumnMetadata;
use Doctrine\ORM\Mapping\ManyToManyAssociationMetadata;
use Doctrine\ORM\Mapping\ManyToOneAssociationMetadata;
use Doctrine\ORM\Mapping\OneToManyAssociationMetadata;
use Doctrine\ORM\Mapping\OneToOneAssociationMetadata;
use Doctrine\ORM\Mapping\ToManyAssociationMetadata;
use Doctrine\ORM\Mapping\ToOneAssociationMetadata;

/**
 * ClassMetadata exporter for Doctrine XML mapping files.
 *
 * @link    www.doctrine-project.org
 * @since   2.0
 * @author  Jonathan Wage <jonwage@gmail.com>
 */
class XmlExporter extends AbstractExporter
{
    /**
     * @var string
     */
    protected $extension = '.dcm.xml';

    /**
     * {@inheritdoc}
     */
    public function exportClassMetadata(ClassMetadata $metadata)
    {
        $xml = new \SimpleXmlElement("<?xml version=\"1.0\" encoding=\"utf-8\"?><doctrine-mapping ".
            "xmlns=\"http://doctrine-project.org/schemas/orm/doctrine-mapping\" " .
            "xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" ".
            "xsi:schemaLocation=\"http://doctrine-project.org/schemas/orm/doctrine-mapping http://doctrine-project.org/schemas/orm/doctrine-mapping.xsd\" />");

        if ($metadata->isMappedSuperclass) {
            $root = $xml->addChild('mapped-superclass');
        } else {
            $root = $xml->addChild('entity');
        }

        if ($metadata->customRepositoryClassName) {
            $root->addAttribute('repository-class', $metadata->customRepositoryClassName);
        }

        $root->addAttribute('name', $metadata->name);

        if ($metadata->table->getName()) {
            $root->addAttribute('table', $metadata->table->getName());
        }

        if ($metadata->table->getSchema()) {
            $root->addAttribute('schema', $metadata->table->getSchema());
        }

        if ($metadata->inheritanceType && $metadata->inheritanceType !== InheritanceType::NONE) {
            $root->addAttribute('inheritance-type', $metadata->inheritanceType);
        }

        if ($metadata->table->getOptions()) {
            $optionsXml = $root->addChild('options');

            $this->exportTableOptions($optionsXml, $metadata->table->getOptions());
        }

        if ($metadata->discriminatorColumn) {
            $discrColumn            = $metadata->discriminatorColumn;
            $discriminatorColumnXml = $root->addChild('discriminator-column');

            $discriminatorColumnXml->addAttribute('name', $discrColumn->getColumnName());
            $discriminatorColumnXml->addAttribute('type', $discrColumn->getTypeName());

            if (is_int($discrColumn->getLength())) {
                $discriminatorColumnXml->addAttribute('length', $discrColumn->getLength());
            }

            if (is_int($discrColumn->getScale())) {
                $discriminatorColumnXml->addAttribute('scale', $discrColumn->getScale());
            }

            if (is_int($discrColumn->getPrecision())) {
                $discriminatorColumnXml->addAttribute('precision', $discrColumn->getPrecision());
            }
        }

        if ($metadata->discriminatorMap) {
            $discriminatorMapXml = $root->addChild('discriminator-map');

            foreach ($metadata->discriminatorMap as $value => $className) {
                $discriminatorMappingXml = $discriminatorMapXml->addChild('discriminator-mapping');
                $discriminatorMappingXml->addAttribute('value', $value);
                $discriminatorMappingXml->addAttribute('class', $className);
            }
        }

        if ($metadata->changeTrackingPolicy !== ChangeTrackingPolicy::DEFERRED_IMPLICIT) {
            $root->addChild('change-tracking-policy', $metadata->changeTrackingPolicy);
        }

        if ($metadata->table->getIndexes()) {
            $indexesXml = $root->addChild('indexes');

            foreach ($metadata->table->getIndexes() as $name => $index) {
                $indexXml = $indexesXml->addChild('index');

                $indexXml->addAttribute('name', $name);
                $indexXml->addAttribute('columns', implode(',', $index['columns']));

                if ($index['unique']) {
                    $indexXml->addAttribute('unique', 'true');
                }

                if ($index['flags']) {
                    $indexXml->addAttribute('flags', implode(',', $index['flags']));
                }

                if ($index['options']) {
                    $optionsXml = $indexXml->addChild('options');

                    foreach ($index['options'] as $key => $value) {
                        $optionXml = $optionsXml->addChild('option', $value);

                        $optionXml->addAttribute('name', $key);
                    }
                }
            }
        }

        if ($metadata->table->getUniqueConstraints()) {
            $uniqueConstraintsXml = $root->addChild('unique-constraints');

            foreach ($metadata->table->getUniqueConstraints() as $name => $constraint) {
                $uniqueConstraintXml = $uniqueConstraintsXml->addChild('unique-constraint');

                $uniqueConstraintXml->addAttribute('name', $name);
                $uniqueConstraintXml->addAttribute('columns', implode(',', $constraint['columns']));

                if ($constraint['flags']) {
                    $uniqueConstraintXml->addAttribute('flags', implode(',', $constraint['flags']));
                }

                if ($constraint['options']) {
                    $optionsXml = $uniqueConstraintXml->addChild('options');

                    foreach ($constraint['options'] as $key => $value) {
                        $optionXml = $optionsXml->addChild('option', $value);

                        $optionXml->addAttribute('name', $key);
                    }
                }
            }
        }

        $properties = $metadata->getProperties();
        $id         = [];

        foreach ($properties as $name => $property) {
            if ($property->isPrimaryKey()) {
                $id[$name] = $property;

                unset($properties[$name]);
            }
        }

        foreach ($metadata->associationMappings as $name => $association) {
            if ($association->isPrimaryKey()) {
                $id[$name] = $association;
            }
        }

        if ($id) {
            foreach ($id as $property) {
                $idXml = $root->addChild('id');

                $idXml->addAttribute('name', $property->getName());

                if ($property instanceof AssociationMetadata) {
                    $idXml->addAttribute('association-key', 'true');

                    continue;
                }

                $idXml->addAttribute('type', $property->getTypeName());
                $idXml->addAttribute('column', $property->getColumnName());

                if (is_int($property->getLength())) {
                    $idXml->addAttribute('length', $property->getLength());
                }

                if ($metadata->generatorType) {
                    $generatorXml = $idXml->addChild('generator');

                    $generatorXml->addAttribute('strategy', $metadata->generatorType);

                    $this->exportSequenceInformation($idXml, $metadata);
                }
            }
        }

        if ($properties) {
            foreach ($properties as $property) {
                $fieldXml = $root->addChild('field');

                $fieldXml->addAttribute('name', $property->getName());
                $fieldXml->addAttribute('type', $property->getTypeName());
                $fieldXml->addAttribute('column', $property->getColumnName());

                if ($property->isNullable()) {
                    $fieldXml->addAttribute('nullable', 'true');
                }

                if ($property->isUnique()) {
                    $fieldXml->addAttribute('unique', 'true');
                }

                if (is_int($property->getLength())) {
                    $fieldXml->addAttribute('length', $property->getLength());
                }

                if (is_int($property->getPrecision())) {
                    $fieldXml->addAttribute('precision', $property->getPrecision());
                }

                if (is_int($property->getScale())) {
                    $fieldXml->addAttribute('scale', $property->getScale());
                }

                if ($metadata->isVersioned() && $metadata->versionProperty->getName() === $property->getName()) {
                    $fieldXml->addAttribute('version', 'true');
                }

                if ($property->getColumnDefinition()) {
                    $fieldXml->addAttribute('column-definition', $property->getColumnDefinition());
                }

                if ($property->getOptions()) {
                    $optionsXml = $fieldXml->addChild('options');

                    foreach ($property->getOptions() as $key => $value) {
                        $optionXml = $optionsXml->addChild('option', $value);

                        $optionXml->addAttribute('name', $key);
                    }
                }
            }
        }

        $orderMap = [
            OneToOneAssociationMetadata::class,
            OneToManyAssociationMetadata::class,
            ManyToOneAssociationMetadata::class,
            ManyToManyAssociationMetadata::class,
        ];

        uasort($metadata->associationMappings, function($m1, $m2) use (&$orderMap){
            $a1 = array_search(get_class($m1), $orderMap);
            $a2 = array_search(get_class($m2), $orderMap);

            return strcmp($a1, $a2);
        });

        foreach ($metadata->associationMappings as $association) {
            if ($association instanceof OneToOneAssociationMetadata) {
                $associationMappingXml = $root->addChild('one-to-one');
            } elseif ($association instanceof OneToManyAssociationMetadata) {
                $associationMappingXml = $root->addChild('one-to-many');
            } elseif ($association instanceof ManyToOneAssociationMetadata) {
                $associationMappingXml = $root->addChild('many-to-one');
            } elseif ($association instanceof ManyToManyAssociationMetadata) {
                $associationMappingXml = $root->addChild('many-to-many');
            }

            $associationMappingXml->addAttribute('field', $association->getName());
            $associationMappingXml->addAttribute('target-entity', $association->getTargetEntity());
            $associationMappingXml->addAttribute('fetch', $association->getFetchMode());

            $this->exportCascade($associationMappingXml, $association->getCascade());

            if ($association->getMappedBy()) {
                $associationMappingXml->addAttribute('mapped-by', $association->getMappedBy());
            }

            if ($association->getInversedBy()) {
                $associationMappingXml->addAttribute('inversed-by', $association->getInversedBy());
            }

            if ($association->isOrphanRemoval()) {
                $associationMappingXml->addAttribute('orphan-removal', 'true');
            }

            if ($association instanceof ToManyAssociationMetadata) {
                if ($association instanceof ManyToManyAssociationMetadata && $association->getJoinTable()) {
                    $joinTableXml = $associationMappingXml->addChild('join-table');
                    $joinTable    = $association->getJoinTable();

                    $joinTableXml->addAttribute('name', $joinTable->getName());

                    $this->exportJoinColumns($joinTableXml, $joinTable->getJoinColumns(), 'join-columns');
                    $this->exportJoinColumns($joinTableXml, $joinTable->getInverseJoinColumns(), 'inverse-join-columns');
                }

                if ($association->getIndexedBy()) {
                    $associationMappingXml->addAttribute('index-by', $association->getIndexedBy());
                }

                if ($association->getOrderBy()) {
                    $orderByXml = $associationMappingXml->addChild('order-by');

                    foreach ($association->getOrderBy() as $name => $direction) {
                        $orderByFieldXml = $orderByXml->addChild('order-by-field');

                        $orderByFieldXml->addAttribute('name', $name);
                        $orderByFieldXml->addAttribute('direction', $direction);
                    }
                }
            }

            if ($association instanceof ToOneAssociationMetadata) {
                if ($association->getJoinColumns()) {
                    $this->exportJoinColumns($associationMappingXml, $association->getJoinColumns());
                }
            }
        }

        if (isset($metadata->lifecycleCallbacks) && count($metadata->lifecycleCallbacks)>0) {
            $lifecycleCallbacksXml = $root->addChild('lifecycle-callbacks');

            foreach ($metadata->lifecycleCallbacks as $name => $methods) {
                foreach ($methods as $method) {
                    $lifecycleCallbackXml = $lifecycleCallbacksXml->addChild('lifecycle-callback');

                    $lifecycleCallbackXml->addAttribute('type', $name);
                    $lifecycleCallbackXml->addAttribute('method', $method);
                }
            }
        }

        return $this->asXml($xml);
    }

    /**
     * @param \SimpleXMLElement $associationXml
     * @param array             $joinColumns
     * @param string            $joinColumnsName
     */
    private function exportJoinColumns(
        \SimpleXMLElement $associationXml,
        array $joinColumns,
        $joinColumnsName = 'join-columns'
    )
    {
        $joinColumnsXml = $associationXml->addChild($joinColumnsName);

        foreach ($joinColumns as $joinColumn) {
            /** @var JoinColumnMetadata $joinColumn */
            $joinColumnXml = $joinColumnsXml->addChild('join-column');

            $joinColumnXml->addAttribute('name', $joinColumn->getColumnName());
            $joinColumnXml->addAttribute('referenced-column-name', $joinColumn->getReferencedColumnName());

            if (! empty($joinColumn->getAliasedName())) {
                $joinColumnXml->addAttribute('field-name', $joinColumn->getAliasedName());
            }

            if (! empty($joinColumn->getOnDelete())) {
                $joinColumnXml->addAttribute('on-delete', $joinColumn->getOnDelete());
            }

            if (! empty($joinColumn->getColumnDefinition())) {
                $joinColumnXml->addAttribute('column-definition', $joinColumn->getColumnDefinition());
            }

            if ($joinColumn->isNullable()) {
                $joinColumnXml->addAttribute('nullable', $joinColumn->isNullable());
            }

            if ($joinColumn->isUnique()) {
                $joinColumnXml->addAttribute('unique', $joinColumn->isUnique());
            }

            if ($joinColumn->getOptions()) {
                $optionsXml = $joinColumnXml->addChild('options');

                foreach ($joinColumn->getOptions() as $key => $value) {
                    $optionXml = $optionsXml->addChild('option', $value);

                    $optionXml->addAttribute('name', $key);
                }
            }
        }
    }

    /**
     * @param \SimpleXMLElement $associationXml
     * @param array             $associationCascades
     */
    private function exportCascade(\SimpleXMLElement $associationXml, array $associationCascades)
    {
        $cascades = [];

        foreach (['remove', 'persist', 'refresh', 'merge', 'detach'] as $type) {
            if (in_array($type, $associationCascades)) {
                $cascades[] = 'cascade-' . $type;
            }
        }

        if (count($cascades) === 5) {
            $cascades = ['cascade-all'];
        }

        if ($cascades) {
            $cascadeXml = $associationXml->addChild('cascade');

            foreach ($cascades as $type) {
                $cascadeXml->addChild($type);
            }
        }
    }

    /**
     * Exports (nested) option elements.
     *
     * @param \SimpleXMLElement $parentXml
     * @param array             $options
     */
    private function exportTableOptions(\SimpleXMLElement $parentXml, array $options)
    {
        foreach ($options as $name => $option) {
            $isArray   = is_array($option);
            $optionXml = $isArray
                ? $parentXml->addChild('option')
                : $parentXml->addChild('option', (string) $option);

            $optionXml->addAttribute('name', (string) $name);

            if ($isArray) {
                $this->exportTableOptions($optionXml, $option);
            }
        }
    }

    /**
     * Export sequence information (if available/configured) into the current identifier XML node
     *
     * @param \SimpleXMLElement $identifierXmlNode
     * @param ClassMetadata     $metadata
     *
     * @return void
     */
    private function exportSequenceInformation(\SimpleXMLElement $identifierXmlNode, ClassMetadata $metadata)
    {
        $sequenceDefinition = $metadata->generatorDefinition;

        if (! ($metadata->generatorType === GeneratorType::SEQUENCE && $sequenceDefinition)) {
            return;
        }

        $sequenceGeneratorXml = $identifierXmlNode->addChild('sequence-generator');

        $sequenceGeneratorXml->addAttribute('sequence-name', $sequenceDefinition['sequenceName']);
        $sequenceGeneratorXml->addAttribute('allocation-size', $sequenceDefinition['allocationSize']);
    }

    /**
     * @param \SimpleXMLElement $simpleXml
     *
     * @return string $xml
     */
    private function asXml($simpleXml)
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');

        $dom->loadXML($simpleXml->asXML());
        $dom->formatOutput = true;

        return $dom->saveXML();
    }
}
