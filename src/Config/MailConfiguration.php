<?php

declare(strict_types=1);

namespace App\Config;

use App\Rule\GroupPattern;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * The "mail" subtree shared by the general config and per-server sections.
 * Note: defaults are NOT auto-populated here - merging must be able to tell
 * whether a section set a key at all.
 */
final class MailConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('mail');
        $tree->getRootNode()
            ->children()
            ->arrayNode('defaults')
            ->children()
            ->scalarNode('quota')
            ->defaultNull()
            ->beforeNormalization()
            ->ifString()
            ->then(static function (string $value): int {
                try {
                    return HumanSize::toBytes($value);
                } catch (\InvalidArgumentException $e) {
                    throw new InvalidConfigurationException($e->getMessage());
                }
            })
            ->end()
            ->validate()
            ->ifTrue(static fn (?int $value): bool => null !== $value && $value <= 0)
            ->thenInvalid('mail.defaults.quota must be a positive number of bytes')
            ->end()
            ->end()
            ->enumNode('antivirus')
            ->values(['off', 'in', 'out', 'inout'])
            ->defaultNull()
            ->end()
            ->end()
            ->end()
            ->arrayNode('group')
            ->arrayPrototype()
            ->children()
            ->scalarNode('address')->isRequired()->end()
            ->scalarNode('domain')->defaultNull()->end()
            ->scalarNode('pattern')->defaultNull()->end()
            ->arrayNode('recipients')
            ->scalarPrototype()->end()
            ->defaultValue([])
            ->end()
            ->end()
            ->validate()
            ->ifTrue(static fn (array $entry): bool => null === $entry['pattern'] && [] === $entry['recipients'])
            ->thenInvalid('a mail group entry must set either pattern or recipients')
            ->end()
            ->validate()
            ->ifTrue(static fn (array $entry): bool => !str_contains($entry['address'], '@') && null === $entry['domain'])
            ->thenInvalid('a mail group entry address without a domain needs an explicit domain property')
            ->end()
            ->validate()
            ->ifTrue(static fn (array $entry): bool => null !== $entry['pattern'] && !GroupPattern::compiles((string) $entry['pattern']))
            ->thenInvalid('mail group pattern is not a valid PCRE')
            ->end()
            ->end()
            ->end()
            ->arrayNode('autoresponder')
            ->arrayPrototype()
            ->children()
            ->scalarNode('address')->isRequired()->end()
            ->scalarNode('message_file')->isRequired()->end()
            ->scalarNode('start_date')->defaultNull()->end()
            ->scalarNode('end_date')->isRequired()->end()
            ->end()
            ->validate()
            ->ifTrue(static fn (array $entry): bool => !str_contains($entry['address'], '@'))
            ->thenInvalid('a mail autoresponder entry address must be a full email address')
            ->end()
            ->end()
            ->end()
        ;

        return $tree;
    }
}
