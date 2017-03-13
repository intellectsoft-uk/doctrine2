<?php

namespace Doctrine\Tests\ORM\Mapping;

use Doctrine\Common\Persistence\Mapping\RuntimeReflectionService;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Annotation as ORM;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Mapping\DefaultNamingStrategy;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\Tests\Models\Cache\City;
use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\CMS\CmsAddressListener;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\Models\Company\CompanyContract;
use Doctrine\Tests\Models\Company\CompanyContractListener;
use Doctrine\Tests\Models\Company\CompanyFixContract;
use Doctrine\Tests\Models\Company\CompanyFlexContract;
use Doctrine\Tests\Models\Company\CompanyFlexUltraContract;
use Doctrine\Tests\Models\Company\CompanyFlexUltraContractListener;
use Doctrine\Tests\Models\Company\CompanyPerson;
use Doctrine\Tests\Models\DDC1476\DDC1476EntityWithDefaultFieldType;
use Doctrine\Tests\Models\DDC2825\ExplicitSchemaAndTable;
use Doctrine\Tests\Models\DDC2825\SchemaAndTableInTableName;
use Doctrine\Tests\Models\DDC3579\DDC3579Admin;
use Doctrine\Tests\Models\DDC869\DDC869ChequePayment;
use Doctrine\Tests\Models\DDC869\DDC869CreditCardPayment;
use Doctrine\Tests\Models\DDC869\DDC869PaymentRepository;
use Doctrine\Tests\Models\DDC889\DDC889Class;
use Doctrine\Tests\Models\DDC889\DDC889Entity;
use Doctrine\Tests\Models\DDC964\DDC964Admin;
use Doctrine\Tests\Models\DDC964\DDC964Guest;
use Doctrine\Tests\OrmTestCase;

abstract class AbstractMappingDriverTest extends OrmTestCase
{
    abstract protected function loadDriver();

    public function createClassMetadata($entityClassName)
    {
        $mappingDriver = $this->loadDriver();

        $class = new ClassMetadata($entityClassName);
        $class->initializeReflection(new RuntimeReflectionService());
        $mappingDriver->loadMetadataForClass($entityClassName, $class);

        return $class;
    }

    /**
     * @param \Doctrine\ORM\EntityManager $entityClassName
     * @return \Doctrine\ORM\Mapping\ClassMetadataFactory
     */
    protected function createClassMetadataFactory(EntityManager $em = null)
    {
        $driver     = $this->loadDriver();
        $em         = $em ?: $this->getTestEntityManager();
        $factory    = new ClassMetadataFactory();
        $em->getConfiguration()->setMetadataDriverImpl($driver);
        $factory->setEntityManager($em);

        return $factory;
    }

    public function testLoadMapping()
    {
        return $this->createClassMetadata(User::class);
    }

    /**
     * @depends testLoadMapping
     * @param ClassMetadata $class
     */
    public function testEntityTableNameAndInheritance($class)
    {
        self::assertEquals('cms_users', $class->getTableName());
        self::assertEquals(Mapping\InheritanceType::NONE, $class->inheritanceType);

        return $class;
    }

    /**
     *
     * @param ClassMetadata $class
     */
    public function testEntityIndexes()
    {
        $class = $this->createClassMetadata('Doctrine\Tests\ORM\Mapping\User');

        self::assertCount(2, $class->table->getIndexes());
        self::assertEquals(
            [
                'name_idx' => [
                    'name'    => 'name_idx',
                    'columns' => ['name'],
                    'unique'  => false,
                    'options' => [],
                    'flags'   => [],
                ],
                0 => [
                    'name'    => null,
                    'columns' => ['user_email'],
                    'unique'  => false,
                    'options' => [],
                    'flags'   => [],
                ]
            ],
            $class->table->getIndexes()
        );

        return $class;
    }

    public function testEntityIndexFlagsAndPartialIndexes()
    {
        $class = $this->createClassMetadata(Comment::class);

        self::assertEquals(
            [
                0 => [
                    'name'    => null,
                    'columns' => ['content'],
                    'unique'  => false,
                    'flags'   => ['fulltext'],
                    'options' => ['where' => 'content IS NOT NULL'],
                ]
            ],
            $class->table->getIndexes()
        );
    }

    /**
     * @depends testEntityTableNameAndInheritance
     * @param ClassMetadata $class
     */
    public function testEntityUniqueConstraints($class)
    {
        self::assertCount(1, $class->table->getUniqueConstraints());
        self::assertEquals(
            [
                'search_idx' => [
                    'name'    => 'search_idx',
                    'columns' => ['name', 'user_email'],
                    'options' => [],
                    'flags'   => [],
                ]
            ],
            $class->table->getUniqueConstraints()
        );

        return $class;
    }

    /**
     * @depends testEntityTableNameAndInheritance
     * @param ClassMetadata $class
     */
    public function testEntityOptions($class)
    {
        self::assertCount(2, $class->table->getOptions());
        self::assertEquals(
            [
                'foo' => 'bar',
                'baz' => ['key' => 'val']
            ],
            $class->table->getOptions()
        );

        return $class;
    }

    /**
     * @depends testEntityOptions
     * @param ClassMetadata $class
     */
    public function testEntitySequence($class)
    {
        self::assertInternalType('array', $class->generatorDefinition, 'No Sequence Definition set on this driver.');
        self::assertEquals(
            [
                'sequenceName'   => 'tablename_seq',
                'allocationSize' => 100,
            ],
            $class->generatorDefinition
        );
    }

    public function testEntityCustomGenerator()
    {
        $class = $this->createClassMetadata(Animal::class);

        self::assertEquals(Mapping\GeneratorType::CUSTOM, $class->generatorType, "Generator Type");
        self::assertEquals(
            [
                'class'     => 'stdClass',
                'arguments' => [],
            ],
            $class->generatorDefinition,
            "Generator Definition"
        );
    }


    /**
     * @depends testEntityTableNameAndInheritance
     * @param ClassMetadata $class
     */
    public function testFieldMappings($class)
    {
        self::assertEquals(4, count($class->getProperties()));

        self::assertNotNull($class->getProperty('id'));
        self::assertNotNull($class->getProperty('name'));
        self::assertNotNull($class->getProperty('email'));
        self::assertNotNull($class->getProperty('version'));

        return $class;
    }

    /**
     * @depends testFieldMappings
     * @param ClassMetadata $class
     */
    public function testVersionProperty($class)
    {
        self::assertTrue($class->isVersioned());
        self::assertNotNull($class->versionProperty);

        $versionPropertyName = $class->versionProperty->getName();

        self::assertEquals("version", $versionPropertyName);
        self::assertNotNull($class->getProperty($versionPropertyName));
    }

    /**
     * @depends testEntityTableNameAndInheritance
     * @param ClassMetadata $class
     */
    public function testFieldMappingsColumnNames($class)
    {
        self::assertNotNull($class->getProperty('id'));
        self::assertNotNull($class->getProperty('name'));
        self::assertNotNull($class->getProperty('email'));

        self::assertEquals("id", $class->getProperty('id')->getColumnName());
        self::assertEquals("name", $class->getProperty('name')->getColumnName());
        self::assertEquals("user_email", $class->getProperty('email')->getColumnName());

        return $class;
    }

    /**
     * @depends testEntityTableNameAndInheritance
     * @param ClassMetadata $class
     */
    public function testStringFieldMappings($class)
    {
        self::assertNotNull($class->getProperty('name'));

        $property = $class->getProperty('name');

        self::assertEquals('string', $property->getTypeName());
        self::assertEquals(50, $property->getLength());
        self::assertTrue($property->isNullable());
        self::assertTrue($property->isUnique());

        return $class;
    }

