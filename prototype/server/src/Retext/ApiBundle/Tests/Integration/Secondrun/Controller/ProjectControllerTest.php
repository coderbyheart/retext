<?php

namespace Retext\ApiBundle\Tests\Integration\Secondrun\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Testet die Schnittstellen zum manipulieren von Projekten
 *
 * @author Markus Tacker <m@tckr.cc>
 */
class ProjectControllerTest extends Base
{
    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        self::$client->CREATE('/api/user', array('email' => 'phpunit+project@retext.it'));
        self::$client->POST('/api/login', array('email' => 'phpunit+project@retext.it', 'password' => 'phpunit+project@retext.it'));
    }

    /**
     * @group secondrun
     * @group integration
     */
    public function testCreateProject()
    {
        $project = self::$client->CREATE('/api/project', array('name' => 'Test-Project äöß'));
        $this->assertEquals('http://jsonld.retext.it/Project', $project->{'@context'});
        $this->assertObjectHasAttribute('name', $project);
        $this->assertEquals('Test-Project äöß', $project->name);

        $project = self::$client->GET($project->{'@subject'});
        $this->assertObjectHasAttribute('name', $project);
        $this->assertEquals('Test-Project äöß', $project->name);

        $projects = self::$client->GET('/api/project');
        $this->assertInternalType('array', $projects);
        $this->assertEquals(1, count($projects));

        $this->assertObjectHasAttribute('name', $projects[0]);
        $this->assertEquals('Test-Project äöß', $projects[0]->name);
    }

    /**
     * @group secondrun
     * @group integration
     */
    public function testContributors()
    {
        $project = self::$client->CREATE('/api/project', array('name' => 'Test-Contributor-Project'));
        $contributorRel = $this->getRelationHref($project, 'http://jsonld.retext.it/ProjectContributor', true);
        $contributors = self::$client->GET($contributorRel);
        $this->assertInternalType('array', $contributors);
        $this->assertEquals(0, count($contributors));

        // Mitarbeiter hinzufügen
        $fritz = 'fritz@wurst.de';
        $hans = 'hans@wurst.de';
        $klaus = 'klaus@wurst.de';
        self::$client->POST($contributorRel, array('email' => $fritz));
        self::$client->POST($contributorRel, array('email' => $klaus));
        self::$client->POST($contributorRel, array('email' => $hans));

        $searchUser = function($email, array $contributors)
        {
            return count(array_filter($contributors, function($el) use($email)
            {
                return $el->email == $email;
            })) === 1;
        };

        $contributors = self::$client->GET($contributorRel);
        $this->assertEquals(3, count($contributors));
        $this->assertTrue($searchUser($fritz, $contributors));
        $this->assertTrue($searchUser($hans, $contributors));
        $this->assertTrue($searchUser($klaus, $contributors));

        // Hans registrieren
        self::$client->CREATE('/api/user', array('email' => $hans));
        self::$client->POST('/api/login', array('email' => $hans, 'password' => $hans));
        self::$client->GET($project->{'@subject'}); // Should work

        // Hans löschen
        self::$client->POST('/api/login', array('email' => 'phpunit+project@retext.it', 'password' => 'phpunit+project@retext.it'));
        $hansMatch = array_filter($contributors, function($el) use($hans)
        {
            return $el->email == $hans;
        });
        $hansContributor = array_shift($hansMatch);
        self::$client->DELETE($hansContributor->{'@subject'});
        $contributors = self::$client->GET($contributorRel);
        $this->assertEquals(2, count($contributors));
        $this->assertTrue($searchUser($fritz, $contributors));
        $this->assertTrue($searchUser($klaus, $contributors));
        $this->assertFalse($searchUser($hans, $contributors));

        // Hans Rechte checken
        self::$client->POST('/api/login', array('email' => $hans, 'password' => $hans));
        self::$client->doRequest('GET', $project->{'@subject'}, null, 404); // Should not work

        // Fritz Rechte checken
        self::$client->CREATE('/api/user', array('email' => $fritz));
        self::$client->POST('/api/login', array('email' => $fritz, 'password' => $fritz));
        self::$client->doRequest('GET', $project->{'@subject'}); // Should work

    }

    /**
     * @group secondrun
     * @group integration
     */
    public function testLanguages()
    {
        $project = self::$client->CREATE('/api/project', array('name' => 'Test-Language-Project'));
        $languageRel = $this->getRelationHref($project, 'http://jsonld.retext.it/Language', true);
        $languages = self::$client->GET($languageRel);
        $this->assertInternalType('array', $languages);
        $this->assertEquals(1, count($languages)); // Deutsch ist default-Sprache

        // Sprachen hinzufügen
        $fr = 'fr';
        $en = 'en';
        self::$client->POST($languageRel, array('name' => $fr, 'description' => 'Französisch'));
        self::$client->POST($languageRel, array('name' => $en, 'description' => 'Englisch'));

        $searchLang = function($lang, array $languages)
        {
            return count(array_filter($languages, function($el) use($lang)
            {
                return $el->name == $lang;
            })) === 1;
        };

        $languages = self::$client->GET($languageRel);
        $this->assertEquals(3, count($languages));
        $this->assertTrue($searchLang($en, $languages));
        $this->assertTrue($searchLang($fr, $languages));
        $this->assertTrue($searchLang($en, $languages));

        // Sprache löschen
        $frMatch = array_filter($languages, function($el) use($fr)
        {
            return $el->name == $fr;
        });
        $frLanguage = array_shift($frMatch);
        self::$client->DELETE($frLanguage->{'@subject'});
        $languages = self::$client->GET($languageRel);
        $this->assertEquals(2, count($languages));
        $this->assertTrue($searchLang($en, $languages));
        $this->assertTrue($searchLang($en, $languages));
        $this->assertFalse($searchLang($fr, $languages));
    }
}
