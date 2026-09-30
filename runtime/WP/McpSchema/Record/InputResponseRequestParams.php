<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace SiteAgent\Vendor\WP\McpSchema\Record;

final class InputResponseRequestParams extends \SiteAgent\Vendor\WP\McpSchema\Record
{
    public const DEFINITION = 'InputResponseRequestParams';

    /**
     * @return \SiteAgent\Vendor\WP\McpSchema\Record\RequestMetaObject
     */
    public function getMeta(): \SiteAgent\Vendor\WP\McpSchema\Record\RequestMetaObject
    {
        /** @var \SiteAgent\Vendor\WP\McpSchema\Record\RequestMetaObject $value */
        $value = $this->declaredValue('_meta');

        return $value;
    }

    /**
     * @return \SiteAgent\Vendor\WP\McpSchema\Record\InputResponses|null
     */
    public function getInputResponses(): ?\SiteAgent\Vendor\WP\McpSchema\Record\InputResponses
    {
        /** @var \SiteAgent\Vendor\WP\McpSchema\Record\InputResponses|null $value */
        $value = $this->declaredValue('inputResponses');

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
}
