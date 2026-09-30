<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace SiteAgent\Vendor\WP\McpSchema\Record;

final class TitledSingleSelectEnumSchema extends \SiteAgent\Vendor\WP\McpSchema\Record implements \SiteAgent\Vendor\WP\McpSchema\Contract\EnumSchema, \SiteAgent\Vendor\WP\McpSchema\Contract\PrimitiveSchemaDefinition, \SiteAgent\Vendor\WP\McpSchema\Contract\SingleSelectEnumSchema
{
    public const DEFINITION = 'TitledSingleSelectEnumSchema';

    /**
     * @return null|string
     */
    public function getDefault(): ?string
    {
        /** @var null|string $value */
        $value = $this->declaredValue('default');

        return $value;
    }

    /**
     * @return null|string
     */
    public function getDescription(): ?string
    {
        /** @var null|string $value */
        $value = $this->declaredValue('description');

        return $value;
    }

    /**
     * @return array<int, \stdClass>
     */
    public function getOneOf(): array
    {
        /** @var array<int, \stdClass> $value */
        $value = $this->declaredValue('oneOf');

        return $value;
    }

    /**
     * @return null|string
     */
    public function getTitle(): ?string
    {
        /** @var null|string $value */
        $value = $this->declaredValue('title');

        return $value;
    }

    /**
     * @return 'string'
     */
    public function getType(): string
    {
        /** @var 'string' $value */
        $value = $this->declaredValue('type');

        return $value;
    }
}