    /**
     * @depends testEntityTableNameAndInheritance
     *
     * @param ClassMetadata $class
     *
     * @return ClassMetadata
     */
    public function testFieldOptions(ClassMetadata $class)
    {
        self::assertNotNull($class->getProperty('name'));

        $property = $class->getProperty('name');
        $expected = ['foo' => 'bar', 'baz' => ['key' => 'val'], 'fixed' => false];

        self::assertEquals($expected, $property->getOptions());

        return $class;
    }

    /**
     * @depends testEntityTableNameAndInheritance
     * @param ClassMetadata $class
     */
    public function testIdFieldOptions($class)
    {
        self::assertNotNull($class->getProperty('id'));

        $property = $class->getProperty('id');
        $expected = ['foo' => 'bar', 'unsigned' => false];

        self::assertEquals($expected, $property->getOptions());

        return $class;
    }

    /**
     * @depends testFieldMappings
     * @param ClassMetadata $class
     */
    public function testIdentifier($class)
    {
        self::assertNotNull($class->getProperty('id'));

        $property = $class->getProperty('id');

        self::assertEquals('integer', $property->getTypeName());
        self::assertEquals(['id'], $class->identifier);
        self::assertEquals(Mapping\GeneratorType::AUTO, $class->generatorType, "ID-Generator is not GeneratorType::AUTO");

        return $class;
    }

    /**
     * @group #6129
     *
     * @depends testLoadMapping
     *
     * @param ClassMetadata $class
     *
     * @return ClassMetadata
     */
    public function testBooleanValuesForOptionIsSetCorrectly(ClassMetadata $class)
    {
        $idOptions = $class->getProperty('id')->getOptions();
        $nameOptions = $class->getProperty('name')->getOptions();

        self::assertInternalType('bool', $idOptions['unsigned']);
        self::assertFalse($idOptions['unsigned']);
        self::assertInternalType('bool', $nameOptions['fixed']);
        self::assertFalse($nameOptions['fixed']);

        return $class;
    }

    /**
     * @depends testIdentifier
     * @param ClassMetadata $class
     */
    public function testAssociations($class)
    {
        self::assertEquals(3, count($class->associationMappings));

        return $class;
    }

    /**
     * @depends testAssociations
     * @param ClassMetadata $class
     */
    public function testOwningOneToOneAssociation($class)
    {
        self::assertArrayHasKey('address', $class->associationMappings);

        $association = $class->associationMappings['address'];

        self::assertTrue($association->isOwningSide());
        self::assertEquals('user', $association->getInversedBy());
        // Check cascading
        self::assertEquals(['remove'], $association->getCascade());

        return $class;
    }

    /**
     * @depends testOwningOneToOneAssociation
     * @param ClassMetadata $class
     */
    public function testInverseOneToManyAssociation($class)
    {
        self::assertArrayHasKey('phonenumbers', $class->associationMappings);

        $association = $class->associationMappings['phonenumbers'];

        self::assertFalse($association->isOwningSide());
        self::assertTrue($association->isOrphanRemoval());

        // Check cascading
        self::assertEquals(['persist', 'remove'], $association->getCascade());

        // Test Order By
        self::assertEquals(['number' => 'ASC'], $association->getOrderBy());

        return $class;
    }

    /**
     * @depends testInverseOneToManyAssociation
     * @param ClassMetadata $class
     */
    public function testManyToManyAssociationWithCascadeAll($class)
    {
        self::assertArrayHasKey('groups', $class->associationMappings);

        $association = $class->associationMappings['groups'];

        self::assertTrue($association->isOwningSide());

        // Make sure that cascade-all works as expected
        self::assertEquals(['remove', 'persist', 'refresh', 'merge', 'detach'], $association->getCascade());

        // Test Order By
        self::assertEquals([], $association->getOrderBy());

        return $class;
    }

    /**
     * @depends testManyToManyAssociationWithCascadeAll
     * @param ClassMetadata $class
     */
    public function testLifecycleCallbacks($class)
    {
        self::assertEquals(count($class->lifecycleCallbacks), 2);
        self::assertEquals($class->lifecycleCallbacks['prePersist'][0], 'doStuffOnPrePersist');
        self::assertEquals($class->lifecycleCallbacks['postPersist'][0], 'doStuffOnPostPersist');

        return $class;
    }

    /**
     * @depends testManyToManyAssociationWithCascadeAll
     * @param ClassMetadata $class
     */
    public function testLifecycleCallbacksSupportMultipleMethodNames($class)
    {
        self::assertEquals(count($class->lifecycleCallbacks['prePersist']), 2);
        self::assertEquals($class->lifecycleCallbacks['prePersist'][1], 'doOtherStuffOnPrePersistToo');

        return $class;
    }

    /**
     * @depends testLifecycleCallbacksSupportMultipleMethodNames
     * @param ClassMetadata $class
     */
    public function testJoinColumnUniqueAndNullable($class)
    {
        // Non-Nullability of Join Column
        $association = $class->associationMappings['groups'];
        $joinTable   = $association->getJoinTable();
        $joinColumns = $joinTable->getJoinColumns();
        $joinColumn  = reset($joinColumns);

        self::assertFalse($joinColumn->isNullable());
        self::assertFalse($joinColumn->isUnique());

        return $class;
    }

    /**
     * @depends testJoinColumnUniqueAndNullable
     * @param ClassMetadata $class
     */
    public function testColumnDefinition($class)
    {
        self::assertNotNull($class->getProperty('email'));

        $property           = $class->getProperty('email');
        $association        = $class->associationMappings['groups'];
        $joinTable          = $association->getJoinTable();
        $inverseJoinColumns = $joinTable->getInverseJoinColumns();
        $inverseJoinColumn  = reset($inverseJoinColumns);

        self::assertEquals("CHAR(32) NOT NULL", $property->getColumnDefinition());
        self::assertEquals("INT NULL", $inverseJoinColumn->getColumnDefinition());

        return $class;
    }

    /**
     * @depends testColumnDefinition
     * @param ClassMetadata $class
     */
    public function testJoinColumnOnDelete($class)
    {
        $association = $class->associationMappings['address'];
        $joinColumns = $association->getJoinColumns();
        $joinColumn  = reset($joinColumns);

        self::assertEquals('CASCADE', $joinColumn->getOnDelete());

        return $class;
    }

    /**
     * @group DDC-514
     */
    public function testDiscriminatorColumnDefaults()
    {
        if (strpos(get_class($this), 'PHPMappingDriver') !== false) {
            $this->markTestSkipped('PHP Mapping Drivers have no defaults.');
        }

        $class = $this->createClassMetadata(Animal::class);

        self::assertNotNull($class->discriminatorColumn);

        $discrColumn = $class->discriminatorColumn;

        self::assertEquals('Animal', $discrColumn->getTableName());
        self::assertEquals('discr', $discrColumn->getColumnName());
        self::assertEquals('string', $discrColumn->getTypeName());
        self::assertEquals(32, $discrColumn->getLength());
        self::assertNull($discrColumn->getColumnDefinition());
    }

