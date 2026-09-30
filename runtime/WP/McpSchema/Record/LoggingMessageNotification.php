<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace SiteAgent\Vendor\WP\McpSchema\Record;

final class LoggingMessageNotification extends \SiteAgent\Vendor\WP\McpSchema\Record implements \SiteAgent\Vendor\WP\McpSchema\Contract\ServerNotification
{
    public const DEFINITION = 'LoggingMessageNotification';

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
     * @return 'notifications/message'
     */
    public function getMethod(): string
    {
        /** @var 'notifications/message' $value */
        $value = $this->declaredValue('method');

        return $value;
    }

    /**
     * @return \SiteAgent\Vendor\WP\McpSchema\Record\LoggingMessageNotificationParams
     */
    public function getParams(): \SiteAgent\Vendor\WP\McpSchema\Record\LoggingMessageNotificationParams
    {
        /** @var \SiteAgent\Vendor\WP\McpSchema\Record\LoggingMessageNotificationParams $value */
        $value = $this->declaredValue('params');

        return $value;
    }
}
