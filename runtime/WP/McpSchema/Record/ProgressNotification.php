<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace SiteAgent\Vendor\WP\McpSchema\Record;

final class ProgressNotification extends \SiteAgent\Vendor\WP\McpSchema\Record implements \SiteAgent\Vendor\WP\McpSchema\Contract\ClientNotification, \SiteAgent\Vendor\WP\McpSchema\Contract\ServerNotification
{
    public const DEFINITION = 'ProgressNotification';

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
     * @return 'notifications/progress'
     */
    public function getMethod(): string
    {
        /** @var 'notifications/progress' $value */
        $value = $this->declaredValue('method');

        return $value;
    }

    /**
     * @return \SiteAgent\Vendor\WP\McpSchema\Record\ProgressNotificationParams
     */
    public function getParams(): \SiteAgent\Vendor\WP\McpSchema\Record\ProgressNotificationParams
    {
        /** @var \SiteAgent\Vendor\WP\McpSchema\Record\ProgressNotificationParams $value */
        $value = $this->declaredValue('params');

        return $value;
    }
}