    /**
     * @group DDC-869
     */
    public function testMappedSuperclassWithRepository()
    {
        $em      = $this->getTestEntityManager();
        $factory = $this->createClassMetadataFactory($em);
        $class   = $factory->getMetadataFor(DDC869CreditCardPayment::class);

        self::assertNotNull($class->getProperty('id'));
        self::assertNotNull($class->getProperty('value'));
        self::assertNotNull($class->getProperty('creditCardNumber'));
        self::assertEquals($class->customRepositoryClassName, DDC869PaymentRepository::class);
        self::assertInstanceOf(DDC869PaymentRepository::class, $em->getRepository(DDC869CreditCardPayment::class));
        self::assertTrue($em->getRepository(DDC869ChequePayment::class)->isTrue());

        $class = $factory->getMetadataFor(DDC869ChequePayment::class);

        self::assertNotNull($class->getProperty('id'));
        self::assertNotNull($class->getProperty('value'));
        self::assertNotNull($class->getProperty('serialNumber'));
        self::assertEquals($class->customRepositoryClassName, DDC869PaymentRepository::class);
        self::assertInstanceOf(DDC869PaymentRepository::class, $em->getRepository(DDC869ChequePayment::class));
        self::assertTrue($em->getRepository(DDC869ChequePayment::class)->isTrue());
    }

    /**
     * @group DDC-1476
     */
    public function testDefaultFieldType()
    {
        $factory = $this->createClassMetadataFactory();
        $class   = $factory->getMetadataFor(DDC1476EntityWithDefaultFieldType::class);

        self::assertNotNull($class->getProperty('id'));
        self::assertNotNull($class->getProperty('name'));

        $idProperty = $class->getProperty('id');
        $nameProperty = $class->getProperty('name');

        self::assertInstanceOf(Mapping\FieldMetadata::class, $idProperty);
        self::assertInstanceOf(Mapping\FieldMetadata::class, $nameProperty);

        self::assertEquals('string', $idProperty->getTypeName());
        self::assertEquals('string', $nameProperty->getTypeName());

        self::assertEquals('id', $idProperty->getName());
        self::assertEquals('name', $nameProperty->getName());

        self::assertEquals('id', $idProperty->getColumnName());
        self::assertEquals('name', $nameProperty->getColumnName());

        self::assertEquals(Mapping\GeneratorType::NONE, $class->generatorType);
    }

    /**
     * @group DDC-1170
     */
    public function testIdentifierColumnDefinition()
    {
        $class = $this->createClassMetadata(DDC1170Entity::class);

        self::assertNotNull($class->getProperty('id'));
        self::assertNotNull($class->getProperty('value'));

        self::assertEquals("INT unsigned NOT NULL", $class->getProperty('id')->getColumnDefinition());
        self::assertEquals("VARCHAR(255) NOT NULL", $class->getProperty('value')->getColumnDefinition());
    }

    /**
     * @group DDC-559
     */
    public function testNamingStrategy()
    {
        $em      = $this->getTestEntityManager();
        $factory = $this->createClassMetadataFactory($em);

        self::assertInstanceOf(DefaultNamingStrategy::class, $em->getConfiguration()->getNamingStrategy());
        $em->getConfiguration()->setNamingStrategy(new UnderscoreNamingStrategy(CASE_UPPER));
        self::assertInstanceOf(UnderscoreNamingStrategy::class, $em->getConfiguration()->getNamingStrategy());

        $class = $factory->getMetadataFor(DDC1476EntityWithDefaultFieldType::class);

        self::assertEquals('ID', $class->getColumnName('id'));
        self::assertEquals('NAME', $class->getColumnName('name'));
        self::assertEquals('DDC1476ENTITY_WITH_DEFAULT_FIELD_TYPE', $class->table->getName());
    }

    /**
     * @group DDC-807
     * @group DDC-553
     */
    public function testDiscriminatorColumnDefinition()
    {
        $class = $this->createClassMetadata(DDC807Entity::class);

        self::assertNotNull($class->discriminatorColumn);

        $discrColumn = $class->discriminatorColumn;

        self::assertEquals('dtype', $discrColumn->getColumnName());
        self::assertEquals("ENUM('ONE','TWO')", $discrColumn->getColumnDefinition());
    }

    /**
     * @group DDC-889
     */
    public function testInvalidEntityOrMappedSuperClassShouldMentionParentClasses()
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('Class "Doctrine\Tests\Models\DDC889\DDC889Class" sub class of "Doctrine\Tests\Models\DDC889\DDC889SuperClass" is not a valid entity or mapped super class.');

