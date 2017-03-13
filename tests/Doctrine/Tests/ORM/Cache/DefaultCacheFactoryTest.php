<?php

namespace Doctrine\Tests\ORM\Cache;

use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\ORM\Cache\CacheFactory;
use Doctrine\ORM\Cache\DefaultCacheFactory;
use Doctrine\ORM\Cache\Persister\Collection\CachedCollectionPersister;
use Doctrine\ORM\Cache\Persister\Collection\NonStrictReadWriteCachedCollectionPersister;
use Doctrine\ORM\Cache\Persister\Collection\ReadOnlyCachedCollectionPersister;
use Doctrine\ORM\Cache\Persister\Collection\ReadWriteCachedCollectionPersister;
use Doctrine\ORM\Cache\Persister\Entity\CachedEntityPersister;
use Doctrine\ORM\Cache\Persister\Entity\NonStrictReadWriteCachedEntityPersister;
use Doctrine\ORM\Cache\Persister\Entity\ReadOnlyCachedEntityPersister;
use Doctrine\ORM\Cache\Persister\Entity\ReadWriteCachedEntityPersister;
use Doctrine\ORM\Cache\Region\DefaultMultiGetRegion;
use Doctrine\ORM\Cache\Region\DefaultRegion;
use Doctrine\ORM\Cache\RegionsConfiguration;
use Doctrine\ORM\Mapping\CacheMetadata;
use Doctrine\ORM\Mapping\CacheUsage;
use Doctrine\ORM\Persisters\Collection\OneToManyPersister;
use Doctrine\ORM\Persisters\Entity\BasicEntityPersister;
use Doctrine\Tests\Mocks\ConcurrentRegionMock;
use Doctrine\Tests\Models\Cache\AttractionContactInfo;
use Doctrine\Tests\Models\Cache\AttractionLocationInfo;
use Doctrine\Tests\Models\Cache\City;
use Doctrine\Tests\Models\Cache\State;
use Doctrine\Tests\OrmTestCase;

/**
 * @group DDC-2183
 */
class DefaultCacheFactoryTest extends OrmTestCase
{
    /**
     * @var \Doctrine\ORM\Cache\CacheFactory
     */
    private $factory;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    private $em;

    /**
     * @var \Doctrine\ORM\Cache\RegionsConfiguration
     */
    private $regionsConfig;

    protected function setUp()
    {
        $this->enableSecondLevelCache();
        
        parent::setUp();

        $this->em            = $this->getTestEntityManager();
        $this->regionsConfig = new RegionsConfiguration;
        
        $arguments = [
            $this->regionsConfig, 
            $this->getSharedSecondLevelCacheDriverImpl()
        ];
        
        $this->factory = $this->getMockBuilder(DefaultCacheFactory::class)
            ->setMethods(['getRegion'])
            ->setConstructorArgs($arguments)
            ->getMock()
        ;
    }

    public function testImplementsCacheFactory()
    {
        self::assertInstanceOf(CacheFactory::class, $this->factory);
    }

    public function testBuildCachedEntityPersisterReadOnly()
    {
        $em         = $this->em;
        $metadata   = clone $em->getClassMetadata(State::class);
        $persister  = new BasicEntityPersister($em, $metadata);
        $region     = new ConcurrentRegionMock(
            new DefaultRegion('regionName', $this->getSharedSecondLevelCacheDriverImpl())
        );

        $metadata->setCache(
            new CacheMetadata(CacheUsage::READ_ONLY, 'doctrine_tests_models_cache_state')
        );

        $this->factory->expects($this->once())
            ->method('getRegion')
            ->with($this->equalTo($metadata->cache))
            ->will($this->returnValue($region));

        $cachedPersister = $this->factory->buildCachedEntityPersister($em, $persister, $metadata);

        self::assertInstanceOf(CachedEntityPersister::class, $cachedPersister);
        self::assertInstanceOf(ReadOnlyCachedEntityPersister::class, $cachedPersister);
    }

    public function testBuildCachedEntityPersisterReadWrite()
    {
        $em        = $this->em;
        $metadata  = clone $em->getClassMetadata(State::class);
        $persister = new BasicEntityPersister($em, $metadata);
        $region    = new ConcurrentRegionMock(
            new DefaultRegion('regionName', $this->getSharedSecondLevelCacheDriverImpl())
        );

        $metadata->setCache(
            new CacheMetadata(CacheUsage::READ_WRITE, 'doctrine_tests_models_cache_state')
        );

        $this->factory->expects($this->once())
            ->method('getRegion')
            ->with($this->equalTo($metadata->cache))
            ->will($this->returnValue($region));

        $cachedPersister = $this->factory->buildCachedEntityPersister($em, $persister, $metadata);

        self::assertInstanceOf(CachedEntityPersister::class, $cachedPersister);
        self::assertInstanceOf(ReadWriteCachedEntityPersister::class, $cachedPersister);
    }

