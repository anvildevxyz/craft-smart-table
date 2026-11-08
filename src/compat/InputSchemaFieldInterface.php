<?php

namespace craft\base;

/**
 * Defines Craft 5.12's field input-schema interface when this install is older
 * than 5.12, so the field class can implement it without failing to boot.
 * On 5.12 the real interface is already loaded and this file does nothing.
 */
if (!interface_exists(InputSchemaFieldInterface::class, false)) {
    interface InputSchemaFieldInterface extends FieldInterface
    {
        /**
         * @return array<string, mixed>
         */
        public function getInputSchema(): array;
    }
}