        $this->createClassMetadata(DDC889Class::class);
    }

    /**
     * @group DDC-889
     */
    public function testIdentifierRequiredShouldMentionParentClasses()
    {
        $factory = $this->createClassMetadataFactory();

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('No identifier/primary key specified for Entity "Doctrine\Tests\Models\DDC889\DDC889Entity" sub class of "Doctrine\Tests\Models\DDC889\DDC889SuperClass". Every Entity must have an identifier/primary key.');

        $factory->getMetadataFor(DDC889Entity::class);
    }

    public function testNamedQuery()
    {
        $driver = $this->loadDriver();
        $class = $this->createClassMetadata(User::class);

        self::assertCount(1, $class->getNamedQueries(), sprintf("Named queries not processed correctly by driver %s", get_class($driver)));
    }

    /**
     * @group DDC-1663
     */
    public function testNamedNativeQuery()
    {

        $class = $this->createClassMetadata(CmsAddress::class);

        //named native query
        self::assertCount(3, $class->namedNativeQueries);
        self::assertArrayHasKey('find-all', $class->namedNativeQueries);
        self::assertArrayHasKey('find-by-id', $class->namedNativeQueries);

        $findAllQuery = $class->getNamedNativeQuery('find-all');
        self::assertEquals('find-all', $findAllQuery['name']);
        self::assertEquals('mapping-find-all', $findAllQuery['resultSetMapping']);
        self::assertEquals('SELECT id, country, city FROM cms_addresses', $findAllQuery['query']);

        $findByIdQuery = $class->getNamedNativeQuery('find-by-id');
        self::assertEquals('find-by-id', $findByIdQuery['name']);
        self::assertEquals(CmsAddress::class,$findByIdQuery['resultClass']);
        self::assertEquals('SELECT * FROM cms_addresses WHERE id = ?',  $findByIdQuery['query']);

        $countQuery = $class->getNamedNativeQuery('count');
        self::assertEquals('count', $countQuery['name']);
        self::assertEquals('mapping-count', $countQuery['resultSetMapping']);
        self::assertEquals('SELECT COUNT(*) AS count FROM cms_addresses',  $countQuery['query']);

        // result set mapping
        self::assertCount(3, $class->sqlResultSetMappings);
        self::assertArrayHasKey('mapping-count', $class->sqlResultSetMappings);
        self::assertArrayHasKey('mapping-find-all', $class->sqlResultSetMappings);
        self::assertArrayHasKey('mapping-without-fields', $class->sqlResultSetMappings);

        $findAllMapping = $class->getSqlResultSetMapping('mapping-find-all');
        self::assertEquals('mapping-find-all', $findAllMapping['name']);
        self::assertEquals(CmsAddress::class, $findAllMapping['entities'][0]['entityClass']);
        self::assertEquals(['name'=>'id','column'=>'id'], $findAllMapping['entities'][0]['fields'][0]);
        self::assertEquals(['name'=>'city','column'=>'city'], $findAllMapping['entities'][0]['fields'][1]);
        self::assertEquals(['name'=>'country','column'=>'country'], $findAllMapping['entities'][0]['fields'][2]);

        $withoutFieldsMapping = $class->getSqlResultSetMapping('mapping-without-fields');
        self::assertEquals('mapping-without-fields', $withoutFieldsMapping['name']);
        self::assertEquals(CmsAddress::class, $withoutFieldsMapping['entities'][0]['entityClass']);
        self::assertEquals([], $withoutFieldsMapping['entities'][0]['fields']);

        $countMapping = $class->getSqlResultSetMapping('mapping-count');
        self::assertEquals('mapping-count', $countMapping['name']);
        self::assertEquals(['name'=>'count'], $countMapping['columns'][0]);

    }

    /**
     * @group DDC-1663
     */
    public function testSqlResultSetMapping()
    {
        $userMetadata   = $this->createClassMetadata(CmsUser::class);
        $personMetadata = $this->createClassMetadata(CompanyPerson::class);

        // user asserts
        self::assertCount(4, $userMetadata->getSqlResultSetMappings());

        $mapping = $userMetadata->getSqlResultSetMapping('mappingJoinedAddress');
        self::assertEquals([],$mapping['columns']);
        self::assertEquals('mappingJoinedAddress', $mapping['name']);
        self::assertNull($mapping['entities'][0]['discriminatorColumn']);
        self::assertEquals(['name'=>'id','column'=>'id'],                   $mapping['entities'][0]['fields'][0]);
        self::assertEquals(['name'=>'name','column'=>'name'],               $mapping['entities'][0]['fields'][1]);
        self::assertEquals(['name'=>'status','column'=>'status'],           $mapping['entities'][0]['fields'][2]);
        self::assertEquals(['name'=>'address.zip','column'=>'zip'],         $mapping['entities'][0]['fields'][3]);
        self::assertEquals(['name'=>'address.city','column'=>'city'],       $mapping['entities'][0]['fields'][4]);
        self::assertEquals(['name'=>'address.country','column'=>'country'], $mapping['entities'][0]['fields'][5]);
        self::assertEquals(['name'=>'address.id','column'=>'a_id'],         $mapping['entities'][0]['fields'][6]);
        self::assertEquals($userMetadata->name,                             $mapping['entities'][0]['entityClass']);

        $mapping = $userMetadata->getSqlResultSetMapping('mappingJoinedPhonenumber');
        self::assertEquals([],$mapping['columns']);
        self::assertEquals('mappingJoinedPhonenumber', $mapping['name']);
        self::assertNull($mapping['entities'][0]['discriminatorColumn']);
        self::assertEquals(['name'=>'id','column'=>'id'],                             $mapping['entities'][0]['fields'][0]);
        self::assertEquals(['name'=>'name','column'=>'name'],                         $mapping['entities'][0]['fields'][1]);
        self::assertEquals(['name'=>'status','column'=>'status'],                     $mapping['entities'][0]['fields'][2]);
        self::assertEquals(['name'=>'phonenumbers.phonenumber','column'=>'number'],   $mapping['entities'][0]['fields'][3]);
        self::assertEquals($userMetadata->name,                                       $mapping['entities'][0]['entityClass']);

        $mapping = $userMetadata->getSqlResultSetMapping('mappingUserPhonenumberCount');
        self::assertEquals(['name'=>'numphones'],$mapping['columns'][0]);
        self::assertEquals('mappingUserPhonenumberCount', $mapping['name']);
        self::assertNull($mapping['entities'][0]['discriminatorColumn']);
        self::assertEquals(['name'=>'id','column'=>'id'],         $mapping['entities'][0]['fields'][0]);
        self::assertEquals(['name'=>'name','column'=>'name'],     $mapping['entities'][0]['fields'][1]);
        self::assertEquals(['name'=>'status','column'=>'status'], $mapping['entities'][0]['fields'][2]);
        self::assertEquals($userMetadata->name,                   $mapping['entities'][0]['entityClass']);

        $mapping = $userMetadata->getSqlResultSetMapping('mappingMultipleJoinsEntityResults');
        self::assertEquals(['name'=>'numphones'],$mapping['columns'][0]);
        self::assertEquals('mappingMultipleJoinsEntityResults', $mapping['name']);
        self::assertNull($mapping['entities'][0]['discriminatorColumn']);
        self::assertEquals(['name'=>'id','column'=>'u_id'],           $mapping['entities'][0]['fields'][0]);
        self::assertEquals(['name'=>'name','column'=>'u_name'],       $mapping['entities'][0]['fields'][1]);
        self::assertEquals(['name'=>'status','column'=>'u_status'],   $mapping['entities'][0]['fields'][2]);
        self::assertEquals($userMetadata->name,                       $mapping['entities'][0]['entityClass']);
        self::assertNull($mapping['entities'][1]['discriminatorColumn']);
        self::assertEquals(['name'=>'id','column'=>'a_id'],           $mapping['entities'][1]['fields'][0]);
        self::assertEquals(['name'=>'zip','column'=>'a_zip'],         $mapping['entities'][1]['fields'][1]);
        self::assertEquals(['name'=>'country','column'=>'a_country'], $mapping['entities'][1]['fields'][2]);
        self::assertEquals(CmsAddress::class,                         $mapping['entities'][1]['entityClass']);

        //person asserts
        self::assertCount(1, $personMetadata->getSqlResultSetMappings());

        $mapping = $personMetadata->getSqlResultSetMapping('mappingFetchAll');

        self::assertEquals([], $mapping['columns']);
        self::assertEquals('mappingFetchAll', $mapping['name']);
        self::assertEquals('discriminator',                   $mapping['entities'][0]['discriminatorColumn']);
        self::assertEquals(['name'=>'id','column'=>'id'],     $mapping['entities'][0]['fields'][0]);
        self::assertEquals(['name'=>'name','column'=>'name'], $mapping['entities'][0]['fields'][1]);
        self::assertEquals($personMetadata->name,             $mapping['entities'][0]['entityClass']);
    }

    /*
     * @group DDC-964
     */
    public function testAssociationOverridesMapping()
    {
        $factory        = $this->createClassMetadataFactory();
        $adminMetadata  = $factory->getMetadataFor(DDC964Admin::class);
        $guestMetadata  = $factory->getMetadataFor(DDC964Guest::class);

        // assert groups association mappings
        self::assertArrayHasKey('groups', $guestMetadata->associationMappings);
        self::assertArrayHasKey('groups', $adminMetadata->associationMappings);

        $guestGroups = $guestMetadata->associationMappings['groups'];
        $adminGroups = $adminMetadata->associationMappings['groups'];

        // assert not override attributes
        self::assertEquals($guestGroups->getName(), $adminGroups->getName());
        self::assertEquals(get_class($guestGroups), get_class($adminGroups));
        self::assertEquals($guestGroups->getMappedBy(), $adminGroups->getMappedBy());
        self::assertEquals($guestGroups->getInversedBy(), $adminGroups->getInversedBy());
        self::assertEquals($guestGroups->isOwningSide(), $adminGroups->isOwningSide());
        self::assertEquals($guestGroups->getFetchMode(), $adminGroups->getFetchMode());
        self::assertEquals($guestGroups->getCascade(), $adminGroups->getCascade());

         // assert not override attributes
        $guestGroupsJoinTable          = $guestGroups->getJoinTable();
        $guestGroupsJoinColumns        = $guestGroupsJoinTable->getJoinColumns();
        $guestGroupsJoinColumn         = reset($guestGroupsJoinColumns);
        $guestGroupsInverseJoinColumns = $guestGroupsJoinTable->getInverseJoinColumns();
        $guestGroupsInverseJoinColumn  = reset($guestGroupsInverseJoinColumns);

        self::assertEquals('ddc964_users_groups', $guestGroupsJoinTable->getName());
        self::assertEquals('user_id', $guestGroupsJoinColumn->getColumnName());
        self::assertEquals('group_id', $guestGroupsInverseJoinColumn->getColumnName());

        $adminGroupsJoinTable          = $adminGroups->getJoinTable();
        $adminGroupsJoinColumns        = $adminGroupsJoinTable->getJoinColumns();
        $adminGroupsJoinColumn         = reset($adminGroupsJoinColumns);
        $adminGroupsInverseJoinColumns = $adminGroupsJoinTable->getInverseJoinColumns();
        $adminGroupsInverseJoinColumn  = reset($adminGroupsInverseJoinColumns);

        self::assertEquals('ddc964_users_admingroups', $adminGroupsJoinTable->getName());
        self::assertEquals('adminuser_id', $adminGroupsJoinColumn->getColumnName());
        self::assertEquals('admingroup_id', $adminGroupsInverseJoinColumn->getColumnName());

        // assert address association mappings
        self::assertArrayHasKey('address', $guestMetadata->associationMappings);
        self::assertArrayHasKey('address', $adminMetadata->associationMappings);

        $guestAddress = $guestMetadata->associationMappings['address'];
        $adminAddress = $adminMetadata->associationMappings['address'];

        // assert not override attributes
        self::assertEquals($guestAddress->getName(), $adminAddress->getName());
        self::assertEquals(get_class($guestAddress), get_class($adminAddress));
        self::assertEquals($guestAddress->getMappedBy(), $adminAddress->getMappedBy());
        self::assertEquals($guestAddress->getInversedBy(), $adminAddress->getInversedBy());
        self::assertEquals($guestAddress->isOwningSide(), $adminAddress->isOwningSide());
        self::assertEquals($guestAddress->getFetchMode(), $adminAddress->getFetchMode());
        self::assertEquals($guestAddress->getCascade(), $adminAddress->getCascade());

        // assert override
        $guestAddressJoinColumns = $guestAddress->getJoinColumns();
        $guestAddressJoinColumn  = reset($guestAddressJoinColumns);

        self::assertEquals('address_id', $guestAddressJoinColumn->getColumnName());

        $adminAddressJoinColumns = $adminAddress->getJoinColumns();
        $adminAddressJoinColumn  = reset($adminAddressJoinColumns);

        self::assertEquals('adminaddress_id', $adminAddressJoinColumn->getColumnName());
    }

    /*
     * @group DDC-3579
     */
    public function testInversedByOverrideMapping()
    {
        $factory        = $this->createClassMetadataFactory();
        $adminMetadata  = $factory->getMetadataFor(DDC3579Admin::class);

        // assert groups association mappings
        self::assertArrayHasKey('groups', $adminMetadata->associationMappings);

        $adminGroups = $adminMetadata->associationMappings['groups'];

        // assert override
        self::assertEquals('admins', $adminGroups->getInversedBy());
    }

    /**
     * @group DDC-964
     */
    public function testAttributeOverridesMapping()
    {
        $factory       = $this->createClassMetadataFactory();
        $adminMetadata = $factory->getMetadataFor(DDC964Admin::class);

        self::assertEquals(
            [
                'user_id' => 'id',
                'user_name' => 'name',
                'adminaddress_id' => 'address',
            ],
            $adminMetadata->fieldNames
        );

        self::assertNotNull($adminMetadata->getProperty('id'));

        $idProperty = $adminMetadata->getProperty('id');

        self::assertTrue($idProperty->isPrimaryKey());
        self::assertEquals('id', $idProperty->getName());
        self::assertEquals('user_id', $idProperty->getColumnName());

        self::assertNotNull($adminMetadata->getProperty('name'));

        $nameProperty = $adminMetadata->getProperty('name');

        self::assertEquals('name', $nameProperty->getName());
        self::assertEquals('user_name', $nameProperty->getColumnName());
        self::assertEquals(250, $nameProperty->getLength());
        self::assertTrue($nameProperty->isNullable());
        self::assertFalse($nameProperty->isUnique());

        $guestMetadata = $factory->getMetadataFor(DDC964Guest::class);

        self::assertEquals(
            [
                'guest_id' => 'id',
                'guest_name' => 'name',
            ],
            $guestMetadata->fieldNames
        );

        self::assertNotNull($guestMetadata->getProperty('id'));

        $idProperty = $guestMetadata->getProperty('id');

        self::assertTrue($idProperty->isPrimaryKey());
        self::assertEquals('id', $idProperty->getName());
        self::assertEquals('guest_id', $idProperty->getColumnName());

        self::assertNotNull($guestMetadata->getProperty('name'));

        $nameProperty = $guestMetadata->getProperty('name');

        self::assertEquals('name', $nameProperty->getName());
        self::assertEquals('guest_name', $nameProperty->getColumnName());
        self::assertEquals(240, $nameProperty->getLength());
        self::assertFalse($nameProperty->isNullable());
        self::assertTrue($nameProperty->isUnique());
    }

    /**
     * @group DDC-1955
     */
    public function testEntityListeners()
    {
        $factory    = $this->createClassMetadataFactory();
        $superClass = $factory->getMetadataFor(CompanyContract::class);
        $flexClass  = $factory->getMetadataFor(CompanyFixContract::class);
        $fixClass   = $factory->getMetadataFor(CompanyFlexContract::class);

        self::assertArrayHasKey(Events::prePersist, $superClass->entityListeners);
        self::assertArrayHasKey(Events::postPersist, $superClass->entityListeners);

        self::assertCount(1, $superClass->entityListeners[Events::prePersist]);
        self::assertCount(1, $superClass->entityListeners[Events::postPersist]);

        $postPersist = $superClass->entityListeners[Events::postPersist][0];
        $prePersist  = $superClass->entityListeners[Events::prePersist][0];

        self::assertEquals(CompanyContractListener::class, $postPersist['class']);
        self::assertEquals(CompanyContractListener::class, $prePersist['class']);
        self::assertEquals('postPersistHandler', $postPersist['method']);
        self::assertEquals('prePersistHandler', $prePersist['method']);

        //Inherited listeners
        self::assertEquals($fixClass->entityListeners, $superClass->entityListeners);
        self::assertEquals($flexClass->entityListeners, $superClass->entityListeners);
    }

    /**
     * @group DDC-1955
     */
    public function testEntityListenersOverride()
    {
        $factory    = $this->createClassMetadataFactory();
        $ultraClass = $factory->getMetadataFor(CompanyFlexUltraContract::class);

        //overridden listeners
        self::assertArrayHasKey(Events::postPersist, $ultraClass->entityListeners);
        self::assertArrayHasKey(Events::prePersist, $ultraClass->entityListeners);

        self::assertCount(1, $ultraClass->entityListeners[Events::postPersist]);
        self::assertCount(3, $ultraClass->entityListeners[Events::prePersist]);

        $postPersist = $ultraClass->entityListeners[Events::postPersist][0];
        $prePersist  = $ultraClass->entityListeners[Events::prePersist][0];

        self::assertEquals(CompanyContractListener::class, $postPersist['class']);
        self::assertEquals(CompanyContractListener::class, $prePersist['class']);
        self::assertEquals('postPersistHandler', $postPersist['method']);
        self::assertEquals('prePersistHandler', $prePersist['method']);

        $prePersist = $ultraClass->entityListeners[Events::prePersist][1];
        self::assertEquals(CompanyFlexUltraContractListener::class, $prePersist['class']);
        self::assertEquals('prePersistHandler1', $prePersist['method']);

        $prePersist = $ultraClass->entityListeners[Events::prePersist][2];
        self::assertEquals(CompanyFlexUltraContractListener::class, $prePersist['class']);
        self::assertEquals('prePersistHandler2', $prePersist['method']);
    }


    /**
     * @group DDC-1955
     */
    public function testEntityListenersNamingConvention()
    {
        $factory  = $this->createClassMetadataFactory();
        $metadata = $factory->getMetadataFor(CmsAddress::class);

        self::assertArrayHasKey(Events::postPersist, $metadata->entityListeners);
        self::assertArrayHasKey(Events::prePersist, $metadata->entityListeners);
        self::assertArrayHasKey(Events::postUpdate, $metadata->entityListeners);
        self::assertArrayHasKey(Events::preUpdate, $metadata->entityListeners);
        self::assertArrayHasKey(Events::postRemove, $metadata->entityListeners);
        self::assertArrayHasKey(Events::preRemove, $metadata->entityListeners);
        self::assertArrayHasKey(Events::postLoad, $metadata->entityListeners);
        self::assertArrayHasKey(Events::preFlush, $metadata->entityListeners);

        self::assertCount(1, $metadata->entityListeners[Events::postPersist]);
        self::assertCount(1, $metadata->entityListeners[Events::prePersist]);
        self::assertCount(1, $metadata->entityListeners[Events::postUpdate]);
        self::assertCount(1, $metadata->entityListeners[Events::preUpdate]);
        self::assertCount(1, $metadata->entityListeners[Events::postRemove]);
        self::assertCount(1, $metadata->entityListeners[Events::preRemove]);
        self::assertCount(1, $metadata->entityListeners[Events::postLoad]);
        self::assertCount(1, $metadata->entityListeners[Events::preFlush]);

        $postPersist = $metadata->entityListeners[Events::postPersist][0];
        $prePersist  = $metadata->entityListeners[Events::prePersist][0];
        $postUpdate  = $metadata->entityListeners[Events::postUpdate][0];
        $preUpdate   = $metadata->entityListeners[Events::preUpdate][0];
        $postRemove  = $metadata->entityListeners[Events::postRemove][0];
        $preRemove   = $metadata->entityListeners[Events::preRemove][0];
        $postLoad    = $metadata->entityListeners[Events::postLoad][0];
        $preFlush    = $metadata->entityListeners[Events::preFlush][0];

        self::assertEquals(CmsAddressListener::class, $postPersist['class']);
        self::assertEquals(CmsAddressListener::class, $prePersist['class']);
        self::assertEquals(CmsAddressListener::class, $postUpdate['class']);
        self::assertEquals(CmsAddressListener::class, $preUpdate['class']);
        self::assertEquals(CmsAddressListener::class, $postRemove['class']);
        self::assertEquals(CmsAddressListener::class, $preRemove['class']);
        self::assertEquals(CmsAddressListener::class, $postLoad['class']);
        self::assertEquals(CmsAddressListener::class, $preFlush['class']);

        self::assertEquals(Events::postPersist, $postPersist['method']);
        self::assertEquals(Events::prePersist, $prePersist['method']);
        self::assertEquals(Events::postUpdate, $postUpdate['method']);
        self::assertEquals(Events::preUpdate, $preUpdate['method']);
        self::assertEquals(Events::postRemove, $postRemove['method']);
        self::assertEquals(Events::preRemove, $preRemove['method']);
        self::assertEquals(Events::postLoad, $postLoad['method']);
        self::assertEquals(Events::preFlush, $preFlush['method']);
    }

    /**
     * @group DDC-2183
     */
    public function testSecondLevelCacheMapping()
    {
        $factory = $this->createClassMetadataFactory();
        $class   = $factory->getMetadataFor(City::class);

        self::assertNotNull($class->cache);
        self::assertEquals(Mapping\CacheUsage::READ_ONLY, $class->cache->getUsage());
        self::assertEquals('doctrine_tests_models_cache_city', $class->cache->getRegion());

        self::assertArrayHasKey('state', $class->associationMappings);

        $stateAssociation = $class->associationMappings['state'];

        self::assertNotNull($stateAssociation->getCache());
        self::assertEquals(Mapping\CacheUsage::READ_ONLY, $stateAssociation->getCache()->getUsage());
        self::assertEquals('doctrine_tests_models_cache_city__state', $stateAssociation->getCache()->getRegion());

        self::assertArrayHasKey('attractions', $class->associationMappings);

        $attractionsAssociation = $class->associationMappings['attractions'];

        self::assertNotNull($attractionsAssociation->getCache());
        self::assertEquals(Mapping\CacheUsage::READ_ONLY, $attractionsAssociation->getCache()->getUsage());
        self::assertEquals('doctrine_tests_models_cache_city__attractions', $attractionsAssociation->getCache()->getRegion());
    }

    /**
     * @group DDC-2825
     * @group 881
     */
    public function testSchemaDefinitionViaExplicitTableSchemaAnnotationProperty()
    {
        $factory  = $this->createClassMetadataFactory();
        $metadata = $factory->getMetadataFor(ExplicitSchemaAndTable::class);

        self::assertSame('explicit_schema', $metadata->getSchemaName());
        self::assertSame('explicit_table', $metadata->getTableName());
    }

    /**
     * @group DDC-2825
     * @group 881
     */
    public function testSchemaDefinitionViaSchemaDefinedInTableNameInTableAnnotationProperty()
    {
        $factory  = $this->createClassMetadataFactory();
        $metadata = $factory->getMetadataFor(SchemaAndTableInTableName::class);

        self::assertSame('implicit_schema', $metadata->getSchemaName());
        self::assertSame('implicit_table', $metadata->getTableName());
    }

    /**
     * @group DDC-514
     * @group DDC-1015
     */
    public function testDiscriminatorColumnDefaultLength()
    {
        if (strpos(get_class($this), 'PHPMappingDriver') !== false) {
            $this->markTestSkipped('PHP Mapping Drivers have no defaults.');
        }

        $class = $this->createClassMetadata(SingleTableEntityNoDiscriminatorColumnMapping::class);

        self::assertEquals(255, $class->discriminatorColumn->getLength());

        $class = $this->createClassMetadata(SingleTableEntityIncompleteDiscriminatorColumnMapping::class);

        self::assertEquals(255, $class->discriminatorColumn->getLength());
    }

    /**
     * @group DDC-514
     * @group DDC-1015
     */
    public function testDiscriminatorColumnDefaultType()
    {
        if (strpos(get_class($this), 'PHPMappingDriver') !== false) {
            $this->markTestSkipped('PHP Mapping Drivers have no defaults.');
        }

        $class = $this->createClassMetadata(SingleTableEntityNoDiscriminatorColumnMapping::class);

        self::assertEquals('string', $class->discriminatorColumn->getTypeName());

        $class = $this->createClassMetadata(SingleTableEntityIncompleteDiscriminatorColumnMapping::class);

        self::assertEquals('string', $class->discriminatorColumn->getTypeName());
    }

    /**
     * @group DDC-514
     * @group DDC-1015
     */
    public function testDiscriminatorColumnDefaultName()
    {
        if (strpos(get_class($this), 'PHPMappingDriver') !== false) {
            $this->markTestSkipped('PHP Mapping Drivers have no defaults.');
        }

        $class = $this->createClassMetadata(SingleTableEntityNoDiscriminatorColumnMapping::class);

        self::assertEquals('dtype', $class->discriminatorColumn->getColumnName());

        $class = $this->createClassMetadata(SingleTableEntityIncompleteDiscriminatorColumnMapping::class);

        self::assertEquals('dtype', $class->discriminatorColumn->getColumnName());
    }
}

