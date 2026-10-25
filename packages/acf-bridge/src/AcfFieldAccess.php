<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use TMSWP\AcfBridge\Exception\AcfBridgeRuntimeException;
use TMSWP\AcfBridge\Exception\AcfNotActiveException;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Kernel\Kernel;

/**
 * 生成クラス(`XxxFields`・`XxxOptions`)が使う、ACFの値の読み書きの基盤(docs/09 §11)。
 *
 * 型強制のヘルパ(acfString()等)は、ACFが返した値を次の2つに分けて扱う(docs/09 §6.1)。
 * - 未設定(null・false・''。配列の型は空の配列も): 型ごとの未設定の値('' ・[]・false・null)に正規化する。エラーにしない。
 * - 形の不一致(フィールドの型の想定と違う値): エラー方針(docs/00 §5.6)の実行時のデータ取得の失敗として扱う。
 *   生成クラスのKernel(Kernel::forClass())のdebugではAcfBridgeRuntimeExceptionを投げ、本番ではそのKernelのロガーに
 *   errorで記録して未設定の値を返す。listの型は要素ごとに検査し、本番では不一致の要素だけを記録して除く。
 *
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

    /**
     * 値が保存されているか。get_field()が読む保存先(acf_get_valid_post_id()で解決したpost_id)の、メタの行(options
     * ページはoption)の有無で判定する。フィールドのdefault_value、register_*_metaの既定値、groupが返す空の配列は
     * 「保存されている」としない。
     *
     * @throws AcfBridgeRuntimeException acf/validate_post_idのフィルタが、post_idをintでも文字列でもない値にした場合
     */
    public function has(string $name): bool
    {
        $this->assertAcfActive(__FUNCTION__);
        $postId = acf_get_valid_post_id($this->acfId);

        if (!\is_int($postId) && !\is_string($postId)) {
            throw new AcfBridgeRuntimeException('ACF resolved the post_id ' . $this->acfId . ' to ' . get_debug_type($postId) . ' (acf/validate_post_id); it must be an int or a string');
        }

        $target = acf_decode_post_id($postId);
        $type = $target['type'] ?? null;
        $id = $target['id'] ?? null;

        if ($type === 'option') {
            // ACFはoptionsの値を`{post_id}_{フィールド名}`のoptionに保存し、nullを未保存として読む
            return \is_string($id) && get_option("{$id}_{$name}", null) !== null;
        }

        if (!\is_string($type) || !\is_int($id) || $id < 1) {
            return false;
        }

        $rows = get_metadata_raw($type, $id, $name, false);

        // WpMetaStorage::has()と同じく、null・false・空の配列とスカラーの空文字は行なしとする
        return $rows !== null && $rows !== false && $rows !== '' && $rows !== [];
    }

    public function value(string $name): mixed
    {
        $this->assertAcfActive(__FUNCTION__);

        return get_field($name, $this->acfId, true);
    }

    public function rawValue(string $name): mixed
    {
        $this->assertAcfActive(__FUNCTION__);

        return get_field($name, $this->acfId, false);
    }

    /**
     * update_field()で保存し、その戻り値をboolで返す。
     *
     * 値はwp_slash()してから渡す。ACFは、メタではupdate_metadata()が、optionsでは自身がwp_unslash()するため、
     * slashしないとバックスラッシュが消える(ACFが管理画面の保存で受け取る$_POSTの値も、slash済みである)。
     *
     * ACFの戻り値は、update_metadata()と同じく、同じ値で書き込まなかったとき・フィルタが中止したとき・データベースの
     * エラーのどれでもfalseになり、区別できない。書き込みの失敗を例外で知る必要がある値は、MetaBag
     * (`#[UseMetaStorage(AcfMetaStorage::class)]`)で読み書きする。
     */
    public function update(string $name, mixed $value): bool
    {
        $this->assertAcfActive(__FUNCTION__);

        // 文字列と配列(の中の文字列)だけをslashする。ほかの型はwp_slash()の対象外
        return update_field($name, \is_string($value) || \is_array($value) ? wp_slash($value) : $value, $this->acfId) !== false;
    }

    public function delete(string $name): bool
    {
        $this->assertAcfActive(__FUNCTION__);

        return delete_field($name, $this->acfId);
    }

    protected function acfString(string $name): string
    {
        $this->assertAcfActive(__FUNCTION__);
        $value = $this->value($name);

        if (self::isUnset($value)) {
            return '';
        }

        if (\is_string($value) || \is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        return $this->acfMismatch($name, 'string', get_debug_type($value), '');
    }

    protected function acfNullableString(string $name): ?string
    {
        $value = $this->acfString($name);

        return $value === '' ? null : $value;
    }

    protected function acfNumber(string $name): int|float|null
    {
        $this->assertAcfActive(__FUNCTION__);
        $value = $this->value($name);

        if (self::isUnset($value)) {
            return null;
        }

        if (\is_int($value) || \is_float($value)) {
            return $value;
        }

        if (\is_string($value) && is_numeric($value)) {
            // 整数の文字列はint、小数・指数の文字列はfloatになる
            return $value + 0;
        }

        return $this->acfMismatch($name, 'number', get_debug_type($value), null);
    }

    protected function acfInt(string $name): ?int
    {
        $value = $this->acfNumber($name);

        if ($value === null || \is_int($value)) {
            return $value;
        }

        return $this->acfMismatch($name, 'int', 'float', null);
    }

    protected function acfBool(string $name): bool
    {
        $this->assertAcfActive(__FUNCTION__);
        $value = $this->value($name);

        if (\is_scalar($value) || $value === null) {
            return (bool) $value;
        }

        return $this->acfMismatch($name, 'bool', get_debug_type($value), false);
    }

    /** @return list<mixed> */
    protected function acfList(string $name): array
    {
        $this->assertAcfActive(__FUNCTION__);
        $value = $this->value($name);

        if (self::isUnset($value)) {
            return [];
        }

        if (!\is_array($value)) {
            return $this->acfMismatch($name, 'list', get_debug_type($value), []);
        }

        foreach (array_keys($value) as $key) {
            if (!\is_int($key)) {
                return $this->acfMismatch($name, 'list', 'array with string keys', []);
            }
        }

        // ACFが要素を除いた配列は添字が飛ぶことがあるため、詰め直す
        return array_values($value);
    }

    /** @return list<int> */
    protected function acfIntList(string $name): array
    {
        return $this->acfListOf($name, 'int', static function (mixed $item): ?int {
            if (\is_int($item)) {
                return $item;
            }

            return \is_string($item) && ctype_digit($item) ? (int) $item : null;
        });
    }

    /** @return list<string> */
    protected function acfStringList(string $name): array
    {
        return $this->acfListOf($name, 'string', static fn(mixed $item): ?string => \is_string($item) || \is_int($item) || \is_float($item) ? (string) $item : null);
    }

    /** @return array<string, mixed>|null */
    protected function acfNullableArray(string $name): ?array
    {
        $this->assertAcfActive(__FUNCTION__);
        $value = $this->value($name);

        // 一度も保存していないgroupは空の配列になる
        if (self::isUnset($value) || $value === []) {
            return null;
        }

        if (!\is_array($value)) {
            return $this->acfMismatch($name, 'array with string keys', get_debug_type($value), null);
        }

        $assoc = self::stringKeyedArray($value);

        return $assoc ?? $this->acfMismatch($name, 'array with string keys', 'list', null);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    protected function acfObject(string $name, string $class): ?object
    {
        $this->assertAcfActive(__FUNCTION__);
        $value = $this->value($name);

        if (self::isUnset($value)) {
            return null;
        }

        return $value instanceof $class ? $value : $this->acfMismatch($name, $class, get_debug_type($value), null);
    }

    /**
     * objectのlist(relationship・post_object・taxonomy・userの複数・return_format=object)。要素ごとに$classのinstanceofを
     * 検査し、合わない要素は形の不一致として扱う(本番では記録して除く)。
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    protected function acfObjectList(string $name, string $class): array
    {
        return $this->acfListOf($name, $class, static fn(mixed $item): ?object => $item instanceof $class ? $item : null);
    }

    /** @return AcfImage|null */
    protected function acfImage(string $name): ?array
    {
        return $this->acfShape($name, 'AcfImage', $this->acfImageFromArray(...));
    }

    /** @return AcfFile|null */
    protected function acfFile(string $name): ?array
    {
        return $this->acfShape($name, 'AcfFile', $this->acfFileFromArray(...));
    }

    /** @return list<AcfImage> */
    protected function acfImageList(string $name): array
    {
        return $this->acfListOf($name, 'AcfImage', $this->arrayItem($this->acfImageFromArray(...)));
    }

    /** @return AcfLink|null */
    protected function acfLink(string $name): ?array
    {
        return $this->acfShape($name, 'AcfLink', $this->acfLinkFromArray(...));
    }

    /** @return AcfChoice|null */
    protected function acfChoice(string $name): ?array
    {
        return $this->acfShape($name, 'AcfChoice', $this->acfChoiceFromArray(...));
    }

    /** @return list<AcfChoice> */
    protected function acfChoiceList(string $name): array
    {
        return $this->acfListOf($name, 'AcfChoice', $this->arrayItem($this->acfChoiceFromArray(...)));
    }

    /** @return AcfUserArray|null */
    protected function acfUserArray(string $name): ?array
    {
        return $this->acfShape($name, 'AcfUserArray', $this->acfUserArrayFromArray(...));
    }

    /** @return list<AcfUserArray> */
    protected function acfUserArrayList(string $name): array
    {
        return $this->acfListOf($name, 'AcfUserArray', $this->arrayItem($this->acfUserArrayFromArray(...)));
    }

    /** @return AcfColorRgba|null */
    protected function acfColorRgba(string $name): ?array
    {
        return $this->acfShape($name, 'AcfColorRgba', $this->acfColorRgbaFromArray(...));
    }

    /**
     * 保存された値(raw)を、$rawFormatの書式でwp_timezone()の日時にする。未設定はnull。書式に合わない値と、範囲外の値
     * (20241332等。createFromFormat()は繰り上げて受け付ける)は形の不一致として扱う。
     *
     * date_time_pickerの書式(Y-m-d H:i:s)で、現地の時刻が夏時間の終わり等で2回来る(2つの瞬間を表す)場合は、早い方の
     * 瞬間を返す(acfUpdateDate()が保存を許すのは早い方だけのため、保存した瞬間として往復する)。
     */
    protected function acfDateTime(string $name, string $rawFormat): ?DateTimeImmutable
    {
        $this->assertAcfActive(__FUNCTION__);
        $value = $this->rawValue($name);

        if (self::isUnset($value)) {
            return null;
        }

        $expected = "date string in the format '{$rawFormat}'";

        if (!\is_string($value)) {
            return $this->acfMismatch($name, $expected, get_debug_type($value), null);
        }

        // createFromFormat()は、NULを含む文字列をValueErrorにするため、解析の前に形の不一致として扱う
        if (str_contains($value, "\0")) {
            return $this->acfMismatch($name, $expected, 'string that contains NUL', null);
        }

        $timezone = wp_timezone();
        $date = DateTimeImmutable::createFromFormat('!' . $rawFormat, $value, $timezone);

        if (!$date instanceof DateTimeImmutable || $date->format($rawFormat) !== $value) {
            return $this->acfMismatch($name, $expected, 'string that does not match the format', null);
        }

        if ($rawFormat !== AcfLocalTime::DATE_TIME_RAW_FORMAT) {
            return $date;
        }

        $instants = AcfLocalTime::instants($value, $timezone);

        return \count($instants) > 1 ? (new DateTimeImmutable('@' . $instants[0]))->setTimezone($timezone) : $date;
    }

    /**
     * update()に委譲し、戻り値を捨てる。update()のfalseは「書き込まなかった」と「失敗した」を区別できないため
     * (update()を参照)、生成クラスのsetterは結果を返さない。
     */
    protected function acfUpdate(string $name, mixed $value): void
    {
        $this->update($name, $value);
    }

    /**
     * DateTimeInterfaceは、wp_timezone()の日時に変換してから$rawFormatの書式の文字列にして保存する(acfDateTime()と
     * 往復する)。文字列とnullはそのまま渡す。
     *
     * ACFの保存形式は時差を持たない現地の時刻のため、夏時間の終わり等で同じ現地の時刻が2回来る間は、2つの瞬間を区別
     * できない。date_time_pickerの書式(Y-m-d H:i:s)では、現地の時刻が2つの瞬間を表すとき(AcfLocalTime::instants())、
     * 早い方の瞬間だけを保存し、遅い方の瞬間は黙って早い方を保存せずに例外にする。日付だけ(Ymd)・時刻だけ(H:i:s)の
     * 書式は瞬間を保存しないため、判定しない。
     *
     * @throws InvalidArgumentException date_time_pickerの書式で、入力が同じ現地の時刻を表す2つの瞬間の遅い方の場合
     */
    protected function acfUpdateDate(string $name, DateTimeInterface|string|null $value, string $rawFormat): void
    {
        $this->assertAcfActive(__FUNCTION__);

        if (!$value instanceof DateTimeInterface) {
            $this->acfUpdate($name, $value);

            return;
        }

        $timezone = wp_timezone();
        $local = DateTimeImmutable::createFromInterface($value)->setTimezone($timezone);
        $raw = $local->format($rawFormat);

        if ($rawFormat === AcfLocalTime::DATE_TIME_RAW_FORMAT) {
            $instants = AcfLocalTime::instants($raw, $timezone);

            if (\count($instants) > 1 && $local->getTimestamp() !== $instants[0]) {
                $instant = $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

                throw new InvalidArgumentException("ACF field '{$name}' cannot store {$instant} UTC: the local time {$raw} in {$timezone->getName()} is ambiguous because it occurs twice (for example when daylight saving time ends), and ACF stores the local time without the UTC offset. Only the earlier of the two instants can be stored; pass it or a time outside the repeated period");
            }
        }

        $this->acfUpdate($name, $raw);
    }

    /**
     * ACFが有効でなければ、呼び出したクラス(生成クラス)とメソッドの名前でAcfNotActiveExceptionを投げる。traitの中の
     * __METHOD__はtraitの名前になるため、呼び出し側は__FUNCTION__を渡し、ここでstatic::classと組み合わせる。
     */
    private function assertAcfActive(string $method): void
    {
        if (!\function_exists('get_field')) {
            throw AcfNotActiveException::forCall(static::class . '::' . $method);
        }
    }

    /** ACFが未設定に返す値(null・false・'')か。 */
    private static function isUnset(mixed $value): bool
    {
        return $value === null || $value === false || $value === '';
    }

    /**
     * 形の不一致を、エラー方針(docs/00 §5.6)の実行時のデータ取得の失敗として扱う。生成クラスのKernelのdebugでは
     * 例外を投げ、本番ではそのKernelのロガーにerrorで記録して$fallbackを返す。値そのものは記録しない。
     *
     * @template T
     *
     * @param string $name フィールド名(listの要素は`name[添字]`)
     * @param string $expected 想定した型
     * @param string $actual 実際の値の型(値そのものではない)
     * @param T $fallback 本番で返す未設定の値
     *
     * @throws AcfBridgeRuntimeException debugの場合
     *
     * @return T
     */
    private function acfMismatch(string $name, string $expected, string $actual, mixed $fallback): mixed
    {
        $kernel = Kernel::forClass(static::class);
        $acfId = (string) $this->acfId;

        if ($kernel->config()->debug) {
            throw new AcfBridgeRuntimeException("ACF field '{$name}' of {$acfId} has an unexpected value: expected {$expected}, got {$actual} (" . static::class . ')');
        }

        $kernel->logger()->error('tmswp.acf: ACF field {field} of {acfId} has an unexpected value: expected {expected}, got {actual} ({class})', [
            'field' => $name,
            'acfId' => $acfId,
            'expected' => $expected,
            'actual' => $actual,
            'class' => static::class,
        ]);

        return $fallback;
    }

    /**
     * 連想配列の値を$shapeで検査する。未設定はnull、形が合わなければ形の不一致として扱う。
     *
     * @template T of array
     *
     * @param Closure(array<string, mixed>): (T|null) $shape
     *
     * @return T|null
     */
    private function acfShape(string $name, string $expected, Closure $shape): ?array
    {
        $value = $this->acfNullableArray($name);

        if ($value === null) {
            return null;
        }

        return $shape($value) ?? $this->acfMismatch($name, $expected, 'array of another shape', null);
    }

    /**
     * listの要素を$itemで検査する。$itemがnullを返した要素は形の不一致として扱い、本番では記録して除く。
     *
     * @template T
     *
     * @param Closure(mixed): (T|null) $item
     *
     * @return list<T>
     */
    private function acfListOf(string $name, string $expected, Closure $item): array
    {
        $items = [];

        foreach ($this->acfList($name) as $index => $element) {
            $typed = $item($element);

            if ($typed === null) {
                $this->acfMismatch("{$name}[{$index}]", $expected, \is_array($element) ? 'array of another shape' : get_debug_type($element), null);
                continue;
            }

            $items[] = $typed;
        }

        return $items;
    }

    /**
     * 連想配列の形の検査を、listの要素の検査(連想配列でない要素はnull)にする。
     *
     * @template T of array
     *
     * @param Closure(array<string, mixed>): (T|null) $shape
     *
     * @return Closure(mixed): (T|null)
     */
    private function arrayItem(Closure $shape): Closure
    {
        return static function (mixed $item) use ($shape): ?array {
            $assoc = \is_array($item) ? self::stringKeyedArray($item) : null;

            return $assoc === null ? null : $shape($assoc);
        };
    }

    /**
     * acf_get_attachment()の画像の配列。width・heightは、WordPressが画像の寸法を返せないとき(SVG等)に無い。
     *
     * @param array<string, mixed> $value
     *
     * @return AcfImage|null
     */
    private function acfImageFromArray(array $value): ?array
    {
        $file = $this->acfFileFromArray($value);
        $sizes = $this->imageSizes($value);

        if ($file === null || $sizes === null) {
            return null;
        }

        $image = $file;

        // acf_get_attachment()と同じ順(width・height・sizes)にする
        if (\array_key_exists('width', $value)) {
            $width = $this->arrayIntValue($value, 'width');

            if ($width === null) {
                return null;
            }

            $image['width'] = $width;
        }

        if (\array_key_exists('height', $value)) {
            $height = $this->arrayIntValue($value, 'height');

            if ($height === null) {
                return null;
            }

            $image['height'] = $height;
        }

        $image['sizes'] = $sizes;

        return $image;
    }

    /**
     * acf_get_attachment()の添付ファイルの配列。urlはwp_get_attachment_url()が、iconはwp_mime_type_icon()がfalseを返しうる。
     *
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
        $url = $this->arrayStringOrFalseValue($value, 'url');
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
        $icon = $this->arrayStringOrFalseValue($value, 'icon');

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
     * ACFのuserフィールド(return_format=array)の配列。user_avatarはget_avatar()が(アバターの表示を無効にしたサイト等で)
     * falseを返しうる。
     *
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
        $avatar = $this->arrayStringOrFalseValue($value, 'user_avatar');

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
     * キーがすべて文字列の配列ならそのまま返す。そうでなければnull。
     *
     * @param array<array-key, mixed> $value
     *
     * @return array<string, mixed>|null
     */
    private static function stringKeyedArray(array $value): ?array
    {
        $typed = [];

        foreach ($value as $key => $item) {
            if (!\is_string($key)) {
                return null;
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
     * 文字列またはfalseの値を返す。キーが無い・ほかの型ならnull。
     *
     * @param array<string, mixed> $value
     */
    private function arrayStringOrFalseValue(array $value, string $key): string|false|null
    {
        $item = $value[$key] ?? null;

        return \is_string($item) || $item === false ? $item : null;
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return array<string, int|string>|null
     */
    private function imageSizes(array $value): ?array
    {
        $sizes = $value['sizes'] ?? null;

        if (!\is_array($sizes)) {
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
