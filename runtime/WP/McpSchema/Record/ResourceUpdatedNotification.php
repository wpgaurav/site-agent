<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace SiteAgent\Vendor\WP\McpSchema\Record;

final class ResourceUpdatedNotification extends \SiteAgent\Vendor\WP\McpSchema\Record implements \SiteAgent\Vendor\WP\McpSchema\Contract\ServerNotification
{
    public const DEFINITION = 'ResourceUpdatedNotification';

    /**
     * @return '2.0'
     */
    public function getJsonrpc(): string
    {
        /** @var '2.0' $value */
        $value = $this->declaredValue('jsonrpc');

        return $value;
    }

    /**
     * @return 'notifications/resources/updated'
     */
    public function getMethod(): string
    {
        /** @var 'notifications/resources/updated' $value */
        $value = $this->declaredValue('method');

        return $value;
    }

    /**
     * @return \SiteAgent\Vendor\WP\McpSchema\Record\ResourceUpdatedNotificationParams
     */
    public function getParams(): \SiteAgent\Vendor\WP\McpSchema\Record\ResourceUpdatedNotificationParams
    {
        /** @var \SiteAgent\Vendor\WP\McpSchema\Record\ResourceUpdatedNotificationParams $value */
        $value = $this->declaredValue('params');

        return $value;
    }
}
