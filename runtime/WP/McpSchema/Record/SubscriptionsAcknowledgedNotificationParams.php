<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace SiteAgent\Vendor\WP\McpSchema\Record;

final class SubscriptionsAcknowledgedNotificationParams extends \SiteAgent\Vendor\WP\McpSchema\Record
{
    public const DEFINITION = 'SubscriptionsAcknowledgedNotificationParams';

    /**
     * @return \SiteAgent\Vendor\WP\McpSchema\Record\NotificationMetaObject|null
     */
    public function getMeta(): ?\SiteAgent\Vendor\WP\McpSchema\Record\NotificationMetaObject
    {
        /** @var \SiteAgent\Vendor\WP\McpSchema\Record\NotificationMetaObject|null $value */
        $value = $this->declaredValue('_meta');

        return $value;
    }

    /**
     * @return \SiteAgent\Vendor\WP\McpSchema\Record\SubscriptionFilter
     */
    public function getNotifications(): \SiteAgent\Vendor\WP\McpSchema\Record\SubscriptionFilter
    {
        /** @var \SiteAgent\Vendor\WP\McpSchema\Record\SubscriptionFilter $value */
        $value = $this->declaredValue('notifications');

        return $value;
    }
}
