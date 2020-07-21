<?php

/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) EC-CUBE CO.,LTD. All Rights Reserved.
 *
 * http://www.ec-cube.co.jp/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Plugin\Api\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DoctrineOrmMappingsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;

class ApiExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container)
    {
        $securityConfig = $container->getExtensionConfig('security');
        $origin = $securityConfig[0]['firewalls'];
        $names = array_keys($origin);
        $replaced = [];
        foreach ($names as $name) {
            // adminの前にapiを追加する
            if ($name === 'admin') {
                $replaced['api'] = [
                    'pattern' => '^/api',
                    'security' => true,
                    'stateless' => true,
                    'oauth2' => true,
                ];
            }
            $replaced[$name] = $origin[$name];
        }

        $container->prependExtensionConfig('security', ['firewalls' => $replaced]);

        $container->addCompilerPass(
            DoctrineOrmMappingsPass::createXmlMappingDriver(
                [
                    realpath(__DIR__ . '/../Resource/config/doctrine') => 'Plugin\Api\Entity',
                ],
                [
                    'trikoder.oauth2.persistence.doctrine.manager',
                ],
                'trikoder.oauth2.persistence.doctrine.enabled',
                [
                    'TrikoderOAuth2Bundle' => 'Trikoder\Bundle\OAuth2Bundle\Model',
                ]
            )
        );
    }

    public function load(array $configs, ContainerBuilder $container)
    {
    }
}
