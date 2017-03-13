<?php

namespace Doctrine\Tests\ORM\Mapping;

use Doctrine\Common\Persistence\Mapping\RuntimeReflectionService;
use Doctrine\Common\Persistence\Mapping\StaticReflectionService;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\DefaultNamingStrategy;
use Doctrine\ORM\Mapping\DiscriminatorColumnMetadata;
use Doctrine\ORM\Mapping\JoinColumnMetadata;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\Tests\Models\CMS;
use Doctrine\Tests\Models\Company\CompanyContract;
use Doctrine\Tests\Models\CustomType\CustomTypeParent;
use Doctrine\Tests\Models\DDC117\DDC117Article;
use Doctrine\Tests\Models\DDC117\DDC117ArticleDetails;
use Doctrine\Tests\Models\DDC964\DDC964Admin;
use Doctrine\Tests\Models\DDC964\DDC964Guest;
use Doctrine\Tests\Models\Routing\RoutingLeg;
use Doctrine\Tests\OrmTestCase;
use DoctrineGlobal_Article;

require_once __DIR__ . '/../../Models/Global/GlobalNamespaceModel.php';

class ClassMetadataTest extends OrmTestCase
{
    public function testClassMetadataInstanceSerialization()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        // Test initial state
        self::assertTrue(count($cm->getReflectionProperties()) == 0);
        self::assertInstanceOf('ReflectionClass', $cm->reflClass);
        self::assertEquals(CMS\CmsUser::class, $cm->name);
        self::assertEquals(CMS\CmsUser::class, $cm->rootEntityName);
        self::assertEquals([], $cm->subClasses);
        self::assertEquals([], $cm->parentClasses);
        self::assertEquals(Mapping\InheritanceType::NONE, $cm->inheritanceType);

        // Customize state
        $discrColumn = new DiscriminatorColumnMetadata();

        $discrColumn->setColumnName('disc');
        $discrColumn->setType(Type::getType('integer'));

        $cm->setInheritanceType(Mapping\InheritanceType::SINGLE_TABLE);
        $cm->setSubclasses(["One", "Two", "Three"]);
        $cm->setParentClasses(["UserParent"]);
        $cm->setCustomRepositoryClass("UserRepository");
        $cm->setDiscriminatorColumn($discrColumn);
        $cm->markReadOnly();
        $cm->addNamedQuery(['name' => 'dql', 'query' => 'foo']);

        $association = new Mapping\OneToOneAssociationMetadata('phonenumbers');

        $association->setTargetEntity('CmsAddress');
        $association->setMappedBy('foo');

        $cm->addAssociation($association);

        self::assertEquals(1, count($cm->associationMappings));

        $serialized = serialize($cm);
        $cm = unserialize($serialized);
        $cm->wakeupReflection(new RuntimeReflectionService());

        // Check state
        self::assertTrue(count($cm->getReflectionProperties()) > 0);
        self::assertInstanceOf(\ReflectionClass::class, $cm->reflClass);
        self::assertEquals(CMS\CmsUser::class, $cm->name);
        self::assertEquals('UserParent', $cm->rootEntityName);
        self::assertEquals([CMS\One::class, CMS\Two::class, CMS\Three::class], $cm->subClasses);
        self::assertEquals(['UserParent'], $cm->parentClasses);
        self::assertEquals(CMS\UserRepository::class, $cm->customRepositoryClassName);
        self::assertEquals(
            [
                'Doctrine\Tests\Models\CMS\One',
                'Doctrine\Tests\Models\CMS\Two',
                'Doctrine\Tests\Models\CMS\Three'
            ],
            $cm->subClasses
        );
        self::assertEquals(['UserParent'], $cm->parentClasses);
        self::assertEquals(CMS\UserRepository::class, $cm->customRepositoryClassName);
        self::assertEquals($discrColumn, $cm->discriminatorColumn);
        self::assertTrue($cm->isReadOnly);
        self::assertEquals(['dql' => ['name'=>'dql','query'=>'foo','dql'=>'foo']], $cm->namedQueries);
        self::assertEquals(1, count($cm->associationMappings));
        self::assertInstanceOf(Mapping\OneToOneAssociationMetadata::class, $cm->associationMappings['phonenumbers']);

        $oneOneMapping = $cm->associationMappings['phonenumbers'];

