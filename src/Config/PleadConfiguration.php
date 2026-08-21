<?php

declare(strict_types=1);

namespace App\Config;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class PleadConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('plead');
        $tree->getRootNode()
            ->ignoreExtraKeys(true)
            ->children()
            ->arrayNode('servers')
            ->isRequired()
            ->requiresAtLeastOneElement()
            ->arrayPrototype()
            ->children()
            ->scalarNode('host')->isRequired()->end()
            ->scalarNode('secret_key')->defaultNull()->end()
            ->scalarNode('login')->defaultNull()->end()
            ->scalarNode('password')->defaultNull()->end()
            ->end()
            ->validate()
            ->ifTrue(static fn (array $server): bool => null === $server['secret_key']
                && (null === $server['login'] || null === $server['password']))
            ->thenInvalid('a server needs a secret_key or both login and password')
            ->end()
            ->end()
            ->end()
            ->append(new MailConfiguration()->getConfigTreeBuilder()->getRootNode())
            ->scalarNode('default_server')->defaultNull()->end()
            ->arrayNode('watch')
            ->addDefaultsIfNotSet()
            ->children()
            ->integerNode('interval')->min(1)->defaultValue(60)->end()
            ->end()
            ->end()
            ->arrayNode('template')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('auto_reply_path')->defaultValue('templates/auto-reply.txt.twig')->end()
            ->end()
            ->end()
            ->scalarNode('log_level')->defaultValue('info')->end()
            ->end()
        ;

        return $tree;
    }
}
