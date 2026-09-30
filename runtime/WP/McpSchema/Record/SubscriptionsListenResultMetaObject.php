<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace SiteAgent\Vendor\WP\McpSchema\Record;

final class SubscriptionsListenResultMetaObject extends \SiteAgent\Vendor\WP\McpSchema\Record
{
    public const DEFINITION = 'SubscriptionsListenResultMetaObject';

    /**
     * @return \SiteAgent\Vendor\WP\McpSchema\Record\Implementation|null
     */
    public function getIoModelcontextprotocolServerInfo(): ?\SiteAgent\Vendor\WP\McpSchema\Record\Implementation
    {
        /** @var \SiteAgent\Vendor\WP\McpSchema\Record\Implementation|null $value */
        $value = $this->declaredValue('io.modelcontextprotocol/serverInfo');

        return $value;
    }

    /**
     * @return float|int|string
     */
    public function getIoModelcontextprotocolSubscriptionId()
    {
        /** @var float|int|string $value */
        $value = $this->declaredValue('io.modelcontextprotocol/subscriptionId');

        return $value;
    }
}
