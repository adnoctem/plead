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
            ->arrayNode('inbox')
            ->addDefaultsIfNotSet()
            ->children()
            ->arrayNode('paths')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('root')->defaultNull()->end()
            ->scalarNode('processed')->defaultNull()->end()
            ->scalarNode('failed')->defaultNull()->end()
            ->end()
            ->end()
            ->scalarNode('template')->defaultNull()->end()
            ->scalarNode('translations')->defaultNull()->end()
            ->scalarNode('domain')->defaultNull()->end()
            ->scalarNode('timezone')->defaultNull()->end()
            ->scalarNode('data_file')->defaultNull()->end()
            ->scalarNode('language')->defaultNull()->end()
            ->scalarNode('subject')->defaultNull()->end()
            ->scalarNode('format')->defaultNull()->validate()
            ->ifTrue(static fn (?string $v): bool => null !== $v && !in_array($v, ['plain', 'html'], true))
            ->thenInvalid('watch.inbox.format must be "plain" or "html"')
            ->end()
            ->end()
            ->booleanNode('inclusive')->defaultTrue()->end()
            ->end()
            ->end()
            ->end()
            ->end()
            ->arrayNode('template')
            ->addDefaultsIfNotSet()
            ->children()
            ->arrayNode('paths')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('auto_reply')->defaultValue('resources/templates/autoresponder.txt.twig')->end()
            ->scalarNode('translations')->defaultValue('resources/translations')->end()
            ->end()
            ->end()
            ->scalarNode('logo_url')->defaultNull()->end()
            ->scalarNode('language')->defaultNull()->end()
            ->end()
            ->end()
            ->scalarNode('log_level')->defaultValue('info')->end()
            ->end()
        ;

        return $tree;
    }
}
