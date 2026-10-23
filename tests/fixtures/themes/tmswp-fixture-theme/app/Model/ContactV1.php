<?php

declare(strict_types=1);

namespace TmswpFixtureTheme\Model;

use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\TableDefinition;

/**
 * 前回のデプロイ時点の Contact のテーブル定義。#[AsModel] を付けないため Kernel には登録されない。
 * Migrator は登録時の定義を保持するため、1プロセス内で定義の変更を再現できない。そこでテストは、
 * この定義で移行した DB(前回のデプロイ後の状態)を用意してから、Contact の定義で移行させる。
 */
final class ContactV1 extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_fixture_contacts')
            ->id()
            ->string('email')
            ->bool('active')
            ->json('meta')
            ->datetime('created_at')
            ->string('status')
            ->unique('email', 'email_unique');
    }

    public static function rowClass(): ?string
    {
        return null;
    }
}
