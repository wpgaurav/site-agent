<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace SiteAgent\Vendor\WP\McpSchema\Record;

final class MissingRequiredClientCapabilityError extends \SiteAgent\Vendor\WP\McpSchema\Record
{
    public const DEFINITION = 'MissingRequiredClientCapabilityError';

    /**
     * @return \SiteAgent\Vendor\WP\McpSchema\Record\Error
     */
    public function getError(): \SiteAgent\Vendor\WP\McpSchema\Record\Error
    {
        /** @var \SiteAgent\Vendor\WP\McpSchema\Record\Error $value */
        $value = $this->declaredValue('error');

        return $value;
    }

    /**
     * @return float|int|null|string
     */
    public function getId()
    {
        /** @var float|int|null|string $value */
        $value = $this->declaredValue('id');

        return $value;
    }

    /**
     * @return '2.0'
     */
    public function getJsonrpc(): string
    {
        /** @var '2.0' $value */
        $value = $this->declaredValue('jsonrpc');

        return $value;
    }
}
