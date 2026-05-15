<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Bundle\DataHubBundle\Tests\Controller;

use Codeception\Test\Unit;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Symfony\Component\HttpFoundation\Request;

class CheckConsumerPermissionsServiceTest extends Unit
{
    const CORRECT_API_KEY = 'correct_key';

    public function testSecurityCheckFailsWhenNoApiKeyInRequest()
    {
        // Arrange
        $configuration = $this->createMock(Configuration::class);
        $configuration->method('getSecurityConfig')
            ->willReturn([
                 'method' => Configuration::SECURITYCONFIG_AUTH_APIKEY,
                 'apikey' => self::CORRECT_API_KEY,
            ]);
        $request = new Request();

        // System under Test
        $sut = new \Pimcore\Bundle\DataHubBundle\Service\CheckConsumerPermissionsService();
        // Act
        $result = $sut->performSecurityCheck($request, $configuration);
        // Assert
        $this->assertFalse($result);
    }

    public function testSecurityCheckFailsWhenInvalidApiKeyInRequest()
    {
        // Arrange
        $configuration = $this->createMock(Configuration::class);
        $configuration->method('getSecurityConfig')
            ->willReturn([
                'method' => Configuration::SECURITYCONFIG_AUTH_APIKEY,
                'apikey' => self::CORRECT_API_KEY,
            ]);
        $request = new Request(['apikey' => 'wrong_key']);

        // System under Test
        $sut = new \Pimcore\Bundle\DataHubBundle\Service\CheckConsumerPermissionsService();
        // Act
        $result = $sut->performSecurityCheck($request, $configuration);
        //Assert
        $this->assertFalse($result);
    }

    public function testSecurityCheckPassesWhenCorrectApiKeyInQuery()
    {
        // Arrange
        $configuration = $this->createMock(Configuration::class);
        $configuration->method('getSecurityConfig')
            ->willReturn([
                'method' => Configuration::SECURITYCONFIG_AUTH_APIKEY,
                'apikey' => self::CORRECT_API_KEY,
            ]);
        $request = new Request(['apikey' => self::CORRECT_API_KEY]);

        // System under Test
        $sut = new \Pimcore\Bundle\DataHubBundle\Service\CheckConsumerPermissionsService();
        // Act
        $result = $sut->performSecurityCheck($request, $configuration);
        // Assert
        $this->assertTrue($result);
    }

    public function testSecurityCheckPassesWhenCorrectApiKeyInApikeyHeader()
    {
        // Arrange
        $configuration = $this->createMock(Configuration::class);
        $configuration->method('getSecurityConfig')
            ->willReturn([
                'method' => Configuration::SECURITYCONFIG_AUTH_APIKEY,
                'apikey' => self::CORRECT_API_KEY,
            ]);
        $request = new Request();
        $request->headers->set('apikey', self::CORRECT_API_KEY);

        // System under Test
        $sut = new \Pimcore\Bundle\DataHubBundle\Service\CheckConsumerPermissionsService();
        // Act
        $result = $sut->performSecurityCheck($request, $configuration);
        // Assert
        $this->assertTrue($result);
    }

    public function testSecurityCheckPassesWhenCorrectXApiKeyInApikeyHeader()
    {
        // Arrange
        $configuration = $this->createMock(Configuration::class);
        $configuration->method('getSecurityConfig')
            ->willReturn([
                'method' => Configuration::SECURITYCONFIG_AUTH_APIKEY,
                'apikey' => self::CORRECT_API_KEY,
            ]);
        $request = new Request();
        $request->headers->set('X-API-Key', self::CORRECT_API_KEY);
        // System under Test
        $sut = new \Pimcore\Bundle\DataHubBundle\Service\CheckConsumerPermissionsService();
        // Act
        $result = $sut->performSecurityCheck($request, $configuration);
        // Assert
        $this->assertTrue($result);
    }

    public function testSecurityCheckPassesForPersistentRefreshAttribute()
    {
        // Background SWR refresh requests carry no apikey but inherit auth via
        // the _datahub_persistent_refresh attribute.
        $configuration = $this->createMock(Configuration::class);
        $configuration->method('getSecurityConfig')
            ->willReturn([
                'method' => Configuration::SECURITYCONFIG_AUTH_APIKEY,
                'apikey' => self::CORRECT_API_KEY,
            ]);
        $request = new Request();
        $request->attributes->set('_datahub_persistent_refresh', true);

        $sut = new \Pimcore\Bundle\DataHubBundle\Service\CheckConsumerPermissionsService();

        $this->assertTrue($sut->performSecurityCheck($request, $configuration));
    }

    public function testPersistentRefreshAttributeTakesPrecedenceOverWrongApiKey()
    {
        // The attribute bypass fires before apikey evaluation — a wrong apikey
        // must not cause a refresh sub-request to be rejected.
        $configuration = $this->createMock(Configuration::class);
        $configuration->method('getSecurityConfig')
            ->willReturn([
                'method' => Configuration::SECURITYCONFIG_AUTH_APIKEY,
                'apikey' => self::CORRECT_API_KEY,
            ]);
        $request = new Request(['apikey' => 'wrong_key']);
        $request->attributes->set('_datahub_persistent_refresh', true);

        $sut = new \Pimcore\Bundle\DataHubBundle\Service\CheckConsumerPermissionsService();

        $this->assertTrue($sut->performSecurityCheck($request, $configuration));
    }

    public function testSecurityCheckPrioritizesHeaderOverQueryParam()
    {
        // Arrange
        $configuration = $this->createMock(Configuration::class);
        $configuration->method('getSecurityConfig')
            ->willReturn([
                'method' => Configuration::SECURITYCONFIG_AUTH_APIKEY,
                'apikey' => self::CORRECT_API_KEY,
            ]);
        $request = new Request(['apikey', 'wrong_key']);
        $request->headers->set('apikey', self::CORRECT_API_KEY);
        // System under Test
        $sut = new \Pimcore\Bundle\DataHubBundle\Service\CheckConsumerPermissionsService();
        // Act
        $result = $sut->performSecurityCheck($request, $configuration);
        // Assert
        $this->assertTrue($result);
    }
}
