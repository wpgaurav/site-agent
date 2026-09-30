<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace SiteAgent\Vendor\WP\McpSchema\Record;

final class CreateMessageResult extends \SiteAgent\Vendor\WP\McpSchema\Record implements \SiteAgent\Vendor\WP\McpSchema\Contract\ClientResult, \SiteAgent\Vendor\WP\McpSchema\Contract\InputResponse
{
    public const DEFINITION = 'CreateMessageResult';

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
     * @return \SiteAgent\Vendor\WP\McpSchema\Record\AudioContent|\SiteAgent\Vendor\WP\McpSchema\Record\ImageContent|\SiteAgent\Vendor\WP\McpSchema\Record\TextContent|\SiteAgent\Vendor\WP\McpSchema\Record\ToolResultContent|\SiteAgent\Vendor\WP\McpSchema\Record\ToolUseContent|array<int, \SiteAgent\Vendor\WP\McpSchema\Contract\SamplingMessageContentBlock>
     */
    public function getContent()
    {
        /** @var \SiteAgent\Vendor\WP\McpSchema\Record\AudioContent|\SiteAgent\Vendor\WP\McpSchema\Record\ImageContent|\SiteAgent\Vendor\WP\McpSchema\Record\TextContent|\SiteAgent\Vendor\WP\McpSchema\Record\ToolResultContent|\SiteAgent\Vendor\WP\McpSchema\Record\ToolUseContent|array<int, \SiteAgent\Vendor\WP\McpSchema\Contract\SamplingMessageContentBlock> $value */
        $value = $this->declaredValue('content');

        return $value;
    }

    /**
     * @return string
     */
    public function getModel(): string
    {
        /** @var string $value */
        $value = $this->declaredValue('model');

        return $value;
    }

    /**
     * @return 'assistant'|'user'
     */
    public function getRole(): string
    {
        /** @var 'assistant'|'user' $value */
        $value = $this->declaredValue('role');

        return $value;
    }

    /**
     * @return null|string
     */
    public function getStopReason(): ?string
    {
        /** @var null|string $value */
        $value = $this->declaredValue('stopReason');

        return $value;
    }
}
