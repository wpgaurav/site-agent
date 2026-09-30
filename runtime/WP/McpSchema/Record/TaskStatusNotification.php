<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace SiteAgent\Vendor\WP\McpSchema\Record;

final class TaskStatusNotification extends \SiteAgent\Vendor\WP\McpSchema\Record implements \SiteAgent\Vendor\WP\McpSchema\Contract\ClientNotification, \SiteAgent\Vendor\WP\McpSchema\Contract\ServerNotification
{
    public const DEFINITION = 'TaskStatusNotification';

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
     * @return 'notifications/tasks/status'
     */
    public function getMethod(): string
    {
        /** @var 'notifications/tasks/status' $value */
        $value = $this->declaredValue('method');

        return $value;
    }

    /**
     * @return \SiteAgent\Vendor\WP\McpSchema\Record\TaskStatusNotificationParams
     */
    public function getParams(): \SiteAgent\Vendor\WP\McpSchema\Record\TaskStatusNotificationParams
    {
        /** @var \SiteAgent\Vendor\WP\McpSchema\Record\TaskStatusNotificationParams $value */
        $value = $this->declaredValue('params');

        return $value;
    }
}
