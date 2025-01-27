<?php

namespace Retext\ApiBundle\Tests\Unit\Model;

use Retext\ApiBundle\Model\ProjectContributor, Retext\ApiBundle\Document\Project;

/**
 * Tests für den ProjectContributor
 *
 * @see \Retext\ApiBundle\Tests\Unit\Model\ProjectContributor
 * @author Markus Tacker <m@tckr.cc>
 */
class ProjectContributorModelTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @group unit
     */
    public function testContributors()
    {
        $project = new Project();
        $project->setId('1234');

        $projectContributor = new ProjectContributor();
        $projectContributor->setProject($project);
        $projectContributor->setEmail('hans@wurst.de');
        $this->assertEquals('/api/project/1234/contributor/hans@wurst.de', $projectContributor->getSubject());
    }
}