    public function testBuildCachedEntityPersisterNonStrictReadWrite()
    {
        $em         = $this->em;
        $metadata   = clone $em->getClassMetadata(State::class);
        $persister  = new BasicEntityPersister($em, $metadata);
        $region     = new ConcurrentRegionMock(
            new DefaultRegion('regionName', $this->getSharedSecondLevelCacheDriverImpl())
        );

        $metadata->setCache(
            new CacheMetadata(CacheUsage::NONSTRICT_READ_WRITE, 'doctrine_tests_models_cache_state')
        );

        $this->factory->expects($this->once())
            ->method('getRegion')
            ->with($this->equalTo($metadata->cache))
            ->will($this->returnValue($region));

        $cachedPersister = $this->factory->buildCachedEntityPersister($em, $persister, $metadata);

        self::assertInstanceOf(CachedEntityPersister::class, $cachedPersister);
        self::assertInstanceOf(NonStrictReadWriteCachedEntityPersister::class, $cachedPersister);
    }

    public function testBuildCachedCollectionPersisterReadOnly()
    {
        $em          = $this->em;
        $metadata    = clone $em->getClassMetadata(State::class);
        $association = $metadata->associationMappings['cities'];
        $persister   = new OneToManyPersister($em);
        $region      = new ConcurrentRegionMock(
            new DefaultRegion('regionName', $this->getSharedSecondLevelCacheDriverImpl())
        );

        $association->setCache(
            new CacheMetadata(CacheUsage::READ_ONLY, 'doctrine_tests_models_cache_state__cities')
        );

        $this->factory->expects($this->once())
            ->method('getRegion')
            ->with($this->equalTo($association->getCache()))
            ->will($this->returnValue($region));


        $cachedPersister = $this->factory->buildCachedCollectionPersister($em, $persister, $association);

        self::assertInstanceOf(CachedCollectionPersister::class, $cachedPersister);
        self::assertInstanceOf(ReadOnlyCachedCollectionPersister::class, $cachedPersister);
    }

    public function testBuildCachedCollectionPersisterReadWrite()
    {
        $em         = $this->em;
        $metadata   = clone $em->getClassMetadata(State::class);
        $association    = $metadata->associationMappings['cities'];
        $persister  = new OneToManyPersister($em);
        $region     = new ConcurrentRegionMock(
            new DefaultRegion('regionName', $this->getSharedSecondLevelCacheDriverImpl())
        );

        $association->setCache(
            new CacheMetadata(CacheUsage::READ_WRITE, 'doctrine_tests_models_cache_state__cities')
        );

        $this->factory->expects($this->once())
            ->method('getRegion')
            ->with($this->equalTo($association->getCache()))
            ->will($this->returnValue($region));

        $cachedPersister = $this->factory->buildCachedCollectionPersister($em, $persister, $association);

        self::assertInstanceOf(CachedCollectionPersister::class, $cachedPersister);
        self::assertInstanceOf(ReadWriteCachedCollectionPersister::class, $cachedPersister);
    }

    public function testBuildCachedCollectionPersisterNonStrictReadWrite()
    {
        $em          = $this->em;
        $metadata    = clone $em->getClassMetadata(State::class);
        $association = $metadata->associationMappings['cities'];
        $persister   = new OneToManyPersister($em);
        $region      = new ConcurrentRegionMock(
            new DefaultRegion('regionName', $this->getSharedSecondLevelCacheDriverImpl())
        );

        $association->setCache(
            new CacheMetadata(CacheUsage::NONSTRICT_READ_WRITE, 'doctrine_tests_models_cache_state__cities')
        );

        $this->factory->expects($this->once())
            ->method('getRegion')
            ->with($this->equalTo($association->getCache()))
            ->will($this->returnValue($region));

        $cachedPersister = $this->factory->buildCachedCollectionPersister($em, $persister, $association);

        self::assertInstanceOf(CachedCollectionPersister::class, $cachedPersister);
        self::assertInstanceOf(NonStrictReadWriteCachedCollectionPersister::class, $cachedPersister);
    }

    public function testInheritedEntityCacheRegion()
    {
        $em         = $this->em;
        $metadata1  = clone $em->getClassMetadata(AttractionContactInfo::class);
        $metadata2  = clone $em->getClassMetadata(AttractionLocationInfo::class);
        $persister1 = new BasicEntityPersister($em, $metadata1);
        $persister2 = new BasicEntityPersister($em, $metadata2);
        $factory    = new DefaultCacheFactory($this->regionsConfig, $this->getSharedSecondLevelCacheDriverImpl());

        $cachedPersister1 = $factory->buildCachedEntityPersister($em, $persister1, $metadata1);
        $cachedPersister2 = $factory->buildCachedEntityPersister($em, $persister2, $metadata2);

        self::assertInstanceOf(CachedEntityPersister::class, $cachedPersister1);
        self::assertInstanceOf(CachedEntityPersister::class, $cachedPersister2);

        self::assertNotSame($cachedPersister1, $cachedPersister2);
        self::assertSame($cachedPersister1->getCacheRegion(), $cachedPersister2->getCacheRegion());
    }

