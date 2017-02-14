<?php

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\ORM\Annotation as ORM;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * Description of SequenceGeneratorTest
 *
 * @author robo
 */
class SequenceGeneratorTest extends OrmFunctionalTestCase
{
    public function setUp()
    {
        parent::setUp();

        if (!$this->em->getConnection()->getDatabasePlatform()->supportsSequences()) {
            $this->markTestSkipped('Only working for Databases that support sequences.');
        }

        try {
            $this->schemaTool->createSchema(
                [
                $this->em->getClassMetadata(SequenceEntity::class),
                ]
            );
        } catch(\Exception $e) {

        }
    }

    public function testHighAllocationSizeSequence()
    {
        for ($i = 0; $i < 11; $i++) {
            $e = new SequenceEntity();
            $this->em->persist($e);
        }
        $this->em->flush();
    }
}

/**
 * @ORM\Entity
 */
class SequenceEntity
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="SEQUENCE")
     * @ORM\SequenceGenerator(allocationSize=5,sequenceName="person_id_seq")
     */
    public $id;
}