        self::assertEquals(Mapping\FetchMode::LAZY, $oneOneMapping->getFetchMode());
        self::assertEquals(CMS\CmsAddress::class, $oneOneMapping->getTargetEntity());
    }

    public function testFieldIsNullable()
    {
        $metadata = new ClassMetadata(CMS\CmsUser::class);
        $metadata->initializeReflection(new RuntimeReflectionService());

        // Explicit Nullable
        $fieldMetadata = new Mapping\FieldMetadata('status');

        $fieldMetadata->setType(Type::getType('string'));
        $fieldMetadata->setLength(50);
        $fieldMetadata->setNullable(true);

        $metadata->addProperty($fieldMetadata);

        $property = $metadata->getProperty('status');

        self::assertTrue($property->isNullable());

        // Explicit Not Nullable
        $fieldMetadata = new Mapping\FieldMetadata('username');

        $fieldMetadata->setType(Type::getType('string'));
        $fieldMetadata->setLength(50);
        $fieldMetadata->setNullable(false);

        $metadata->addProperty($fieldMetadata);

        $property = $metadata->getProperty('username');

        self::assertFalse($property->isNullable());

        // Implicit Not Nullable
        $fieldMetadata = new Mapping\FieldMetadata('name');

        $fieldMetadata->setType(Type::getType('string'));
        $fieldMetadata->setLength(50);

        $metadata->addProperty($fieldMetadata);

        $property = $metadata->getProperty('name');

        self::assertFalse($property->isNullable(), "By default a field should not be nullable.");
    }

    /**
     * @group DDC-115
     */
    public function testMapAssociationInGlobalNamespace()
    {
        require_once __DIR__."/../../Models/Global/GlobalNamespaceModel.php";

        $cm = new ClassMetadata(DoctrineGlobal_Article::class);

        $cm->initializeReflection(new RuntimeReflectionService());

        $joinTable = new Mapping\JoinTableMetadata();
        $joinTable->setName('bar');

        $joinColumn = new Mapping\JoinColumnMetadata();
        $joinColumn->setColumnName("bar_id");
        $joinColumn->setReferencedColumnName("id");

        $joinTable->addJoinColumn($joinColumn);

        $joinColumn = new Mapping\JoinColumnMetadata();
        $joinColumn->setColumnName("baz_id");
        $joinColumn->setReferencedColumnName("id");

        $joinTable->addInverseJoinColumn($joinColumn);

        $association = new Mapping\ManyToManyAssociationMetadata('author');

        $association->setJoinTable($joinTable);
        $association->setTargetEntity('DoctrineGlobal_User');

        $cm->addAssociation($association);

        self::assertEquals("DoctrineGlobal_User", $cm->associationMappings['author']->getTargetEntity());
    }

    public function testMapManyToManyJoinTableDefaults()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $association = new Mapping\ManyToManyAssociationMetadata('groups');

        $association->setTargetEntity('CmsGroup');

        $cm->addAssociation($association);

        $association = $cm->associationMappings['groups'];

        $joinColumns = [];

        $joinColumn = new Mapping\JoinColumnMetadata();

        $joinColumn->setColumnName("cmsuser_id");
        $joinColumn->setReferencedColumnName("id");
        $joinColumn->setOnDelete("CASCADE");

        $joinColumns[] = $joinColumn;

        $inverseJoinColumns = [];

        $joinColumn = new Mapping\JoinColumnMetadata();

        $joinColumn->setColumnName("cmsgroup_id");
        $joinColumn->setReferencedColumnName("id");
        $joinColumn->setOnDelete("CASCADE");

        $inverseJoinColumns[] = $joinColumn;

        $joinTable = $association->getJoinTable();

        self::assertEquals('cmsuser_cmsgroup', $joinTable->getName());
        self::assertEquals($joinColumns, $joinTable->getJoinColumns());
        self::assertEquals($inverseJoinColumns, $joinTable->getInverseJoinColumns());
    }

    public function testSerializeManyToManyJoinTableCascade()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $association = new Mapping\ManyToManyAssociationMetadata('groups');

        $association->setTargetEntity('CmsGroup');

        $cm->addAssociation($association);

        $association = $cm->associationMappings['groups'];
        $association = unserialize(serialize($association));

        $joinTable = $association->getJoinTable();

        foreach ($joinTable->getJoinColumns() as $joinColumn) {
            self::assertEquals('CASCADE', $joinColumn->getOnDelete());
        }
    }

    /**
     * @group DDC-115
     */
    public function testSetDiscriminatorMapInGlobalNamespace()
    {
        require_once __DIR__."/../../Models/Global/GlobalNamespaceModel.php";

        $cm = new ClassMetadata('DoctrineGlobal_User');
        $cm->initializeReflection(new RuntimeReflectionService());
        $cm->setDiscriminatorMap(['descr' => 'DoctrineGlobal_Article', 'foo' => 'DoctrineGlobal_User']);

        self::assertEquals("DoctrineGlobal_Article", $cm->discriminatorMap['descr']);
        self::assertEquals("DoctrineGlobal_User", $cm->discriminatorMap['foo']);
    }

    /**
     * @group DDC-115
     */
    public function testSetSubClassesInGlobalNamespace()
    {
        require_once __DIR__."/../../Models/Global/GlobalNamespaceModel.php";

        $cm = new ClassMetadata('DoctrineGlobal_User');
        $cm->initializeReflection(new RuntimeReflectionService());
        $cm->setSubclasses(['DoctrineGlobal_Article']);

        self::assertEquals("DoctrineGlobal_Article", $cm->subClasses[0]);
    }

    /**
     * @group DDC-268
     */
    public function testSetInvalidVersionMapping_ThrowsException()
    {
        $metadata = new ClassMetadata(CMS\CmsUser::class);
        $property = new Mapping\VersionFieldMetadata('foo'); //new FieldMetadata('foo', 'foo', Type::getType('string'));

        $property->setDeclaringClass($metadata);
        $property->setColumnName('foo');
        $property->setType(Type::getType('string'));

        $metadata->initializeReflection(new RuntimeReflectionService());

        $this->expectException(MappingException::class);

        $metadata->setVersionProperty($property);
    }

    public function testGetSingleIdentifierFieldName_MultipleIdentifierEntity_ThrowsException()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());
        $cm->isIdentifierComposite  = true;

        $this->expectException(MappingException::class);
        $cm->getSingleIdentifierFieldName();
    }

    public function testDuplicateAssociationMappingException()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $association = new Mapping\OneToOneAssociationMetadata('foo');

        $association->setDeclaringClass($cm);
        $association->setSourceEntity('stdClass');
        $association->setTargetEntity('stdClass');
        $association->setMappedBy('foo');

        $cm->addInheritedAssociation($association);

        $this->expectException(MappingException::class);

        $association = new Mapping\OneToOneAssociationMetadata('foo');

        $association->setDeclaringClass($cm);
        $association->setSourceEntity('stdClass');
        $association->setTargetEntity('stdClass');
        $association->setMappedBy('foo');

        $cm->addInheritedAssociation($association);
    }

    public function testDuplicateColumnName_ThrowsMappingException()
    {
        $metadata = new ClassMetadata(CMS\CmsUser::class);
        $metadata->initializeReflection(new RuntimeReflectionService());

        $fieldMetadata = new Mapping\FieldMetadata('name');

        $fieldMetadata->setType(Type::getType('string'));

        $metadata->addProperty($fieldMetadata);

        $this->expectException(MappingException::class);

        $fieldMetadata = new Mapping\FieldMetadata('username');

        $fieldMetadata->setType(Type::getType('string'));
        $fieldMetadata->setColumnName('name');

        $metadata->addProperty($fieldMetadata);
    }

    public function testDuplicateColumnName_DiscriminatorColumn_ThrowsMappingException()
    {
        $metadata = new ClassMetadata(CMS\CmsUser::class);
        $metadata->initializeReflection(new RuntimeReflectionService());

        $fieldMetadata = new Mapping\FieldMetadata('name');

        $fieldMetadata->setType(Type::getType('string'));

        $metadata->addProperty($fieldMetadata);

        $discrColumn = new DiscriminatorColumnMetadata();

        $discrColumn->setColumnName('name');
        $discrColumn->setType(Type::getType('string'));
        $discrColumn->setLength(255);

        $this->expectException(\Doctrine\ORM\Mapping\MappingException::class);

        $metadata->setDiscriminatorColumn($discrColumn);
    }

    public function testDuplicateColumnName_DiscriminatorColumn2_ThrowsMappingException()
    {
        $metadata = new ClassMetadata(CMS\CmsUser::class);
        $metadata->initializeReflection(new RuntimeReflectionService());

        $discrColumn = new DiscriminatorColumnMetadata();

        $discrColumn->setColumnName('name');
        $discrColumn->setType(Type::getType('string'));
        $discrColumn->setLength(255);

        $metadata->setDiscriminatorColumn($discrColumn);

        $this->expectException(\Doctrine\ORM\Mapping\MappingException::class);

        $fieldMetadata = new Mapping\FieldMetadata('name');

        $fieldMetadata->setType(Type::getType('string'));

        $metadata->addProperty($fieldMetadata);
    }

    public function testDuplicateFieldAndAssociationMapping1_ThrowsException()
    {
        $metadata = new ClassMetadata(CMS\CmsUser::class);
        $metadata->initializeReflection(new RuntimeReflectionService());

        $fieldMetadata = new Mapping\FieldMetadata('name');

        $fieldMetadata->setType(Type::getType('string'));

        $metadata->addProperty($fieldMetadata);

        $this->expectException(\Doctrine\ORM\Mapping\MappingException::class);

        $association = new Mapping\OneToOneAssociationMetadata('name');

        $association->setTargetEntity('CmsUser');

        $metadata->addAssociation($association);
    }

    public function testDuplicateFieldAndAssociationMapping2_ThrowsException()
    {
        $metadata = new ClassMetadata(CMS\CmsUser::class);
        $metadata->initializeReflection(new RuntimeReflectionService());

        $association = new Mapping\OneToOneAssociationMetadata('name');

        $association->setTargetEntity('CmsUser');

        $metadata->addAssociation($association);

        $this->expectException(\Doctrine\ORM\Mapping\MappingException::class);

        $fieldMetadata = new Mapping\FieldMetadata('name');

        $fieldMetadata->setType(Type::getType('string'));

        $metadata->addProperty($fieldMetadata);
    }

    /**
     * @group DDC-1224
     */
    public function testGetTemporaryTableNameSchema()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $tableMetadata = new Mapping\TableMetadata();

        $tableMetadata->setSchema('foo');
        $tableMetadata->setName('bar');

        $cm->setPrimaryTable($tableMetadata);

        self::assertEquals('foo_bar_id_tmp', $cm->getTemporaryIdTableName());
    }

    public function testDefaultTableName()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        // When table's name is not given
        self::assertEquals('CmsUser', $cm->getTableName());
        self::assertEquals('CmsUser', $cm->table->getName());

        $cm = new ClassMetadata(CMS\CmsAddress::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        // When joinTable's name is not given
        $joinTable = new Mapping\JoinTableMetadata();

        $joinColumn = new Mapping\JoinColumnMetadata();
        $joinColumn->setReferencedColumnName("id");

        $joinTable->addJoinColumn($joinColumn);

        $joinColumn = new Mapping\JoinColumnMetadata();
        $joinColumn->setReferencedColumnName("id");

        $joinTable->addInverseJoinColumn($joinColumn);

        $association = new Mapping\ManyToManyAssociationMetadata('user');

        $association->setJoinTable($joinTable);
        $association->setTargetEntity('CmsUser');
        $association->setInversedBy('users');

        $cm->addAssociation($association);

        $association = $cm->associationMappings['user'];

        self::assertEquals('cmsaddress_cmsuser', $association->getJoinTable()->getName());
    }

    public function testDefaultJoinColumnName()
    {
        $cm = new ClassMetadata(CMS\CmsAddress::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        // this is really dirty, but it's the simplest way to test whether
        // joinColumn's name will be automatically set to user_id
        $joinColumns = [];

        $joinColumn = new JoinColumnMetadata();

        $joinColumn->setReferencedColumnName('id');

        $joinColumns[] = $joinColumn;

        $association = new Mapping\OneToOneAssociationMetadata('user');

        $association->setJoinColumns($joinColumns);
        $association->setTargetEntity('CmsUser');

        $cm->addAssociation($association);

        $association = $cm->associationMappings['user'];
        $joinColumns = $association->getJoinColumns();
        $joinColumn  = reset($joinColumns);

        self::assertEquals('user_id', $joinColumn->getColumnName());

        $cm = new ClassMetadata(CMS\CmsAddress::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $joinTable = new Mapping\JoinTableMetadata();
        $joinTable->setName('user_CmsUser');

        $joinColumn = new JoinColumnMetadata();
        $joinColumn->setReferencedColumnName('id');

        $joinTable->addJoinColumn($joinColumn);

        $joinColumn = new JoinColumnMetadata();
        $joinColumn->setReferencedColumnName('id');

        $joinTable->addInverseJoinColumn($joinColumn);

        $association = new Mapping\ManyToManyAssociationMetadata('user');

        $association->setJoinTable($joinTable);
        $association->setTargetEntity('CmsUser');
        $association->setInversedBy('users');

        $cm->addAssociation($association);

        $association        = $cm->associationMappings['user'];
        $joinTable          = $association->getJoinTable();
        $joinColumns        = $joinTable->getJoinColumns();
        $joinColumn         = reset($joinColumns);
        $inverseJoinColumns = $joinTable->getInverseJoinColumns();
        $inverseJoinColumn  = reset($inverseJoinColumns);

        self::assertEquals('cmsaddress_id', $joinColumn->getColumnName());
        self::assertEquals('cmsuser_id', $inverseJoinColumn->getColumnName());
    }

    /**
     * @group DDC-559
     */
    public function testOneToOneUnderscoreNamingStrategyDefaults()
    {
        $namingStrategy = new UnderscoreNamingStrategy(CASE_UPPER);
        $metadata       = new ClassMetadata(CMS\CmsAddress::class, $namingStrategy);

        $association = new Mapping\OneToOneAssociationMetadata('user');

        $association->setTargetEntity('CmsUser');

        $metadata->addAssociation($association);

        $association = $metadata->associationMappings['user'];
        $joinColumns = $association->getJoinColumns();
        $joinColumn  = reset($joinColumns);

        self::assertEquals('USER_ID', $joinColumn->getColumnName());
        self::assertEquals('ID', $joinColumn->getReferencedColumnName());
    }

    /**
     * @group DDC-559
     */
    public function testManyToManyUnderscoreNamingStrategyDefaults()
    {
        $namingStrategy = new UnderscoreNamingStrategy(CASE_UPPER);
        $metadata       = new ClassMetadata('Doctrine\Tests\Models\CMS\CmsAddress', $namingStrategy);

        $association = new Mapping\ManyToManyAssociationMetadata('user');

        $association->setTargetEntity('CmsUser');

        $metadata->addAssociation($association);

        $association        = $metadata->associationMappings['user'];
        $joinTable          = $association->getJoinTable();
        $joinColumns        = $joinTable->getJoinColumns();
        $joinColumn         = reset($joinColumns);
        $inverseJoinColumns = $joinTable->getInverseJoinColumns();
        $inverseJoinColumn  = reset($inverseJoinColumns);

        self::assertEquals('CMS_ADDRESS_CMS_USER', $joinTable->getName());

        self::assertEquals('CMS_ADDRESS_ID', $joinColumn->getColumnName());
        self::assertEquals('ID', $joinColumn->getReferencedColumnName());

        self::assertEquals('CMS_USER_ID', $inverseJoinColumn->getColumnName());
        self::assertEquals('ID', $inverseJoinColumn->getReferencedColumnName());

        $cm = new ClassMetadata('DoctrineGlobal_Article', $namingStrategy);

        $association = new Mapping\ManyToManyAssociationMetadata('author');

        $association->setTargetEntity(CMS\CmsUser::class);

        $cm->addAssociation($association);

        $association = $cm->associationMappings['author'];

        self::assertEquals('DOCTRINE_GLOBAL_ARTICLE_CMS_USER', $association->getJoinTable()->getName());
    }

    /**
     * @group DDC-886
     */
    public function testSetMultipleIdentifierSetsComposite()
    {
        $metadata = new ClassMetadata(CMS\CmsUser::class);
        $metadata->initializeReflection(new RuntimeReflectionService());

        $fieldMetadata = new Mapping\FieldMetadata('name');
        $fieldMetadata->setType(Type::getType('string'));

        $metadata->addProperty($fieldMetadata);

        $fieldMetadata = new Mapping\FieldMetadata('username');
        $fieldMetadata->setType(Type::getType('string'));

        $metadata->addProperty($fieldMetadata);

        $metadata->setIdentifier(['name', 'username']);
        self::assertTrue($metadata->isIdentifierComposite);
    }

    /**
     * @group DDC-961
     */
    public function testJoinTableMappingDefaults()
    {
        $metadata = new ClassMetadata('DoctrineGlobal_Article');
        $metadata->initializeReflection(new RuntimeReflectionService());

        $association = new Mapping\ManyToManyAssociationMetadata('author');

        $association->setTargetEntity(CMS\CmsUser::class);

        $metadata->addAssociation($association);

        $association = $metadata->associationMappings['author'];

        self::assertEquals('doctrineglobal_article_cmsuser', $association->getJoinTable()->getName());
    }

    /**
     * @group DDC-117
     */
    public function testMapIdentifierAssociation()
    {
        $cm = new ClassMetadata(DDC117ArticleDetails::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $association = new Mapping\OneToOneAssociationMetadata('article');

        $association->setTargetEntity(DDC117Article::class);
        $association->setPrimaryKey(true);

        $cm->addAssociation($association);

        self::assertEquals(["article"], $cm->identifier);
    }

    /**
     * @group DDC-117
     */
    public function testOrphanRemovalIdentifierAssociation()
    {
        $cm = new ClassMetadata(DDC117ArticleDetails::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('The orphan removal option is not allowed on an association that');

        $association = new Mapping\OneToOneAssociationMetadata('article');

        $association->setTargetEntity(DDC117Article::class);
        $association->setPrimaryKey(true);
        $association->setOrphanRemoval(true);

        $cm->addAssociation($association);
    }

    /**
     * @group DDC-117
     */
    public function testInverseIdentifierAssociation()
    {
        $cm = new ClassMetadata(DDC117ArticleDetails::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('An inverse association is not allowed to be identifier in');

        $association = new Mapping\OneToOneAssociationMetadata('article');

        $association->setTargetEntity(DDC117Article::class);
        $association->setPrimaryKey(true);
        $association->setMappedBy('details');

        $cm->addAssociation($association);
    }

    /**
     * @group DDC-117
     */
    public function testIdentifierAssociationManyToMany()
    {
        $cm = new ClassMetadata(DDC117ArticleDetails::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('Many-to-many or one-to-many associations are not allowed to be identifier in');

        $association = new Mapping\ManyToManyAssociationMetadata('article');

        $association->setTargetEntity(DDC117Article::class);
        $association->setPrimaryKey(true);

        $cm->addAssociation($association);
    }

    /**
     * @group DDC-996
     */
    public function testEmptyFieldNameThrowsException()
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage("The field or association mapping misses the 'fieldName' attribute in entity '" . CMS\CmsUser::class . "'.");

        $metadata = new ClassMetadata(CMS\CmsUser::class);
        $metadata->initializeReflection(new RuntimeReflectionService());

        $fieldMetadata = new Mapping\FieldMetadata('');

        $fieldMetadata->setType(Type::getType('string'));

        $metadata->addProperty($fieldMetadata);
    }

    public function testRetrievalOfNamedQueries()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        self::assertEquals(0, count($cm->getNamedQueries()));

        $cm->addNamedQuery(
            [
                'name'  => 'userById',
                'query' => 'SELECT u FROM __CLASS__ u WHERE u.id = ?1'
            ]
        );

        self::assertEquals(1, count($cm->getNamedQueries()));
    }

    /**
     * @group DDC-1663
     */
    public function testRetrievalOfResultSetMappings()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());


        self::assertEquals(0, count($cm->getSqlResultSetMappings()));

        $cm->addSqlResultSetMapping(
            [
            'name'      => 'find-all',
            'entities'  => [
                [
                    'entityClass'   => CMS\CmsUser::class,
                ],
            ],
            ]
        );

        self::assertEquals(1, count($cm->getSqlResultSetMappings()));
    }

    public function testExistanceOfNamedQuery()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());


        $cm->addNamedQuery(
            [
            'name'  => 'all',
            'query' => 'SELECT u FROM __CLASS__ u'
            ]
        );

        self::assertTrue($cm->hasNamedQuery('all'));
        self::assertFalse($cm->hasNamedQuery('userById'));
    }

    /**
     * @group DDC-1663
     */
    public function testRetrieveOfNamedNativeQuery()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->addNamedNativeQuery(
            [
                'name'              => 'find-all',
                'query'             => 'SELECT * FROM cms_users',
                'resultSetMapping'  => 'result-mapping-name',
                'resultClass'       => CMS\CmsUser::class,
            ]
        );

        $cm->addNamedNativeQuery(
            [
                'name'              => 'find-by-id',
                'query'             => 'SELECT * FROM cms_users WHERE id = ?',
                'resultClass'       => '__CLASS__',
                'resultSetMapping'  => 'result-mapping-name',
            ]
        );

        $mapping = $cm->getNamedNativeQuery('find-all');
        self::assertEquals('SELECT * FROM cms_users', $mapping['query']);
        self::assertEquals('result-mapping-name', $mapping['resultSetMapping']);
        self::assertEquals(CMS\CmsUser::class, $mapping['resultClass']);

        $mapping = $cm->getNamedNativeQuery('find-by-id');
        self::assertEquals('SELECT * FROM cms_users WHERE id = ?', $mapping['query']);
        self::assertEquals('result-mapping-name', $mapping['resultSetMapping']);
        self::assertEquals(CMS\CmsUser::class, $mapping['resultClass']);
    }

    /**
     * @group DDC-1663
     */
    public function testRetrieveOfSqlResultSetMapping()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->addSqlResultSetMapping(
            [
                'name'      => 'find-all',
                'entities'  => [
                    [
                        'entityClass'   => '__CLASS__',
                        'fields'        => [
                            [
                                'name'  => 'id',
                                'column'=> 'id'
                            ],
                            [
                                'name'  => 'name',
                                'column'=> 'name'
                            ]
                        ]
                    ],
                    [
                        'entityClass'   => CMS\CmsEmail::class,
                        'fields'        => [
                            [
                                'name'  => 'id',
                                'column'=> 'id'
                            ],
                            [
                                'name'  => 'email',
                                'column'=> 'email'
                            ]
                        ]
                    ]
                ],
                'columns'   => [['name' => 'scalarColumn']]
            ]
        );

        $mapping = $cm->getSqlResultSetMapping('find-all');

        self::assertEquals(CMS\CmsUser::class, $mapping['entities'][0]['entityClass']);
        self::assertEquals(['name'=>'id','column'=>'id'], $mapping['entities'][0]['fields'][0]);
        self::assertEquals(['name'=>'name','column'=>'name'], $mapping['entities'][0]['fields'][1]);

        self::assertEquals(CMS\CmsEmail::class, $mapping['entities'][1]['entityClass']);
        self::assertEquals(['name'=>'id','column'=>'id'], $mapping['entities'][1]['fields'][0]);
        self::assertEquals(['name'=>'email','column'=>'email'], $mapping['entities'][1]['fields'][1]);

        self::assertEquals('scalarColumn', $mapping['columns'][0]['name']);
    }

    /**
     * @group DDC-1663
     */
    public function testExistanceOfSqlResultSetMapping()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->addSqlResultSetMapping(
            [
                'name'      => 'find-all',
                'entities'  => [
                    [
                        'entityClass'   => CMS\CmsUser::class,
                    ],
                ],
            ]
        );

        self::assertTrue($cm->hasSqlResultSetMapping('find-all'));
        self::assertFalse($cm->hasSqlResultSetMapping('find-by-id'));
    }

    /**
     * @group DDC-1663
     */
    public function testExistanceOfNamedNativeQuery()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->addNamedNativeQuery(
            [
                'name'              => 'find-all',
                'query'             => 'SELECT * FROM cms_users',
                'resultClass'       => CMS\CmsUser::class,
                'resultSetMapping'  => 'result-mapping-name'
            ]
        );

        self::assertTrue($cm->hasNamedNativeQuery('find-all'));
        self::assertFalse($cm->hasNamedNativeQuery('find-by-id'));
    }

    public function testRetrieveOfNamedQuery()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());


        $cm->addNamedQuery(
            [
                'name'  => 'userById',
                'query' => 'SELECT u FROM __CLASS__ u WHERE u.id = ?1'
            ]
        );

        self::assertEquals('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = ?1', $cm->getNamedQuery('userById'));
    }

    /**
     * @group DDC-1663
     */
    public function testRetrievalOfNamedNativeQueries()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        self::assertEquals(0, count($cm->getNamedNativeQueries()));

        $cm->addNamedNativeQuery(
            [
                'name'              => 'find-all',
                'query'             => 'SELECT * FROM cms_users',
                'resultClass'       => CMS\CmsUser::class,
                'resultSetMapping'  => 'result-mapping-name'
            ]
        );

        self::assertEquals(1, count($cm->getNamedNativeQueries()));
    }

    /**
     * @group DDC-2451
     */
    public function testSerializeEntityListeners()
    {
        $metadata = new ClassMetadata(CompanyContract::class);

        $metadata->initializeReflection(new RuntimeReflectionService());
        $metadata->addEntityListener(Events::prePersist, 'CompanyContractListener', 'prePersistHandler');
        $metadata->addEntityListener(Events::postPersist, 'CompanyContractListener', 'postPersistHandler');

        $serialize   = serialize($metadata);
        $unserialize = unserialize($serialize);

        self::assertEquals($metadata->entityListeners, $unserialize->entityListeners);
    }

    /**
     * @expectedException \Doctrine\ORM\Mapping\MappingException
     * @expectedExceptionMessage Query named "userById" in "Doctrine\Tests\Models\CMS\CmsUser" was already declared, but it must be declared only once
     */
    public function testNamingCollisionNamedQueryShouldThrowException()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->addNamedQuery(
            [
                'name'  => 'userById',
                'query' => 'SELECT u FROM __CLASS__ u WHERE u.id = ?1'
            ]
        );

        $cm->addNamedQuery(
            [
                'name'  => 'userById',
                'query' => 'SELECT u FROM __CLASS__ u WHERE u.id = ?1'
            ]
        );
    }

    /**
     * @group DDC-1663
     *
     * @expectedException \Doctrine\ORM\Mapping\MappingException
     * @expectedExceptionMessage Query named "find-all" in "Doctrine\Tests\Models\CMS\CmsUser" was already declared, but it must be declared only once
     */
    public function testNamingCollisionNamedNativeQueryShouldThrowException()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->addNamedNativeQuery(
            [
            'name'              => 'find-all',
            'query'             => 'SELECT * FROM cms_users',
            'resultClass'       => CMS\CmsUser::class,
            'resultSetMapping'  => 'result-mapping-name'
            ]
        );

        $cm->addNamedNativeQuery(
            [
            'name'              => 'find-all',
            'query'             => 'SELECT * FROM cms_users',
            'resultClass'       => CMS\CmsUser::class,
            'resultSetMapping'  => 'result-mapping-name'
            ]
        );
    }

    /**
     * @group DDC-1663
     *
     * @expectedException \Doctrine\ORM\Mapping\MappingException
     * @expectedExceptionMessage Result set mapping named "find-all" in "Doctrine\Tests\Models\CMS\CmsUser" was already declared, but it must be declared only once
     */
    public function testNamingCollisionSqlResultSetMappingShouldThrowException()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->addSqlResultSetMapping(
            [
            'name'      => 'find-all',
            'entities'  => [
                [
                    'entityClass'   => CMS\CmsUser::class,
                ],
            ],
            ]
        );

        $cm->addSqlResultSetMapping(
            [
            'name'      => 'find-all',
            'entities'  => [
                [
                    'entityClass'   => CMS\CmsUser::class,
                ],
            ],
            ]
        );
    }

    /**
     * @group DDC-1068
     */
    public function testClassCaseSensitivity()
    {
        $user = new CMS\CmsUser();
        $cm = new ClassMetadata(strtoupper(CMS\CmsUser::class));
        $cm->initializeReflection(new RuntimeReflectionService());

        self::assertEquals(CMS\CmsUser::class, $cm->name);
    }

    /**
     * @group DDC-659
     */
    public function testLifecycleCallbackNotFound()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());
        $cm->addLifecycleCallback('notfound', 'postLoad');

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage("Entity '" . CMS\CmsUser::class . "' has no method 'notfound' to be registered as lifecycle callback.");

        $cm->validateLifecycleCallbacks(new RuntimeReflectionService());
    }

    /**
     * @group ImproveErrorMessages
     */
    public function testTargetEntityNotFound()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $association = new Mapping\ManyToOneAssociationMetadata('address');

        $association->setTargetEntity('UnknownClass');

        $cm->addAssociation($association);

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage("The target-entity Doctrine\\Tests\\Models\\CMS\\UnknownClass cannot be found in '" . CMS\CmsUser::class . "#address'.");

        $cm->validateAssociations();
    }

    /**
     * @group DDC-1663
     *
     * @expectedException \Doctrine\ORM\Mapping\MappingException
     * @expectedExceptionMessage Query name on entity class 'Doctrine\Tests\Models\CMS\CmsUser' is not defined.
     */
    public function testNameIsMandatoryForNamedQueryMappingException()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->addNamedQuery(
            [
            'query' => 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u',
            ]
        );
    }

    /**
     * @group DDC-1663
     *
     * @expectedException \Doctrine\ORM\Mapping\MappingException
     * @expectedExceptionMessage Query name on entity class 'Doctrine\Tests\Models\CMS\CmsUser' is not defined.
     */
    public function testNameIsMandatoryForNameNativeQueryMappingException()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->addNamedQuery(
            [
            'query'             => 'SELECT * FROM cms_users',
            'resultClass'       => CMS\CmsUser::class,
            'resultSetMapping'  => 'result-mapping-name'
            ]
        );
    }

    /**
     * @group DDC-1663
     *
     * @expectedException \Doctrine\ORM\Mapping\MappingException
     * @expectedExceptionMessage Result set mapping named "find-all" in "Doctrine\Tests\Models\CMS\CmsUser requires a entity class name.
     */
    public function testNameIsMandatoryForEntityNameSqlResultSetMappingException()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->addSqlResultSetMapping(
            [
            'name'      => 'find-all',
            'entities'  => [
                [
                    'fields' => []
                ]
            ],
            ]
        );
    }

    /**
     * @group DDC-984
     * @group DDC-559
     * @group DDC-1575
     */
    public function testFullyQualifiedClassNameShouldBeGivenToNamingStrategy()
    {
        $namingStrategy     = new MyNamespacedNamingStrategy();
        $addressMetadata    = new ClassMetadata(CMS\CmsAddress::class, $namingStrategy);
        $articleMetadata    = new ClassMetadata(DoctrineGlobal_Article::class, $namingStrategy);
        $routingMetadata    = new ClassMetadata(RoutingLeg::class, $namingStrategy);

        $addressMetadata->initializeReflection(new RuntimeReflectionService());
        $articleMetadata->initializeReflection(new RuntimeReflectionService());
        $routingMetadata->initializeReflection(new RuntimeReflectionService());

        $association = new Mapping\ManyToManyAssociationMetadata('user');

        $association->setTargetEntity('CmsUser');

        $addressMetadata->addAssociation($association);

        $association = new Mapping\ManyToManyAssociationMetadata('author');

        $association->setTargetEntity(CMS\CmsUser::class);

        $articleMetadata->addAssociation($association);

        self::assertEquals('routing_routingleg', $routingMetadata->table->getName());
        self::assertEquals('cms_cmsaddress_cms_cmsuser', $addressMetadata->associationMappings['user']->getJoinTable()->getName());
        self::assertEquals('doctrineglobal_article_cms_cmsuser', $articleMetadata->associationMappings['author']->getJoinTable()->getName());
    }

    /**
     * @group DDC-984
     * @group DDC-559
     */
    public function testFullyQualifiedClassNameShouldBeGivenToNamingStrategyPropertyToColumnName()
    {
        $namingStrategy = new MyPrefixNamingStrategy();
        $metadata       = new ClassMetadata(CMS\CmsAddress::class, $namingStrategy);

        $metadata->initializeReflection(new RuntimeReflectionService());

        $fieldMetadata = new Mapping\FieldMetadata('country');

        $fieldMetadata->setType(Type::getType('string'));

        $metadata->addProperty($fieldMetadata);

        $fieldMetadata = new Mapping\FieldMetadata('city');

        $fieldMetadata->setType(Type::getType('string'));

        $metadata->addProperty($fieldMetadata);

        self::assertEquals(
            $metadata->fieldNames,
            [
                'cmsaddress_country' => 'country',
                'cmsaddress_city'    => 'city'
            ]
        );
    }

    /**
     * @group DDC-1746
     * @expectedException        \Doctrine\ORM\Mapping\MappingException
     * @expectedExceptionMessage You have specified invalid cascade options for Doctrine\Tests\Models\CMS\CmsUser::$address: 'invalid'; available options: 'remove', 'persist', 'refresh', 'merge', and 'detach'
     */
    public function testInvalidCascade()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $association = new Mapping\ManyToOneAssociationMetadata('address');

        $association->setTargetEntity('UnknownClass');
        $association->setCascade(['invalid']);

        $cm->addAssociation($association);
     }

    /**
     * @group DDC-964
     * @expectedException        \Doctrine\ORM\Mapping\MappingException
     * @expectedExceptionMessage Invalid field override named 'invalidPropertyName' for class 'Doctrine\Tests\Models\DDC964\DDC964Admin'
     */
    public function testInvalidPropertyAssociationOverrideNameException()
    {
        $cm = new ClassMetadata(DDC964Admin::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $association = new Mapping\ManyToOneAssociationMetadata('address');

        $association->setTargetEntity('DDC964Address');

        $cm->addAssociation($association);

        $cm->setAssociationOverride(new Mapping\ManyToOneAssociationMetadata('invalidPropertyName'));
    }

    /**
     * @group DDC-964
     * @expectedException        \Doctrine\ORM\Mapping\MappingException
     * @expectedExceptionMessage Invalid field override named 'invalidPropertyName' for class 'Doctrine\Tests\Models\DDC964\DDC964Guest'.
     */
    public function testInvalidPropertyAttributeOverrideNameException()
    {
        $metadata = new ClassMetadata(DDC964Guest::class);
        $metadata->initializeReflection(new RuntimeReflectionService());

        $fieldMetadata = new Mapping\FieldMetadata('name');
        $fieldMetadata->setType(Type::getType('string'));

        $metadata->addProperty($fieldMetadata);

        $fieldMetadata = new Mapping\FieldMetadata('invalidPropertyName');
        $fieldMetadata->setType(Type::getType('string'));

        $metadata->setAttributeOverride($fieldMetadata);
    }

    /**
     * @group DDC-1955
     *
     * @expectedException        \Doctrine\ORM\Mapping\MappingException
     * @expectedExceptionMessage Entity Listener "\InvalidClassName" declared on "Doctrine\Tests\Models\CMS\CmsUser" not found.
     */
    public function testInvalidEntityListenerClassException()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->addEntityListener(Events::postLoad, '\InvalidClassName', 'postLoadHandler');
    }

    /**
     * @group DDC-1955
     *
     * @expectedException        \Doctrine\ORM\Mapping\MappingException
     * @expectedExceptionMessage Entity Listener "\Doctrine\Tests\Models\Company\CompanyContractListener" declared on "Doctrine\Tests\Models\CMS\CmsUser" has no method "invalidMethod".
     */
    public function testInvalidEntityListenerMethodException()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->addEntityListener(Events::postLoad, '\Doctrine\Tests\Models\Company\CompanyContractListener', 'invalidMethod');
    }

    public function testManyToManySelfReferencingNamingStrategyDefaults()
    {
        $cm = new ClassMetadata(CustomTypeParent::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $association = new Mapping\ManyToManyAssociationMetadata('friendsWithMe');

        $association->setTargetEntity('CustomTypeParent');

        $cm->addAssociation($association);

        $association = $cm->associationMappings['friendsWithMe'];

        $joinColumns = [];

        $joinColumn = new Mapping\JoinColumnMetadata();

        $joinColumn->setColumnName("customtypeparent_source");
        $joinColumn->setReferencedColumnName("id");
        $joinColumn->setOnDelete("CASCADE");

        $joinColumns[] = $joinColumn;

        $inverseJoinColumns = [];

        $joinColumn = new Mapping\JoinColumnMetadata();

        $joinColumn->setColumnName("customtypeparent_target");
        $joinColumn->setReferencedColumnName("id");
        $joinColumn->setOnDelete("CASCADE");

        $inverseJoinColumns[] = $joinColumn;

        $joinTable = $association->getJoinTable();

        self::assertEquals('customtypeparent_customtypeparent', $joinTable->getName());
        self::assertEquals($joinColumns, $joinTable->getJoinColumns());
        self::assertEquals($inverseJoinColumns, $joinTable->getInverseJoinColumns());
    }

    /**
     * @group DDC-2608
     */
    public function testSetSequenceGeneratorThrowsExceptionWhenSequenceNameIsMissing()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class);

        $cm->setIdGeneratorType(Mapping\GeneratorType::SEQUENCE);
        $cm->initializeReflection(new RuntimeReflectionService());

        $this->expectException(Mapping\MappingException::class);

        $cm->setGeneratorDefinition([]);
    }

    /**
     * @group DDC-2662
     */
    public function testQuotedSequenceName()
    {
        $cm = new ClassMetadata(CMS\CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->setGeneratorDefinition(['sequenceName' => 'foo', 'allocationSize' => 1]);

        self::assertEquals(['sequenceName' => 'foo', 'allocationSize' => 1], $cm->generatorDefinition);
    }

    /**
     * @group DDC-2700
     */
    public function testIsIdentifierMappedSuperClass()
    {
        $class = new ClassMetadata(DDC2700MappedSuperClass::class);

        self::assertFalse($class->isIdentifier('foo'));
    }

    /**
     * @group DDC-3120
     */
    public function testCanInstantiateInternalPhpClassSubclass()
    {
        $classMetadata = new ClassMetadata(MyArrayObjectEntity::class);

        self::assertInstanceOf(MyArrayObjectEntity::class, $classMetadata->newInstance());
    }

    /**
     * @group DDC-3120
     */
    public function testCanInstantiateInternalPhpClassSubclassFromUnserializedMetadata()
    {
        /* @var $classMetadata ClassMetadata */
        $classMetadata = unserialize(serialize(new ClassMetadata(MyArrayObjectEntity::class)));

        $classMetadata->wakeupReflection(new RuntimeReflectionService());

        self::assertInstanceOf(MyArrayObjectEntity::class, $classMetadata->newInstance());
    }

    /**
     * @group embedded
     */
    public function testWakeupReflectionWithEmbeddableAndStaticReflectionService()
    {
        $metadata = new ClassMetadata(TestEntity1::class);

        $metadata->mapEmbedded(
            [
                'fieldName'    => 'test',
                'class'        => TestEntity1::class,
                'columnPrefix' => false,
            ]
        );

        $fieldMetadata = new Mapping\FieldMetadata('test.embeddedProperty');
        $fieldMetadata->setType(Type::getType('string'));

        $metadata->addProperty($fieldMetadata);

        /*
        $mapping = [
            'originalClass' => TestEntity1::class,
            'declaredField' => 'test',
            'originalField' => 'embeddedProperty'
        ];

        $metadata->addProperty('test.embeddedProperty', Type::getType('string'), $mapping);
        */

        $metadata->wakeupReflection(new StaticReflectionService());

        self::assertEquals(
            [
                'test'                  => null,
                'test.embeddedProperty' => null
            ],
            $metadata->getReflectionProperties()
        );
    }
}

/**
 * @ORM\MappedSuperclass
 */
class DDC2700MappedSuperClass
{
    /** @ORM\Column */
    private $foo;
}

class MyNamespacedNamingStrategy extends DefaultNamingStrategy
{
    /**
     * {@inheritdoc}
     */
    public function classToTableName($className)
    {
        if (strpos($className, '\\') !== false) {
            $className = str_replace('\\', '_', str_replace('Doctrine\Tests\Models\\', '', $className));
        }

        return strtolower($className);
    }
}

class MyPrefixNamingStrategy extends DefaultNamingStrategy
{
    /**
     * {@inheritdoc}
     */
    public function propertyToColumnName($propertyName, $className = null)
    {
        return strtolower($this->classToTableName($className)) . '_' . $propertyName;
    }
}

class MyArrayObjectEntity extends \ArrayObject
{
}
