<?php

/**
 * Copyright 2014 Underground Elephant
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @package     qpush-bundle
 * @copyright   Underground Elephant 2014
 * @license     Apache License, Version 2.0
 */

namespace Uecode\Bundle\QPushBundle\DependencyInjection;

use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use RuntimeException;
use Exception;

/**
 * @author Keith Kirk <kkirk@undergroundelephant.com>
 */
class UecodeQPushExtension extends Extension
{
    /**
     * @param array            $configs
     * @param ContainerBuilder $container
     *
     * @throws RuntimeException|InvalidArgumentException|ServiceNotFoundException
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__.'/../Resources/config')
        );

        $loader->load('parameters.yml');
        $loader->load('services.yml');

        $registry = $container->getDefinition('uecode_qpush.registry');
        $cache    = $config['cache_service'] ?: 'uecode_qpush.file_cache';

        foreach ($config['queues'] as $queue => $values) {

            // Adds logging property to queue options
            $values['options']['logging_enabled'] = $config['logging_enabled'];

            $provider   = $values['provider'];
            $class      = null;
            $client     = null;

            switch ($config['providers'][$provider]['driver']) {
                case 'aws':
                    $class  = $container->getParameter('uecode_qpush.provider.aws');
                    $client = $this->createAwsClient(
                        $config['providers'][$provider],
                        $container,
                        $provider
                    );
                    break;
                case 'ironmq':
                    $class  = $container->getParameter('uecode_qpush.provider.ironmq');
                    $client = $this->createIronMQClient(
                        $config['providers'][$provider],
                        $container,
                        $provider
                    );
                    break;
                case 'sync':
                    $class  = $container->getParameter('uecode_qpush.provider.sync');
                    $client = $this->createSyncClient();
                    break;
                case 'custom':
                    $class  = $container->getParameter('uecode_qpush.provider.custom');
                    $client = $this->createCustomClient($config['providers'][$provider]['service']);
                    break;
                case 'file':
                    $class = $container->getParameter('uecode_qpush.provider.file');
                    $values['options']['path'] = $config['providers'][$provider]['path'];
                    break;
                case 'doctrine':
                    $class = $container->getParameter('uecode_qpush.provider.doctrine');
                    $client = $this->createDoctrineClient($config['providers'][$provider]);
                    break;
            }

            $definition = new Definition(
                $class, [$queue, $values['options'], $client, new Reference($cache), new Reference('logger')]
            );

            $definition->setPublic(true);

            $definition->addTag('monolog.logger', ['channel' => 'qpush'])
                ->addTag(
                    'uecode_qpush.event_listener',
                    [
                        'event' => "{$queue}.on_notification",
                        'method' => "onNotification",
                        'priority' => 255
                    ]
                )
                ->addTag(
                    'uecode_qpush.event_listener',
                    [
                        'event' => "{$queue}.message_received",
                        'method' => "onMessageReceived",
                        'priority' => -255
                    ]
                );

            $isProviderAWS = $config['providers'][$provider]['driver'] === 'aws';
            $isQueueNameSet = isset($values['options']['queue_name']) && !empty($values['options']['queue_name']);

            if ($isQueueNameSet && $isProviderAWS) {
                $definition->addTag(
                    'uecode_qpush.event_listener',
                    [
                        'event' => "{$values['options']['queue_name']}.on_notification",
                        'method' => "onNotification",
                        'priority' => 255
                    ]
                );

                // Check queue name ends with ".fifo"
                $isQueueNameFIFOReady = preg_match("/$(?<=(\.fifo))/", $values['options']['queue_name']) === 1;
                if ($values['options']['fifo'] === true && !$isQueueNameFIFOReady) {
                    throw new InvalidArgumentException('Queue name must end with ".fifo" on AWS FIFO queues');
                }
            }

            $name = sprintf('uecode_qpush.%s', $queue);
            $container->setDefinition($name, $definition);

            $registry->addMethodCall('addProvider', [$queue, new Reference($name)]);
        }
    }

    /**
     * Creates a definition for the AWS provider
     *
     * @param array            $config    A Configuration array for the client
     * @param ContainerBuilder $container The container
     * @param string           $name      The provider key
     *
     * @throws RuntimeException
     *
     * @return Reference
     */
    private function createAwsClient($config, ContainerBuilder $container, $name)
    {
        $service = sprintf('uecode_qpush.provider.%s', $name);

        if (array_key_exists('client', $config)) {
            return new Reference($config['client']);
        }

        if (!$container->hasDefinition($service)) {
            $aws2 = class_exists('Aws\Common\Aws');
            $aws3 = class_exists('Aws\Sdk');
            if (!$aws2 && !$aws3) {
                throw new RuntimeException('You must require "aws/aws-sdk-php" to use the AWS provider.');
            }

            $awsConfig = [
                'region' => $config['region']
            ];

            $aws = new Definition('Aws\Common\Aws');
            $aws->setFactory(['Aws\Common\Aws', 'factory']);
            $aws->setArguments([$awsConfig]);

            if ($aws2) {
                $aws = new Definition('Aws\Common\Aws');
                $aws->setFactory(['Aws\Common\Aws', 'factory']);

                if (!empty($config['key']) && !empty($config['secret'])) {
                    $awsConfig['key']    = $config['key'];
                    $awsConfig['secret'] = $config['secret'];
                }

            } else {
                $aws = new Definition('Aws\Sdk');

                if (!empty($config['key']) && !empty($config['secret'])) {
                    $awsConfig['credentials'] = [
                        'key'    => $config['key'],
                        'secret' => $config['secret']
                    ];
                }
                $awsConfig['version']  = 'latest';
            }

            $aws->setArguments([$awsConfig]);

            $container->setDefinition($service, $aws)->setPublic(false);
        }

        return new Reference($service);
    }

    /**
     * Creates a definition for the IronMQ provider
     *
     * @param array            $config    A Configuration array for the provider
     * @param ContainerBuilder $container The container
     * @param string           $name      The provider key
     *
     * @throws RuntimeException
     *
     * @return Reference
     */
    private function createIronMQClient($config, ContainerBuilder $container, $name)
    {
        $service = sprintf('uecode_qpush.provider.%s', $name);

        if (!$container->hasDefinition($service)) {

            if (!class_exists('IronMQ\IronMQ')) {
                throw new RuntimeException('You must require "iron-io/iron_mq" to use the Iron MQ provider.');
            }

            $ironmq = new Definition('IronMQ\IronMQ');
            $ironmq->setArguments([
                [
                    'token'         => $config['token'],
                    'project_id'    => $config['project_id'],
                    'host'          => sprintf('%s.iron.io', $config['host']),
                    'port'          => $config['port'],
                    'api_version'   => $config['api_version']
                ]
            ]);

            $container->setDefinition($service, $ironmq)->setPublic(false);
        }

        return new Reference($service);
    }

    /**
     * @return Reference
     */
    private function createSyncClient()
    {
        return new Reference('event_dispatcher');
    }

    private function createDoctrineClient($config)
    {
        return new Reference($config['entity_manager']);
    }

    /**
     * @param string $serviceId
     *
     * @return Reference
     */
    private function createCustomClient($serviceId)
    {
        return new Reference($serviceId);
    }

    /**
     * Returns the Extension Alias
     *
     * @return string
     */
    public function getAlias()
    {
        return 'uecode_qpush';
    }
}
