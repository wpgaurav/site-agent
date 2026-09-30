<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace SiteAgent\Vendor\WP\McpSchema\Record;

final class CreateTaskResult extends \SiteAgent\Vendor\WP\McpSchema\Record
{
    public const DEFINITION = 'CreateTaskResult';

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
     * @return \SiteAgent\Vendor\WP\McpSchema\Record\Task
     */
    public function getTask(): \SiteAgent\Vendor\WP\McpSchema\Record\Task
    {
        /** @var \SiteAgent\Vendor\WP\McpSchema\Record\Task $value */
        $value = $this->declaredValue('task');

        return $value;
    }
}
