<?php

declare(strict_types=1);

namespace TMSWP\Core\Label;

final class LabelGenerator
{
    /** @return array<string, string> */
    public static function forPostType(string $label, LabelLanguage $language): array
    {
        return self::expand($label, match ($language) {
            LabelLanguage::Ja => [
                'name' => '%s',
                'singular_name' => '%s',
                'add_new' => '新規追加',
                'add_new_item' => '新規%sを追加',
                'edit_item' => '%sを編集',
                'new_item' => '新規%s',
                'view_item' => '%sを表示',
                'view_items' => '%s一覧を表示',
                'search_items' => '%sを検索',
                'not_found' => '%sが見つかりませんでした',
                'not_found_in_trash' => 'ゴミ箱内に%sが見つかりませんでした',
                'parent_item_colon' => '親%s:',
                'all_items' => '%s一覧',
                'archives' => '%sアーカイブ',
                'attributes' => '%sの属性',
                'insert_into_item' => '%sに挿入',
                'uploaded_to_this_item' => 'この%sへのアップロード',
                'filter_items_list' => '%s一覧を絞り込む',
                'items_list_navigation' => '%s一覧ナビゲーション',
                'items_list' => '%s一覧',
                'item_published' => '%sを公開しました',
                'item_updated' => '%sを更新しました',
            ],
            LabelLanguage::En => [
                'name' => '%s',
                'singular_name' => '%s',
                'add_new' => 'Add New',
                'add_new_item' => 'Add New %s',
                'edit_item' => 'Edit %s',
                'new_item' => 'New %s',
                'view_item' => 'View %s',
                'view_items' => 'View %s',
                'search_items' => 'Search %s',
                'not_found' => 'No %s found.',
                'not_found_in_trash' => 'No %s found in Trash.',
                'parent_item_colon' => 'Parent %s:',
                'all_items' => 'All %s',
                'archives' => '%s Archives',
                'attributes' => '%s Attributes',
                'insert_into_item' => 'Insert into %s',
                'uploaded_to_this_item' => 'Uploaded to this %s',
                'filter_items_list' => 'Filter %s list',
                'items_list_navigation' => '%s list navigation',
                'items_list' => '%s list',
                'item_published' => '%s published.',
                'item_updated' => '%s updated.',
            ],
        });
    }

    /** @return array<string, string> */
    public static function forTaxonomy(string $label, LabelLanguage $language): array
    {
        return self::expand($label, match ($language) {
            LabelLanguage::Ja => [
                'name' => '%s',
                'singular_name' => '%s',
                'search_items' => '%sを検索',
                'popular_items' => 'よく使われている%s',
                'all_items' => '%s一覧',
                'parent_item' => '親%s',
                'parent_item_colon' => '親%s:',
                'edit_item' => '%sを編集',
                'view_item' => '%sを表示',
                'update_item' => '%sを更新',
                'add_new_item' => '新規%sを追加',
                'new_item_name' => '新規%s名',
                'separate_items_with_commas' => '複数の%sはコンマで区切ってください',
                'add_or_remove_items' => '%sの追加または削除',
                'choose_from_most_used' => 'よく使われている%sから選択',
                'not_found' => '%sが見つかりませんでした',
                'no_terms' => '%sなし',
                'items_list_navigation' => '%s一覧ナビゲーション',
                'items_list' => '%s一覧',
                'back_to_items' => '← %s一覧へ戻る',
            ],
            LabelLanguage::En => [
                'name' => '%s',
                'singular_name' => '%s',
                'search_items' => 'Search %s',
                'popular_items' => 'Popular %s',
                'all_items' => 'All %s',
                'parent_item' => 'Parent %s',
                'parent_item_colon' => 'Parent %s:',
                'edit_item' => 'Edit %s',
                'view_item' => 'View %s',
                'update_item' => 'Update %s',
                'add_new_item' => 'Add New %s',
                'new_item_name' => 'New %s Name',
                'separate_items_with_commas' => 'Separate %s with commas',
                'add_or_remove_items' => 'Add or remove %s',
                'choose_from_most_used' => 'Choose from the most used %s',
                'not_found' => 'No %s found.',
                'no_terms' => 'No %s',
                'items_list_navigation' => '%s list navigation',
                'items_list' => '%s list',
                'back_to_items' => '← Back to %s',
            ],
        });
    }

    /**
     * @param array<string, string> $templates
     *
     * @return array<string, string>
     */
    private static function expand(string $label, array $templates): array
    {
        $labels = [];

        foreach ($templates as $key => $template) {
            $labels[$key] = \sprintf($template, $label);
        }

        return $labels;
    }
}
