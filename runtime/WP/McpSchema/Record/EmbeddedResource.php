<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace SiteAgent\Vendor\WP\McpSchema\Record;

final class EmbeddedResource extends \SiteAgent\Vendor\WP\McpSchema\Record implements \SiteAgent\Vendor\WP\McpSchema\Contract\ContentBlock
{
    public const DEFINITION = 'EmbeddedResource';

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
     * @return \SiteAgent\Vendor\WP\McpSchema\Record\BlobResourceContents|\SiteAgent\Vendor\WP\McpSchema\Record\TextResourceContents
     */
    public function getResource()
    {
        /** @var \SiteAgent\Vendor\WP\McpSchema\Record\BlobResourceContents|\SiteAgent\Vendor\WP\McpSchema\Record\TextResourceContents $value */
        $value = $this->declaredValue('resource');

        return $value;
    }

    /**
     * @return 'resource'
     */
    public function getType(): string
    {
        /** @var 'resource' $value */
        $value = $this->declaredValue('type');

        return $value;
    }
}
