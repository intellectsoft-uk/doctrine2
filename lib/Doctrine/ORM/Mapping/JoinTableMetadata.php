<?php

declare(strict_types = 1);

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

namespace Doctrine\ORM\Mapping;

final class JoinTableMetadata extends TableMetadata
{
    /** @var array<JoinColumnMetadata> */
    protected $joinColumns = [];

    /** @var array<JoinColumnMetadata> */
    protected $inverseJoinColumns = [];

    /**
     * @return bool
     */
    public function hasColumns()
    {
        return $this->joinColumns || $this->inverseJoinColumns;
    }

    /**
     * @return array<JoinColumnMetadata>
     */
    public function getJoinColumns()
    {
        return $this->joinColumns;
    }

    /**
     * @param JoinColumnMetadata $joinColumn
     */
    public function addJoinColumn(JoinColumnMetadata $joinColumn)
    {
        $this->joinColumns[] = $joinColumn;
    }

    /**
     * @return array<JoinColumnMetadata>
     */
    public function getInverseJoinColumns()
    {
        return $this->inverseJoinColumns;
    }

    /**
     * @param JoinColumnMetadata $joinColumn
     */
    public function addInverseJoinColumn(JoinColumnMetadata $joinColumn)
    {
        $this->inverseJoinColumns[] = $joinColumn;
    }

    public function __clone()
    {
        foreach ($this->joinColumns as $index => $joinColumn) {
            $this->joinColumns[$index] = clone $joinColumn;
        }

        foreach ($this->inverseJoinColumns as $index => $inverseJoinColumn) {
            $this->inverseJoinColumns[$index] = clone $inverseJoinColumn;
        }
    }
}