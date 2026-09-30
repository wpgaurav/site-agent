<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace SiteAgent\Vendor\WP\McpSchema\Record;

final class TextContent extends \SiteAgent\Vendor\WP\McpSchema\Record implements \SiteAgent\Vendor\WP\McpSchema\Contract\ContentBlock, \SiteAgent\Vendor\WP\McpSchema\Contract\SamplingMessageContentBlock
{
    public const DEFINITION = 'TextContent';

    /**
     * @return \SiteAgent\Vendor\WP\McpSchema\Record\MetaObject|\stdClass|null
     */
    public function getMeta()
    {
        /** @var \SiteAgent\Vendor\WP\McpSchema\Record\MetaObject|\stdClass|null $value */
        $value = $this->declaredValue('_meta');

        return $value;
    }

    /**
     * @return \SiteAgent\Vendor\WP\McpSchema\Record\Annotations|null
     */
    public function getAnnotations(): ?\SiteAgent\Vendor\WP\McpSchema\Record\Annotations
    {
        /** @var \SiteAgent\Vendor\WP\McpSchema\Record\Annotations|null $value */
        $value = $this->declaredValue('annotations');

        return $value;
    }

    /**
     * @return string
     */
    public function getText(): string
    {
        /** @var string $value */
        $value = $this->declaredValue('text');

        return $value;
    }

    /**
     * @return 'text'
     */
    public function getType(): string
    {
        /** @var 'text' $value */
        $value = $this->declaredValue('type');

        return $value;
    }
}
