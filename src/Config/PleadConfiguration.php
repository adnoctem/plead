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
            ->children()
                ->arrayNode('plesk')
                    ->children()
                        ->scalarNode('host')->isRequired()->end()
                        ->scalarNode('secret_key')->defaultNull()->end()
                        ->scalarNode('login')->defaultNull()->end()
                        ->scalarNode('password')->defaultNull()->end()
                    ->end()
                ->end()
                ->arrayNode('template')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('auto_reply_path')->defaultValue('templates/auto-reply.txt.twig')->end()
                    ->end()
                ->end()
                ->scalarNode('log_level')->defaultValue('info')->end()
            ->end();

        return $tree;
    }
}