/**
 * @ORM\Entity
 * @ORM\HasLifecycleCallbacks
 * @ORM\Table(
 *  name="cms_users",
 *  uniqueConstraints={@ORM\UniqueConstraint(name="search_idx", columns={"name", "user_email"})},
 *  indexes={@ORM\Index(name="name_idx", columns={"name"}), @ORM\Index(columns={"user_email"})},
 *  options={"foo": "bar", "baz": {"key": "val"}}
 * )
 * @ORM\NamedQueries({@ORM\NamedQuery(name="all", query="SELECT u FROM __CLASS__ u")})
 */
class User
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer", options={"foo": "bar", "unsigned": false})
     * @ORM\GeneratedValue(strategy="AUTO")
     * @ORM\SequenceGenerator(sequenceName="tablename_seq", allocationSize=100)
     **/
    public $id;

    /**
     * @ORM\Column(length=50, nullable=true, unique=true, options={"foo": "bar", "baz": {"key": "val"}, "fixed": false})
     */
    public $name;

    /**
     * @ORM\Column(name="user_email", columnDefinition="CHAR(32) NOT NULL")
     */
    public $email;

    /**
     * @ORM\OneToOne(targetEntity="Address", cascade={"remove"}, inversedBy="user")
     * @ORM\JoinColumn(onDelete="CASCADE")
     */
    public $address;

    /**
     * @ORM\OneToMany(targetEntity="Phonenumber", mappedBy="user", cascade={"persist"}, orphanRemoval=true)
     * @ORM\OrderBy({"number"="ASC"})
     */
    public $phonenumbers;

    /**
     * @ORM\ManyToMany(targetEntity="Group", cascade={"all"})
     * @ORM\JoinTable(name="cms_user_groups",
     *    joinColumns={@ORM\JoinColumn(name="user_id", referencedColumnName="id", nullable=false, unique=false)},
     *    inverseJoinColumns={@ORM\JoinColumn(name="group_id", referencedColumnName="id", columnDefinition="INT NULL")}
     * )
     */
    public $groups;

    /**
     * @ORM\Column(type="integer")
     * @ORM\Version
     */
    public $version;


    /**
     * @ORM\PrePersist
     */
    public function doStuffOnPrePersist()
    {
    }

    /**
     * @ORM\PrePersist
     */
    public function doOtherStuffOnPrePersistToo() {
    }

    /**
     * @ORM\PostPersist
     */
    public function doStuffOnPostPersist()
    {

    }

    public static function loadMetadata(ClassMetadata $metadata)
    {
        $tableMetadata = new Mapping\TableMetadata();

        $tableMetadata->setName('cms_users');
        $tableMetadata->addIndex(
            [
                'name'    => 'name_idx',
                'columns' => ['name'],
                'unique'  => false,
                'options' => [],
                'flags'   => [],
            ]
        );

        $tableMetadata->addIndex(
            [
                'name'    => null,
                'columns' => ['user_email'],
                'unique'  => false,
                'options' => [],
                'flags'   => [],
            ]
        );

        $tableMetadata->addUniqueConstraint(
            [
                'name'    => 'search_idx',
                'columns' => ['name', 'user_email'],
                'options' => [],
                'flags'   => [],
            ]
        );
        $tableMetadata->addOption('foo', 'bar');
        $tableMetadata->addOption('baz', ['key' => 'val']);

        $metadata->setPrimaryTable($tableMetadata);
        $metadata->setInheritanceType(Mapping\InheritanceType::NONE);
        $metadata->setChangeTrackingPolicy(Mapping\ChangeTrackingPolicy::DEFERRED_IMPLICIT);

        $metadata->addLifecycleCallback('doStuffOnPrePersist', 'prePersist');
        $metadata->addLifecycleCallback('doOtherStuffOnPrePersistToo', 'prePersist');
        $metadata->addLifecycleCallback('doStuffOnPostPersist', 'postPersist');

        $metadata->setGeneratorDefinition(
            [
                'sequenceName'   => 'tablename_seq',
                'allocationSize' => 100,
            ]
        );

        $metadata->addNamedQuery(
            [
                'name' => 'all',
                'query' => 'SELECT u FROM __CLASS__ u'
            ]
        );

        $fieldMetadata = new Mapping\FieldMetadata('id');
        $fieldMetadata->setType(Type::getType('integer'));
        $fieldMetadata->setPrimaryKey(true);
        $fieldMetadata->setOptions(['foo' => 'bar', 'unsigned' => false]);

        $metadata->addProperty($fieldMetadata);

        $fieldMetadata = new Mapping\FieldMetadata('name');
        $fieldMetadata->setType(Type::getType('string'));
        $fieldMetadata->setLength(50);
        $fieldMetadata->setNullable(true);
        $fieldMetadata->setUnique(true);
        $fieldMetadata->setOptions(
            [
                'foo' => 'bar',
                'baz' => [
                    'key' => 'val',
                ],
                'fixed' => false,
            ]
        );

        $metadata->addProperty($fieldMetadata);

        $fieldMetadata = new Mapping\FieldMetadata('email');

        $fieldMetadata->setType(Type::getType('string'));
        $fieldMetadata->setColumnName('user_email');
        $fieldMetadata->setColumnDefinition('CHAR(32) NOT NULL');

        $metadata->addProperty($fieldMetadata);

        $fieldMetadata = new Mapping\VersionFieldMetadata('version');

        $fieldMetadata->setType(Type::getType('integer'));

        $metadata->addProperty($fieldMetadata);
        $metadata->setVersionProperty($fieldMetadata);
        $metadata->setIdGeneratorType(Mapping\GeneratorType::AUTO);

        $joinColumns = [];

        $joinColumn = new Mapping\JoinColumnMetadata();

        $joinColumn->setColumnName('address_id');
        $joinColumn->setReferencedColumnName('id');
        $joinColumn->setOnDelete('CASCADE');

        $joinColumns[] = $joinColumn;

        $association = new Mapping\OneToOneAssociationMetadata('address');

        $association->setJoinColumns($joinColumns);
        $association->setTargetEntity(Address::class);
        $association->setInversedBy('user');
        $association->setCascade(['remove']);
        $association->setOrphanRemoval(false);

        $metadata->addAssociation($association);

        $association = new Mapping\OneToManyAssociationMetadata('phonenumbers');

        $association->setTargetEntity(Phonenumber::class);
        $association->setMappedBy('user');
        $association->setCascade(['persist']);
        $association->setOrderBy(['number' => 'ASC']);
        $association->setOrphanRemoval(true);

        $metadata->addAssociation($association);

        $joinTable = new Mapping\JoinTableMetadata();
        $joinTable->setName('cms_users_groups');

        $joinColumn = new Mapping\JoinColumnMetadata();

        $joinColumn->setColumnName('user_id');
        $joinColumn->setReferencedColumnName('id');
        $joinColumn->setNullable(false);
        $joinColumn->setUnique(false);

        $joinTable->addJoinColumn($joinColumn);

        $joinColumn = new Mapping\JoinColumnMetadata();

        $joinColumn->setColumnName('group_id');
        $joinColumn->setReferencedColumnName('id');
        $joinColumn->setColumnDefinition('INT NULL');

        $joinTable->addInverseJoinColumn($joinColumn);

        $association = new Mapping\ManyToManyAssociationMetadata('groups');

        $association->setJoinTable($joinTable);
        $association->setTargetEntity(Group::class);
        $association->setCascade(['remove', 'persist', 'refresh', 'merge', 'detach']);

        $metadata->addAssociation($association);
    }
}

