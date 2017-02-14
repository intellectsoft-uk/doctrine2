<?php

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Annotation as ORM;
use Doctrine\Tests\OrmFunctionalTestCase;

class DDC1209Test extends OrmFunctionalTestCase
{
    protected function setUp()
    {
        parent::setUp();
        try {
            $this->schemaTool->createSchema(
                [
                $this->em->getClassMetadata(DDC1209_1::class),
                $this->em->getClassMetadata(DDC1209_2::class),
                $this->em->getClassMetadata(DDC1209_3::class)
                ]
            );
        } catch(\Exception $e) {
        }
    }

    /**
     * @group DDC-1209
     */
    public function testIdentifierCanHaveCustomType()
    {
        $this->em->persist(new DDC1209_3());
        $this->em->flush();
    }

    /**
     * @group DDC-1209
     */
    public function testCompositeIdentifierCanHaveCustomType()
    {
        $future1 = new DDC1209_1();
        $this->em->persist($future1);

        $this->em->flush();

        $future2 = new DDC1209_2($future1);
        $this->em->persist($future2);

        $this->em->flush();
    }
}

/**
 * @ORM\Entity
 */
class DDC1209_1
{
    /**
     * @ORM\Id @ORM\GeneratedValue @ORM\Column(type="integer")
     */
    private $id;

    public function getId()
    {
        return $this->id;
    }
}

/**
 * @ORM\Entity
 */
class DDC1209_2
{
    /**
     *  @ORM\Id
     *  @ORM\ManyToOne(targetEntity="DDC1209_1")
     *  @ORM\JoinColumn(referencedColumnName="id", nullable=false)
     */
    private $future1;
    /**
     *  @ORM\Id
     *  @ORM\Column(type="datetime", nullable=false)
     */
    private $starting_datetime;
    /**
     *  @ORM\Id
     *  @ORM\Column(type="datetime", nullable=false)
     */
    private $during_datetime;
    /**
     *  @ORM\Id
     *  @ORM\Column(type="datetime", nullable=false)
     */
    private $ending_datetime;

    public function __construct(DDC1209_1 $future1)
    {
        $this->future1 = $future1;
        $this->starting_datetime = new DateTime2();
        $this->during_datetime = new DateTime2();
        $this->ending_datetime = new DateTime2();
    }
}

/**
 * @ORM\Entity
 */
class DDC1209_3
{
    /**
     * @ORM\Id
     * @ORM\Column(type="datetime", name="somedate")
     */
    private $date;

    public function __construct()
    {
        $this->date = new DateTime2();
    }
}

class DateTime2 extends \DateTime
{
    public function __toString()
    {
        return $this->format('Y');
    }
}
