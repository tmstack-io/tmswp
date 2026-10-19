<?php

declare(strict_types=1);

namespace TMSWP\SettingsPage;

use ReflectionEnum;
use ReflectionEnumBackedCase;
use TMSWP\Core\Attribute\OptionSchema;
use TMSWP\Core\Exception\InvalidSchemaException;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormResult;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Log\Log;
use TMSWP\Core\Option\OptionRepository;
use TMSWP\Core\Option\OptionRepositoryFactory;

final class OptionFormBridge
{
    /**
     * @param class-string $optionGroupClass
     */
    public static function applyDefaults(Form $form, string $optionGroupClass): void
    {
        $repository = self::repository($optionGroupClass);
        $schemaKeys = self::schemaKeys($optionGroupClass);
        $fieldNames = array_keys($form->fields());
        $defaults = [];

        foreach ($schemaKeys as $key) {
            if (\in_array($key, $fieldNames, true)) {
                $defaults[$key] = $repository->get($key);
                continue;
            }

            Log::debug("settings-page option has no matching form field: {$key}");
        }

        foreach ($fieldNames as $fieldName) {
            if (!\in_array($fieldName, $schemaKeys, true)) {
                Log::debug("settings-page form field has no matching option schema: {$fieldName}");
            }
        }

        $form->applyDefaults($defaults);
    }

    /**
     * @param class-string $optionGroupClass
     * @param list<string> $only
     */
    public static function persist(FormResult $result, string $optionGroupClass, array $only = []): void
    {
        $repository = self::repository($optionGroupClass);
        $schemaKeys = self::schemaKeys($optionGroupClass);
        $allowed = $only === [] ? $schemaKeys : array_values(array_intersect($schemaKeys, $only));
        $values = $result->values;

        foreach ($allowed as $key) {
            if (\array_key_exists($key, $values)) {
                $repository->set($key, $values[$key]);
                continue;
            }

            Log::debug("settings-page option has no matching submitted field: {$key}");
        }

        foreach (array_keys($values) as $fieldName) {
            if (!\in_array($fieldName, $schemaKeys, true)) {
                Log::debug("settings-page submitted field has no matching option schema: {$fieldName}");
            }
        }
    }

    /**
     * @param class-string $optionGroupClass
     */
    private static function repository(string $optionGroupClass): OptionRepository
    {
        $factory = Kernel::forClass($optionGroupClass)->container()->get(OptionRepositoryFactory::class);

        return $factory->for($optionGroupClass);
    }

    /**
     * @param class-string $optionGroupClass
     *
     * @return list<string>
     */
    private static function schemaKeys(string $optionGroupClass): array
    {
        if (!enum_exists($optionGroupClass)) {
            throw new InvalidSchemaException("option group must be an enum: {$optionGroupClass}");
        }

        $reflection = new ReflectionEnum($optionGroupClass);
        $keys = [];

        foreach ($reflection->getCases() as $case) {
            if (!$case instanceof ReflectionEnumBackedCase || $case->getAttributes(OptionSchema::class) === []) {
                continue;
            }

            $value = $case->getBackingValue();

            if (!\is_string($value)) {
                throw new InvalidSchemaException("option group case must use string backing value: {$optionGroupClass}::{$case->getName()}");
            }

            $keys[] = $value;
        }

        return $keys;
    }
}