/**
 * @ORM\Entity
 * @ORM\InheritanceType("SINGLE_TABLE")
 * @ORM\DiscriminatorMap({"cat" = "Cat", "dog" = "Dog"})
 * @ORM\DiscriminatorColumn(name="discr", length=32, type="string")
 */
abstract class Animal
{
    /**
     * @ORM\Id @ORM\Column(type="string") @ORM\GeneratedValue(strategy="CUSTOM")
     * @ORM\CustomIdGenerator(class="stdClass")
     */
    public $id;

    public static function loadMetadata(ClassMetadata $metadata)
    {
        $metadata->setIdGeneratorType(Mapping\GeneratorType::CUSTOM);
        $metadata->setGeneratorDefinition(
            [
                'class'     => 'stdClass',
                'arguments' => [],
            ]
        );
    }
}

/** @ORM\Entity */
class Cat extends Animal
{
    public static function loadMetadata(ClassMetadata $metadata)
    {

    }
}

/** @ORM\Entity */
class Dog extends Animal
{
    public static function loadMetadata(ClassMetadata $metadata)
    {

    }
}

/**
 * @ORM\Entity
 */
class DDC1170Entity
{
    /**
     * @param string $value
     */
    public function __construct($value = null)
    {
        $this->value = $value;
    }