    public function testCreateNewCacheDriver()
    {
        $em         = $this->em;
        $metadata1  = clone $em->getClassMetadata(State::class);
        $metadata2  = clone $em->getClassMetadata(City::class);
        $persister1 = new BasicEntityPersister($em, $metadata1);
        $persister2 = new BasicEntityPersister($em, $metadata2);
        $factory    = new DefaultCacheFactory($this->regionsConfig, $this->getSharedSecondLevelCacheDriverImpl());

        $cachedPersister1 = $factory->buildCachedEntityPersister($em, $persister1, $metadata1);
        $cachedPersister2 = $factory->buildCachedEntityPersister($em, $persister2, $metadata2);

        self::assertInstanceOf(CachedEntityPersister::class, $cachedPersister1);
        self::assertInstanceOf(CachedEntityPersister::class, $cachedPersister2);

        self::assertNotSame($cachedPersister1, $cachedPersister2);
        self::assertNotSame($cachedPersister1->getCacheRegion(), $cachedPersister2->getCacheRegion());
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Unrecognized access strategy type [-1]
     */
    public function testBuildCachedEntityPersisterNonStrictException()
    {
        $em         = $this->em;
        $metadata   = clone $em->getClassMetadata(State::class);
        $persister  = new BasicEntityPersister($em, $metadata);
        
        $metadata->setCache(
            new CacheMetadata(-1, 'doctrine_tests_models_cache_state')
        );

        $this->factory->buildCachedEntityPersister($em, $persister, $metadata);
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Unrecognized access strategy type [-1]
     */
    public function testBuildCachedCollectionPersisterException()
    {
        $em          = $this->em;
        $metadata    = clone $em->getClassMetadata(State::class);
        $association = $metadata->associationMappings['cities'];
        $persister   = new OneToManyPersister($em);

        $association->setCache(
            new CacheMetadata(-1, 'doctrine_tests_models_cache_state__cities')
        );

        $this->factory->buildCachedCollectionPersister($em, $persister, $association);
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage If you want to use a "READ_WRITE" cache an implementation of "Doctrine\ORM\Cache\ConcurrentRegion" is required, The default implementation provided by doctrine is "Doctrine\ORM\Cache\Region\FileLockRegion" if you want to use it please provide a valid directory
     */
    public function testInvalidFileLockRegionDirectoryException()
    {
        $factory = new DefaultCacheFactory($this->regionsConfig, $this->getSharedSecondLevelCacheDriverImpl());

        $fooCache  = new CacheMetadata(CacheUsage::READ_WRITE, 'foo');
        
        $factory->getRegion($fooCache);
    }

    public function testBuildsNewNamespacedCacheInstancePerRegionInstance()
    {
        $factory = new DefaultCacheFactory($this->regionsConfig, $this->getSharedSecondLevelCacheDriverImpl());

        $fooCache  = new CacheMetadata(CacheUsage::READ_ONLY, 'foo');
        $fooRegion = $factory->getRegion($fooCache);
        
        $barCache  = new CacheMetadata(CacheUsage::READ_ONLY, 'bar');
        $barRegion = $factory->getRegion($barCache);

        self::assertSame('foo', $fooRegion->getCache()->getNamespace());
        self::assertSame('bar', $barRegion->getCache()->getNamespace());
    }

    public function testAppendsNamespacedCacheInstancePerRegionInstanceWhenItsAlreadySet()
    {
        $cache = clone $this->getSharedSecondLevelCacheDriverImpl();
        $cache->setNamespace('testing');

        $factory = new DefaultCacheFactory($this->regionsConfig, $cache);

        $fooCache  = new CacheMetadata(CacheUsage::READ_ONLY, 'foo');
        $fooRegion = $factory->getRegion($fooCache);

        $barCache  = new CacheMetadata(CacheUsage::READ_ONLY, 'bar');
        $barRegion = $factory->getRegion($barCache);

        $this->assertSame('testing:foo', $fooRegion->getCache()->getNamespace());
        $this->assertSame('testing:bar', $barRegion->getCache()->getNamespace());
    }

    public function testBuildsDefaultCacheRegionFromGenericCacheRegion()
    {
        /* @var $cache \Doctrine\Common\Cache\Cache */
        $cache   = $this->createMock(Cache::class);
        $factory = new DefaultCacheFactory($this->regionsConfig, $cache);
        
        $barCache  = new CacheMetadata(CacheUsage::READ_ONLY, 'bar');
        $barRegion = $factory->getRegion($barCache);
        
        self::assertInstanceOf(DefaultRegion::class, $barRegion);
    }

    public function testBuildsMultiGetCacheRegionFromGenericCacheRegion()
    {
        /* @var $cache \Doctrine\Common\Cache\CacheProvider */
        $cache   = $this->getMockForAbstractClass(CacheProvider::class);
        $factory = new DefaultCacheFactory($this->regionsConfig, $cache);
        
        $barCache  = new CacheMetadata(CacheUsage::READ_ONLY, 'bar');
        $barRegion = $factory->getRegion($barCache);

        self::assertInstanceOf(DefaultMultiGetRegion::class, $barRegion);
    }

}
