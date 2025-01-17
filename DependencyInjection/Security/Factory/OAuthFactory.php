<?php

/*
 * This file is part of the HWIOAuthBundle package.
 *
 * (c) Hardware Info <opensource@hardware.info>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace HWI\Bundle\OAuthBundle\DependencyInjection\Security\Factory;

use Symfony\Bundle\SecurityBundle\DependencyInjection\Security\Factory\AbstractFactory;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Parameter;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @author Geoffrey Bachelet <geoffrey.bachelet@gmail.com>
 * @author Alexander <iam.asm89@gmail.com>
 */
class OAuthFactory extends AbstractFactory
{
    /**
     * @param ArrayNodeDefinition $node
     */
    public function addConfiguration(NodeDefinition $node)
    {
        parent::addConfiguration($node);

        $builder = $node->children();
        $builder
            ->scalarNode('login_path')->cannotBeEmpty()->isRequired()->end()
        ;

        $this->addOAuthProviderConfiguration($node);
        $this->addResourceOwnersConfiguration($node);
    }

    /**
     * {@inheritdoc}
     */
    public function getKey()
    {
        return 'oauth';
    }

    /**
     * {@inheritdoc}
     */
    public function getPosition()
    {
        return 'http';
    }

    /**
     * Creates a resource owner map for the given configuration.
     *
     * @param ContainerBuilder $container Container to build for
     * @param string           $id        Firewall id
     * @param array            $config    Configuration
     */
    protected function createResourceOwnerMap(ContainerBuilder $container, $id, array $config)
    {
        $resourceOwnersMap = [];
        foreach ($config['resource_owners'] as $name => $checkPath) {
            $resourceOwnersMap[$name] = $checkPath;
        }
        $container->setParameter('hwi_oauth.resource_ownermap.configured.'.$id, $resourceOwnersMap);

        $container
            ->setDefinition($this->getResourceOwnerMapReference($id), new ChildDefinition('hwi_oauth.abstract_resource_ownermap'))
            ->replaceArgument('$resourceOwners', new Parameter('hwi_oauth.resource_ownermap.configured.'.$id))
            ->setPublic(true)
        ;
    }

    /**
     * Gets a reference to the resource owner map.
     *
     * @param string $id
     *
     * @return Reference
     */
    protected function getResourceOwnerMapReference($id)
    {
        return new Reference('hwi_oauth.resource_ownermap.'.$id);
    }

    /**
     * {@inheritdoc}
     */
    protected function createAuthProvider(ContainerBuilder $container, $id, $config, $userProviderId)
    {
        $providerId = 'hwi_oauth.authentication.provider.oauth.'.$id;

        $this->createResourceOwnerMap($container, $id, $config);

        $container
            ->setDefinition($providerId, new ChildDefinition('hwi_oauth.authentication.provider.oauth'))
            ->addArgument($this->createOAuthAwareUserProvider($container, $id, $config['oauth_user_provider']))
            ->addArgument($this->getResourceOwnerMapReference($id))
            ->addArgument(new Reference('hwi_oauth.user_checker'))
            ->addArgument(new Reference('security.token_storage'))
        ;

        return $providerId;
    }

    protected function createOAuthAwareUserProvider(ContainerBuilder $container, $id, $config)
    {
        $serviceId = 'hwi_oauth.user.provider.entity.'.$id;

        // todo: move this to factories?
        switch (key($config)) {
            case 'oauth':
                $container
                    ->setDefinition($serviceId, new ChildDefinition('hwi_oauth.user.provider'))
                ;
                break;
            case 'orm':
                $container
                    ->setDefinition($serviceId, new ChildDefinition('hwi_oauth.user.provider.entity'))
                    ->addArgument($config['orm']['class'])
                    ->addArgument($config['orm']['properties'])
                    ->addArgument($config['orm']['manager_name'])
                ;
                break;
            case 'service':
                $container
                    ->setAlias($serviceId, $config['service']);
                break;
        }

        return new Reference($serviceId);
    }

    /**
     * {@inheritdoc}
     */
    protected function createEntryPoint($container, $id, $config, $defaultEntryPoint)
    {
        $entryPointId = 'hwi_oauth.authentication.entry_point.oauth.'.$id;

        $container
            ->setDefinition($entryPointId, new ChildDefinition('hwi_oauth.authentication.entry_point.oauth'))
            ->addArgument($config['login_path'])
            ->addArgument($config['use_forward'])
        ;

        return $entryPointId;
    }

    /**
     * {@inheritdoc}
     */
    protected function createListener($container, $id, $config, $userProvider)
    {
        $listenerId = parent::createListener($container, $id, $config, $userProvider);

        $checkPaths = $config['resource_owners'];

        $container
            ->getDefinition($listenerId)
            ->addMethodCall('setResourceOwnerMap', [$this->getResourceOwnerMapReference($id)])
            ->addMethodCall('setCheckPaths', [$checkPaths])
        ;

        return $listenerId;
    }

    /**
     * {@inheritdoc}
     */
    protected function getListenerId()
    {
        return 'hwi_oauth.authentication.listener.oauth';
    }

    private function addOAuthProviderConfiguration(ArrayNodeDefinition $node)
    {
        $builder = $node->children();
        $builder
            ->arrayNode('oauth_user_provider')
                ->isRequired()
                ->children()
                    ->arrayNode('orm')
                        ->children()
                            ->scalarNode('class')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('manager_name')->defaultNull()->end()
                            ->arrayNode('properties')
                                ->isRequired()
                                ->useAttributeAsKey('name')
                                    ->prototype('scalar')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                    ->scalarNode('service')->cannotBeEmpty()->end()
                    ->scalarNode('oauth')->end()
                ->end()
                ->validate()
                    ->ifTrue(function ($c) {
                        return 1 !== \count($c) || !\in_array(key($c), ['oauth', 'orm', 'service'], true);
                    })
                    ->thenInvalid("You should configure (only) one of: 'oauth', 'orm', 'service'.")
                ->end()
            ->end()
        ;
    }

    private function addResourceOwnersConfiguration(ArrayNodeDefinition $node)
    {
        $builder = $node->children();
        $builder
            ->arrayNode('resource_owners')
                ->isRequired()
                ->useAttributeAsKey('name')
                    ->prototype('scalar')
                ->end()
                ->validate()
                    ->ifTrue(function ($c) {
                        $checkPaths = [];
                        foreach ($c as $checkPath) {
                            if (\in_array($checkPath, $checkPaths, true)) {
                                return true;
                            }

                            $checkPaths[] = $checkPath;
                        }

                        return false;
                    })
                    ->thenInvalid('Each resource owner should have a unique "check_path".')
                ->end()
            ->end()
        ;
    }
}
