<?php

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FetchMode;
use Doctrine\Tests\Models\ECommerce\ECommerceCategory;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * Tests a bidirectional one-to-one association mapping (without inheritance).
 */
class OneToManySelfReferentialAssociationTest extends OrmFunctionalTestCase
{
    private $parent;
    private $firstChild;
    private $secondChild;

    protected function setUp()
    {
        $this->useModelSet('ecommerce');
        parent::setUp();
        $this->parent = new ECommerceCategory();
        $this->parent->setName('Programming languages books');
        $this->firstChild = new ECommerceCategory();
        $this->firstChild->setName('Java books');
        $this->secondChild = new ECommerceCategory();
        $this->secondChild->setName('Php books');
    }

    public function testSavesAOneToManyAssociationWithCascadeSaveSet() {
        $this->parent->addChild($this->firstChild);
        $this->parent->addChild($this->secondChild);
        $this->em->persist($this->parent);

        $this->em->flush();

        self::assertForeignKeyIs($this->parent->getId(), $this->firstChild);
        self::assertForeignKeyIs($this->parent->getId(), $this->secondChild);
    }

    public function testSavesAnEmptyCollection()
    {
        $this->em->persist($this->parent);
        $this->em->flush();

        self::assertEquals(0, count($this->parent->getChildren()));
    }

    public function testDoesNotSaveAnInverseSideSet() {
        $this->parent->brokenAddChild($this->firstChild);
        $this->em->persist($this->parent);
        $this->em->flush();

        self::assertForeignKeyIs(null, $this->firstChild);
    }

    public function testRemovesOneToManyAssociation()
    {
        $this->parent->addChild($this->firstChild);
        $this->parent->addChild($this->secondChild);
        $this->em->persist($this->parent);

        $this->parent->removeChild($this->firstChild);
        $this->em->flush();

        self::assertForeignKeyIs(null, $this->firstChild);
        self::assertForeignKeyIs($this->parent->getId(), $this->secondChild);
    }

    public function testEagerLoadsOneToManyAssociation()
    {
        $this->createFixture();

        $query = $this->em->createQuery('select c1, c2 from Doctrine\Tests\Models\ECommerce\ECommerceCategory c1 join c1.children c2');
        $result = $query->getResult();
        self::assertEquals(1, count($result));
        $parent = $result[0];
        $children = $parent->getChildren();

        self::assertInstanceOf(ECommerceCategory::class, $children[0]);
        self::assertSame($parent, $children[0]->getParent());
        self::assertEquals(' books', strstr($children[0]->getName(), ' books'));
        self::assertInstanceOf(ECommerceCategory::class, $children[1]);
        self::assertSame($parent, $children[1]->getParent());
        self::assertEquals(' books', strstr($children[1]->getName(), ' books'));
    }

    public function testLazyLoadsOneToManyAssociation()
    {
        $this->createFixture();
        $metadata = $this->em->getClassMetadata(ECommerceCategory::class);
        $metadata->associationMappings['children']->setFetchMode(FetchMode::LAZY);

        $query = $this->em->createQuery('select c from Doctrine\Tests\Models\ECommerce\ECommerceCategory c order by c.id asc');
        $result = $query->getResult();
        $parent = $result[0];
        $children = $parent->getChildren();

        self::assertInstanceOf(ECommerceCategory::class, $children[0]);
        self::assertSame($parent, $children[0]->getParent());
        self::assertEquals(' books', strstr($children[0]->getName(), ' books'));
        self::assertInstanceOf(ECommerceCategory::class, $children[1]);
        self::assertSame($parent, $children[1]->getParent());
        self::assertEquals(' books', strstr($children[1]->getName(), ' books'));
    }

    private function createFixture()
    {
        $this->parent->addChild($this->firstChild);
        $this->parent->addChild($this->secondChild);
        $this->em->persist($this->parent);

        $this->em->flush();
        $this->em->clear();
    }

    public function assertForeignKeyIs($value, ECommerceCategory $child) {
        $foreignKey = $this->em->getConnection()->executeQuery('SELECT parent_id FROM ecommerce_categories WHERE id=?', [$child->getId()])->fetchColumn();
        self::assertEquals($value, $foreignKey);
    }
}
