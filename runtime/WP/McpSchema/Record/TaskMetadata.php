<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace SiteAgent\Vendor\WP\McpSchema\Record;

final class TaskMetadata extends \SiteAgent\Vendor\WP\McpSchema\Record
{
    public const DEFINITION = 'TaskMetadata';

    /**
     * @return float|int|null
     */
    public function getTtl()
    {
        /** @var float|int|null $value */
        $value = $this->declaredValue('ttl');

        return $value;
    }
}