    /**
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="NONE")
     * @ORM\Column(type="integer", columnDefinition = "INT unsigned NOT NULL")
     **/
    private $id;

    /**
     * @ORM\Column(columnDefinition = "VARCHAR(255) NOT NULL")
     */
    private $value;

    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getValue()
    {
        return $this->value;
    }

    public static function loadMetadata(ClassMetadata $metadata)
    {
        $fieldMetadata = new Mapping\FieldMetadata('id');

        $fieldMetadata->setType(Type::getType('integer'));
        $fieldMetadata->setColumnDefinition('INT unsigned NOT NULL');
        $fieldMetadata->setPrimaryKey(true);

        $metadata->addProperty($fieldMetadata);

        $fieldMetadata = new Mapping\FieldMetadata('value');

        $fieldMetadata->setType(Type::getType('string'));
        $fieldMetadata->setColumnDefinition('VARCHAR(255) NOT NULL');

        $metadata->addProperty($fieldMetadata);

        $metadata->setIdGeneratorType(Mapping\GeneratorType::NONE);
    }

}

/**
 * @ORM\Entity
 * @ORM\InheritanceType("SINGLE_TABLE")
 * @ORM\DiscriminatorMap({"ONE" = "DDC807SubClasse1", "TWO" = "DDC807SubClasse2"})
 * @ORM\DiscriminatorColumn(name = "dtype", columnDefinition="ENUM('ONE','TWO')")
 */
