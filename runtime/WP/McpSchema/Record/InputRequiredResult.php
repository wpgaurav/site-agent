<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace SiteAgent\Vendor\WP\McpSchema\Record;

final class InputRequiredResult extends \SiteAgent\Vendor\WP\McpSchema\Record implements \SiteAgent\Vendor\WP\McpSchema\Contract\ServerResult
{
    public const DEFINITION = 'InputRequiredResult';

    /**
     * @return \SiteAgent\Vendor\WP\McpSchema\Record\ResultMetaObject|null
     */
    public function getMeta(): ?\SiteAgent\Vendor\WP\McpSchema\Record\ResultMetaObject
    {
        /** @var \SiteAgent\Vendor\WP\McpSchema\Record\ResultMetaObject|null $value */
        $value = $this->declaredValue('_meta');

        return $value;
    }

    /**
     * @return \SiteAgent\Vendor\WP\McpSchema\Record\InputRequests|null
     */
    public function getInputRequests(): ?\SiteAgent\Vendor\WP\McpSchema\Record\InputRequests
    {
        /** @var \SiteAgent\Vendor\WP\McpSchema\Record\InputRequests|null $value */
        $value = $this->declaredValue('inputRequests');

        return $value;
    }

    /**
     * @return null|string
     */
    public function getRequestState(): ?string
    {
        /** @var null|string $value */
        $value = $this->declaredValue('requestState');

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
