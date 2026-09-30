<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace SiteAgent\Vendor\WP\McpSchema\Record;

final class TaskAugmentedRequestParams extends \SiteAgent\Vendor\WP\McpSchema\Record
{
    public const DEFINITION = 'TaskAugmentedRequestParams';

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
     * @return \SiteAgent\Vendor\WP\McpSchema\Record\TaskMetadata|null
     */
    public function getTask(): ?\SiteAgent\Vendor\WP\McpSchema\Record\TaskMetadata
    {
        /** @var \SiteAgent\Vendor\WP\McpSchema\Record\TaskMetadata|null $value */
        $value = $this->declaredValue('task');

        return $value;
    }
}
