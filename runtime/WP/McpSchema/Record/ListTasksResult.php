<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace SiteAgent\Vendor\WP\McpSchema\Record;

final class ListTasksResult extends \SiteAgent\Vendor\WP\McpSchema\Record implements \SiteAgent\Vendor\WP\McpSchema\Contract\ClientResult, \SiteAgent\Vendor\WP\McpSchema\Contract\ServerResult
{
    public const DEFINITION = 'ListTasksResult';

    /**
     * @return \stdClass|null
     */
    public function getMeta(): ?\stdClass
    {
        /** @var \stdClass|null $value */
        $value = $this->declaredValue('_meta');

        return $value;
    }

    /**
     * @return null|string
     */
    public function getNextCursor(): ?string
    {
        /** @var null|string $value */
        $value = $this->declaredValue('nextCursor');

        return $value;
    }

    /**
     * @return array<int, \SiteAgent\Vendor\WP\McpSchema\Record\Task>
     */
    public function getTasks(): array
    {
        /** @var array<int, \SiteAgent\Vendor\WP\McpSchema\Record\Task> $value */
        $value = $this->declaredValue('tasks');

        return $value;
    }
}
