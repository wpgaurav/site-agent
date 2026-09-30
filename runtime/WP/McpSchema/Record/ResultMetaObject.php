<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace SiteAgent\Vendor\WP\McpSchema\Record;

final class ResultMetaObject extends \SiteAgent\Vendor\WP\McpSchema\Record
{
    public const DEFINITION = 'ResultMetaObject';

    /**
     * @return \SiteAgent\Vendor\WP\McpSchema\Record\Implementation|null
     */
    public function getIoModelcontextprotocolServerInfo(): ?\SiteAgent\Vendor\WP\McpSchema\Record\Implementation
    {
        /** @var \SiteAgent\Vendor\WP\McpSchema\Record\Implementation|null $value */
        $value = $this->declaredValue('io.modelcontextprotocol/serverInfo');

        return $value;
    }
}
