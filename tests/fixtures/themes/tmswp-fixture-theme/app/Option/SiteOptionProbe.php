<?php

declare(strict_types=1);

namespace TmswpFixtureTheme\Option;

/**
 * enum の OptionAccess 経由の読み書きを、アプリのコードと同じ書き方で実行する。
 */
final class SiteOptionProbe
{
    /** @return array{name: string, contact: string, maintenanceHas: bool, deleted: list<string>} */
    public static function exercise(): array
    {
        SiteOption::ContactEmail->set('probe@example.test');
        SiteOption::Maintenance->set(false);
        $deleted = SiteOption::deleteAll();
        SiteOption::ContactEmail->set('after-delete@example.test');

        return [
            'name' => SiteOption::ContactEmail->name(),
            'contact' => SiteOption::ContactEmail->string(),
            'maintenanceHas' => SiteOption::Maintenance->has(),
            'deleted' => $deleted,
        ];
    }
}
