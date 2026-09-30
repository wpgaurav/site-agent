<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace SiteAgent\Vendor\WP\McpSchema\Record;

final class SubscriptionsListenResult extends \SiteAgent\Vendor\WP\McpSchema\Record implements \SiteAgent\Vendor\WP\McpSchema\Contract\ServerResult
{
    public const DEFINITION = 'SubscriptionsListenResult';

    /**
     * @return \SiteAgent\Vendor\WP\McpSchema\Record\SubscriptionsListenResultMetaObject
     */
    public function getMeta(): \SiteAgent\Vendor\WP\McpSchema\Record\SubscriptionsListenResultMetaObject
    {
        /** @var \SiteAgent\Vendor\WP\McpSchema\Record\SubscriptionsListenResultMetaObject $value */
        $value = $this->declaredValue('_meta');

        return $value;
    }

    /**
     * @return string
     */
    public function getResultType(): string
    {
        /** @var string $value */
        $value = $this->declaredValue('resultType');

        return $value;
    }
}
