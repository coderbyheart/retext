<?php

namespace Retext\ApiBundle\Tests\Unit\Document;

use Retext\ApiBundle\Document\User;

/**
 * Tests für den Benutzer.
 *
 * @see \Retext\ApiBundle\Tests\Unit\Document\User
 * @author Markus Tacker <m@tckr.cc>
 */
class UserDocumentTest extends \PHPUnit_Framework_TestCase
{
    public function testHash()
    {
        $user = new User();
        $pass = $user->hashPassword('1234');
        $this->assertEquals($pass, $user->hashPassword('1234', $pass));
        $this->assertNotEquals($pass, $user->hashPassword('12345', $pass));
    }

    public function testSetPassword()
    {
        $user = new User();
        $user->setPassword('1234');
        $this->assertEquals($user->getPassword(), $user->hashPassword('1234', $user->getPassword()));
    }


}
