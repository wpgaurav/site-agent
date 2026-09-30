<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace SiteAgent\Vendor\WP\McpSchema\Record;

final class InitializeResult extends \SiteAgent\Vendor\WP\McpSchema\Record implements \SiteAgent\Vendor\WP\McpSchema\Contract\ServerResult
{
    public const DEFINITION = 'InitializeResult';

    /**
     * @return \stdClass|null
     */
    public function getMeta(): ?\stdClass
    {
        /** @var \stdClass|null $value */
        $value = $this->declaredValue('_meta');

        return $value;
    }

    /**
     * @return \SiteAgent\Vendor\WP\McpSchema\Record\ServerCapabilities
     */
    public function getCapabilities(): \SiteAgent\Vendor\WP\McpSchema\Record\ServerCapabilities
    {
        /** @var \SiteAgent\Vendor\WP\McpSchema\Record\ServerCapabilities $value */
        $value = $this->declaredValue('capabilities');

        return $value;
    }

    /**
     * @return null|string
     */
    public function getInstructions(): ?string
    {
        /** @var null|string $value */
        $value = $this->declaredValue('instructions');

        return $value;
    }

    /**
     * @return string
     */
    public function getProtocolVersion(): string
    {
        /** @var string $value */
        $value = $this->declaredValue('protocolVersion');

        return $value;
    }

    /**
     * @return \SiteAgent\Vendor\WP\McpSchema\Record\Implementation
     */
    public function getServerInfo(): \SiteAgent\Vendor\WP\McpSchema\Record\Implementation
    {
        /** @var \SiteAgent\Vendor\WP\McpSchema\Record\Implementation $value */
        $value = $this->declaredValue('serverInfo');

        return $value;
    }
}
