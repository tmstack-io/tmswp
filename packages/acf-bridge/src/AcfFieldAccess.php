<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge;

use DateTimeImmutable;
use DateTimeInterface;
use TMSWP\AcfBridge\Exception\AcfNotActiveException;

/**
 * @phpstan-import-type AcfChoice from \TMSWP\AcfBridge\AcfTypes
 * @phpstan-import-type AcfColorRgba from \TMSWP\AcfBridge\AcfTypes
 * @phpstan-import-type AcfFile from \TMSWP\AcfBridge\AcfTypes
 * @phpstan-import-type AcfImage from \TMSWP\AcfBridge\AcfTypes
 * @phpstan-import-type AcfLink from \TMSWP\AcfBridge\AcfTypes
 * @phpstan-import-type AcfUserArray from \TMSWP\AcfBridge\AcfTypes
 */
trait AcfFieldAccess
{
    private function __construct(private readonly int|string $acfId) {}

    public function acfId(): int|string
    {
        return $this->acfId;
    }

    public function has(string $name): bool
    {
        $this->assertAcfActive(__METHOD__);

        return get_field($name, $this->acfId, false) !== null;
    }

    public function value(string $name): mixed
    {
        $this->assertAcfActive(__METHOD__);

        return get_field($name, $this->acfId, true);
    }

    public function rawValue(string $name): mixed
    {
        $this->assertAcfActive(__METHOD__);

        return get_field($name, $this->acfId, false);
    }

    public function update(string $name, mixed $value): bool
    {
        $this->assertAcfActive(__METHOD__);

        return update_field($name, $value, $this->acfId) !== false;
    }

    public function delete(string $name): bool
    {
        $this->assertAcfActive(__METHOD__);

        return delete_field($name, $this->acfId);
    }

    protected function acfString(string $name): string
    {
        $this->assertAcfActive(__METHOD__);
        $value = $this->value($name);

        return \is_scalar($value) && $value !== false ? (string) $value : '';
    }

    protected function acfNullableString(string $name): ?string
    {
        $value = $this->acfString($name);

        return $value === '' ? null : $value;
    }

