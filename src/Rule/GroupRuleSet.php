<?php

declare(strict_types=1);

namespace App\Rule;

/**
 * Builds GroupRule value objects from the merged mail.group config list.
 */
final class GroupRuleSet
{
    /**
     * @param array<int, array<string, mixed>> $entries merged mail.group entries
     *
     * @return GroupRule[]
     */
    public static function fromConfig(array $entries): array
    {
        return array_map(GroupRule::fromConfigEntry(...), array_values($entries));
    }
}