class DDC807Entity
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="NONE")
     **/
    public $id;

    public static function loadMetadata(ClassMetadata $metadata)
    {
        $fieldMetadata = new Mapping\FieldMetadata('id');

        $fieldMetadata->setType(Type::getType('string'));
        $fieldMetadata->setPrimaryKey(true);

        $metadata->addProperty($fieldMetadata);

        $discrColumn = new Mapping\DiscriminatorColumnMetadata();

        $discrColumn->setTableName($metadata->getTableName());
        $discrColumn->setColumnName('dtype');
        $discrColumn->setType(Type::getType('string'));
        $discrColumn->setColumnDefinition("ENUM('ONE','TWO')");

        $metadata->setDiscriminatorColumn($discrColumn);

        $metadata->setIdGeneratorType(Mapping\GeneratorType::NONE);
    }
}

class DDC807SubClasse1 {}
class DDC807SubClasse2 {}

class Address {}
class Phonenumber {}
class Group {}

/**
 * @ORM\Entity
 * @ORM\Table(indexes={@ORM\Index(columns={"content"}, flags={"fulltext"}, options={"where": "content IS NOT NULL"})})
 */
class Comment
{
    /**
     * @ORM\Column(type="text")
     */
    private $content;

    public static function loadMetadata(ClassMetadata $metadata)
    {
        $tableMetadata = new Mapping\TableMetadata();

        $tableMetadata->addIndex(
            [
                'name'    => null,
                'unique'  => false,
                'columns' => ['content'],
                'flags'   => ['fulltext'],
                'options' => ['where' => 'content IS NOT NULL'],
            ]
        );

        $metadata->setPrimaryTable($tableMetadata);
        $metadata->setInheritanceType(Mapping\InheritanceType::NONE);

        $fieldMetadata = new Mapping\FieldMetadata('content');
        $fieldMetadata->setType(Type::getType('text'));
        $fieldMetadata->setNullable(false);
        $fieldMetadata->setUnique(false);

        $metadata->addProperty($fieldMetadata);
    }
}

/**
 * @ORM\Entity
 * @ORM\InheritanceType("SINGLE_TABLE")
 * @ORM\DiscriminatorMap({
 *     "ONE" = "SingleTableEntityNoDiscriminatorColumnMappingSub1",
 *     "TWO" = "SingleTableEntityNoDiscriminatorColumnMappingSub2"
 * })
 */
class SingleTableEntityNoDiscriminatorColumnMapping
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="NONE")
     */
    public $id;

    public static function loadMetadata(ClassMetadata $metadata)
    {
        $fieldMetadata = new Mapping\FieldMetadata('id');
        $fieldMetadata->setType(Type::getType('string'));
        $fieldMetadata->setPrimaryKey(true);

        $metadata->addProperty($fieldMetadata);

        $metadata->setIdGeneratorType(Mapping\GeneratorType::NONE);
    }
}

/**
 * @ORM\Entity
 */
class SingleTableEntityNoDiscriminatorColumnMappingSub1 extends SingleTableEntityNoDiscriminatorColumnMapping {}

/**
 * @ORM\Entity
 */
class SingleTableEntityNoDiscriminatorColumnMappingSub2 extends SingleTableEntityNoDiscriminatorColumnMapping {}

/**
 * @ORM\Entity
 * @ORM\InheritanceType("SINGLE_TABLE")
 * @ORM\DiscriminatorMap({
 *     "ONE" = "SingleTableEntityIncompleteDiscriminatorColumnMappingSub1",
 *     "TWO" = "SingleTableEntityIncompleteDiscriminatorColumnMappingSub2"
 * })
 * @ORM\DiscriminatorColumn(name="dtype")
 */
class SingleTableEntityIncompleteDiscriminatorColumnMapping
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="NONE")
     */
    public $id;

    public static function loadMetadata(ClassMetadata $metadata)
    {
        // @todo: String != Integer and this should not work
        $fieldMetadata = new Mapping\FieldMetadata('id');
        $fieldMetadata->setType(Type::getType('string'));
        $fieldMetadata->setPrimaryKey(true);

        $metadata->addProperty($fieldMetadata);

        $metadata->setIdGeneratorType(Mapping\GeneratorType::NONE);
    }
}

class SingleTableEntityIncompleteDiscriminatorColumnMappingSub1
    extends SingleTableEntityIncompleteDiscriminatorColumnMapping {}

class SingleTableEntityIncompleteDiscriminatorColumnMappingSub2
    extends SingleTableEntityIncompleteDiscriminatorColumnMapping {}