    protected function acfNumber(string $name): int|float|null
    {
        $this->assertAcfActive(__METHOD__);
        $value = $this->value($name);

        if ($value === null || $value === false || $value === '') {
            return null;
        }

        if (\is_int($value) || \is_float($value)) {
            return $value;
        }

        if (\is_string($value) && is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        return null;
    }

    protected function acfInt(string $name): ?int
    {
        $value = $this->acfNumber($name);

        return \is_int($value) ? $value : null;
    }

    protected function acfBool(string $name): bool
    {
        $this->assertAcfActive(__METHOD__);

        return (bool) $this->value($name);
    }

    /** @return list<mixed> */
    protected function acfList(string $name): array
    {
        $this->assertAcfActive(__METHOD__);
        $value = $this->value($name);

        return \is_array($value) && array_is_list($value) ? $value : [];
    }

    /** @return list<int> */
    protected function acfIntList(string $name): array
    {
        $items = [];

        foreach ($this->acfList($name) as $item) {
            if (\is_int($item)) {
                $items[] = $item;
                continue;
            }

            if (\is_string($item) && ctype_digit($item)) {
                $items[] = (int) $item;
                continue;
            }

            return [];
        }

        return $items;
    }

    /** @return list<string> */
    protected function acfStringList(string $name): array
    {
        $items = [];

        foreach ($this->acfList($name) as $item) {
            if (!\is_scalar($item) || $item === false) {
                return [];
            }

            $items[] = (string) $item;
        }

        return $items;
    }

    /** @return array<string, mixed>|null */
    protected function acfNullableArray(string $name): ?array
    {
        $this->assertAcfActive(__METHOD__);
        $value = $this->value($name);

        if (!\is_array($value) || array_is_list($value)) {
            return null;
        }

        $assoc = [];

        foreach ($value as $key => $item) {
            if (!\is_string($key)) {
                return null;
            }

            $assoc[$key] = $item;
        }

        return $assoc;
    }

    protected function acfObject(string $name, string $class): ?object
    {
        $this->assertAcfActive(__METHOD__);
        $value = $this->value($name);

        return $value instanceof $class ? $value : null;
    }

    /** @return AcfImage|null */
    protected function acfImage(string $name): ?array
    {
        $value = $this->acfNullableArray($name);

        return $value === null ? null : $this->acfImageFromArray($value);
    }

    /** @return AcfFile|null */
    protected function acfFile(string $name): ?array
    {
        $value = $this->acfNullableArray($name);

        return $value === null ? null : $this->acfFileFromArray($value);
    }

    /** @return list<AcfImage> */
    protected function acfImageList(string $name): array
    {
        $items = [];

        foreach ($this->acfList($name) as $item) {
            if (!\is_array($item) || array_is_list($item)) {
                return [];
            }

            $image = $this->acfImageFromArray($this->stringKeyedArray($item));

            if ($image === null) {
                return [];
            }

            $items[] = $image;
        }

        return $items;
    }

    /** @return AcfLink|null */
    protected function acfLink(string $name): ?array
    {
        $value = $this->acfNullableArray($name);

        return $value === null ? null : $this->acfLinkFromArray($value);
    }

    /** @return AcfChoice|null */
    protected function acfChoice(string $name): ?array
    {
        $value = $this->acfNullableArray($name);

        return $value === null ? null : $this->acfChoiceFromArray($value);
    }

    /** @return list<AcfChoice> */
    protected function acfChoiceList(string $name): array
    {
        $items = [];

        foreach ($this->acfList($name) as $item) {
            if (!\is_array($item) || array_is_list($item)) {
                return [];
            }

            $choice = $this->acfChoiceFromArray($this->stringKeyedArray($item));

            if ($choice === null) {
                return [];
            }

            $items[] = $choice;
        }

        return $items;
    }

    /** @return AcfUserArray|null */
    protected function acfUserArray(string $name): ?array
    {
        $value = $this->acfNullableArray($name);

        return $value === null ? null : $this->acfUserArrayFromArray($value);
    }

    /** @return list<AcfUserArray> */
    protected function acfUserArrayList(string $name): array
    {
        $items = [];

        foreach ($this->acfList($name) as $item) {
            if (!\is_array($item) || array_is_list($item)) {
                return [];
            }

            $user = $this->acfUserArrayFromArray($this->stringKeyedArray($item));

            if ($user === null) {
                return [];
            }

            $items[] = $user;
        }

        return $items;
    }

    /** @return AcfColorRgba|null */
    protected function acfColorRgba(string $name): ?array
    {
        $value = $this->acfNullableArray($name);

        return $value === null ? null : $this->acfColorRgbaFromArray($value);
    }

    protected function acfDateTime(string $name, string $rawFormat): ?DateTimeImmutable
    {
        $this->assertAcfActive(__METHOD__);
        $value = $this->rawValue($name);

        if (!\is_string($value) || $value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!' . $rawFormat, $value, wp_timezone());

        return $date instanceof DateTimeImmutable ? $date : null;
    }

    protected function acfUpdate(string $name, mixed $value): void
    {
        $this->update($name, $value);
    }

    protected function acfUpdateDate(string $name, DateTimeInterface|string|null $value, string $rawFormat): void
    {
        if ($value instanceof DateTimeInterface) {
            $this->acfUpdate($name, $value->format($rawFormat));

            return;
        }

        $this->acfUpdate($name, $value);
    }

    private function assertAcfActive(string $callable): void
    {
        if (!\function_exists('get_field')) {
            throw AcfNotActiveException::forCall($callable);
        }
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return AcfImage|null
     */
    private function acfImageFromArray(array $value): ?array
    {
        $file = $this->acfFileFromArray($value);
        $width = $this->arrayIntValue($value, 'width');
        $height = $this->arrayIntValue($value, 'height');

        if ($file === null || $width === null || $height === null) {
            return null;
        }

        $sizes = $this->imageSizes($value);

        if ($sizes === null) {
            return null;
        }

        return $file + [
            'width' => $width,
            'height' => $height,
            'sizes' => $sizes,
        ];
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return AcfFile|null
     */
    private function acfFileFromArray(array $value): ?array
    {
        $idUpper = $this->arrayIntValue($value, 'ID');
        $id = $this->arrayIntValue($value, 'id');
        $filesize = $this->arrayIntValue($value, 'filesize');
        $uploadedTo = $this->arrayIntValue($value, 'uploaded_to');
        $menuOrder = $this->arrayIntValue($value, 'menu_order');
        $title = $this->arrayStringValue($value, 'title');
        $filename = $this->arrayStringValue($value, 'filename');
        $url = $this->arrayStringValue($value, 'url');
        $link = $this->arrayStringValue($value, 'link');
        $alt = $this->arrayStringValue($value, 'alt');
        $author = $this->arrayStringValue($value, 'author');
        $description = $this->arrayStringValue($value, 'description');
        $caption = $this->arrayStringValue($value, 'caption');
        $name = $this->arrayStringValue($value, 'name');
        $status = $this->arrayStringValue($value, 'status');
        $date = $this->arrayStringValue($value, 'date');
        $modified = $this->arrayStringValue($value, 'modified');
        $mimeType = $this->arrayStringValue($value, 'mime_type');
        $type = $this->arrayStringValue($value, 'type');
        $subtype = $this->arrayStringValue($value, 'subtype');
        $icon = $this->arrayStringValue($value, 'icon');

        if (
            $idUpper === null
            || $id === null
            || $filesize === null
            || $uploadedTo === null
            || $menuOrder === null
            || $title === null
            || $filename === null
            || $url === null
            || $link === null
            || $alt === null
            || $author === null
            || $description === null
            || $caption === null
            || $name === null
            || $status === null
            || $date === null
            || $modified === null
            || $mimeType === null
            || $type === null
            || $subtype === null
            || $icon === null
        ) {
            return null;
        }

        return [
            'ID' => $idUpper,
            'id' => $id,
            'title' => $title,
            'filename' => $filename,
            'filesize' => $filesize,
            'url' => $url,
            'link' => $link,
            'alt' => $alt,
            'author' => $author,
            'description' => $description,
            'caption' => $caption,
            'name' => $name,
            'status' => $status,
            'uploaded_to' => $uploadedTo,
            'date' => $date,
            'modified' => $modified,
            'menu_order' => $menuOrder,
            'mime_type' => $mimeType,
            'type' => $type,
            'subtype' => $subtype,
            'icon' => $icon,
        ];
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return AcfLink|null
     */
    private function acfLinkFromArray(array $value): ?array
    {
        $title = $this->arrayStringValue($value, 'title');
        $url = $this->arrayStringValue($value, 'url');
        $target = $this->arrayStringValue($value, 'target');

        if ($title === null || $url === null || $target === null) {
            return null;
        }

        return [
            'title' => $title,
            'url' => $url,
            'target' => $target,
        ];
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return AcfChoice|null
     */
    private function acfChoiceFromArray(array $value): ?array
    {
        $valueItem = $this->arrayStringValue($value, 'value');
        $label = $this->arrayStringValue($value, 'label');

        if ($valueItem === null || $label === null) {
            return null;
        }

        return [
            'value' => $valueItem,
            'label' => $label,
        ];
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return AcfUserArray|null
     */
    private function acfUserArrayFromArray(array $value): ?array
    {
        $id = $this->arrayIntValue($value, 'ID');
        $firstName = $this->arrayStringValue($value, 'user_firstname');
        $lastName = $this->arrayStringValue($value, 'user_lastname');
        $nickname = $this->arrayStringValue($value, 'nickname');
        $nicename = $this->arrayStringValue($value, 'user_nicename');
        $displayName = $this->arrayStringValue($value, 'display_name');
        $email = $this->arrayStringValue($value, 'user_email');
        $url = $this->arrayStringValue($value, 'user_url');
        $registered = $this->arrayStringValue($value, 'user_registered');
        $description = $this->arrayStringValue($value, 'user_description');
        $avatar = $this->arrayStringValue($value, 'user_avatar');

        if (
            $id === null
            || $firstName === null
            || $lastName === null
            || $nickname === null
            || $nicename === null
            || $displayName === null
            || $email === null
            || $url === null
            || $registered === null
            || $description === null
            || $avatar === null
        ) {
            return null;
        }

        return [
            'ID' => $id,
            'user_firstname' => $firstName,
            'user_lastname' => $lastName,
            'nickname' => $nickname,
            'user_nicename' => $nicename,
            'display_name' => $displayName,
            'user_email' => $email,
            'user_url' => $url,
            'user_registered' => $registered,
            'user_description' => $description,
            'user_avatar' => $avatar,
        ];
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return AcfColorRgba|null
     */
    private function acfColorRgbaFromArray(array $value): ?array
    {
        $red = $this->arrayIntValue($value, 'red');
        $green = $this->arrayIntValue($value, 'green');
        $blue = $this->arrayIntValue($value, 'blue');
        $alpha = $value['alpha'] ?? null;

        if ($red === null || $green === null || $blue === null || !\is_float($alpha)) {
            return null;
        }

        return [
            'red' => $red,
            'green' => $green,
            'blue' => $blue,
            'alpha' => $alpha,
        ];
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $value): array
    {
        $typed = [];

        foreach ($value as $key => $item) {
            if (!\is_string($key)) {
                return [];
            }

            $typed[$key] = $item;
        }

        return $typed;
    }

    /** @param array<string, mixed> $value */
    private function arrayIntValue(array $value, string $key): ?int
    {
        $item = $value[$key] ?? null;

        return \is_int($item) ? $item : null;
    }

    /** @param array<string, mixed> $value */
    private function arrayStringValue(array $value, string $key): ?string
    {
        $item = $value[$key] ?? null;

        return \is_string($item) ? $item : null;
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return array<string, int|string>|null
     */
    private function imageSizes(array $value): ?array
    {
        $sizes = $value['sizes'] ?? null;

        if (!\is_array($sizes) || array_is_list($sizes)) {
            return null;
        }

        $typed = [];

        foreach ($sizes as $key => $item) {
            if (!\is_string($key) || (!\is_int($item) && !\is_string($item))) {
                return null;
            }

            $typed[$key] = $item;
        }

        return $typed;
    }
}
