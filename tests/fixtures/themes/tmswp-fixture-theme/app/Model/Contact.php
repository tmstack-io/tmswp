<?php

declare(strict_types=1);

namespace TmswpFixtureTheme\Model;

use DateTimeImmutable;
use TMSWP\Core\Attribute\AsModel;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\TableDefinition;

/**
 * 現在のテーブル定義。前回のデプロイ時点の定義(ContactV1)に phone 列と索引を加えたもの。
 */
#[AsModel]
final class Contact extends Model
{
    /** @param array<mixed> $meta */
    public function __construct(
        public int $id,
        public string $email,
        public bool $active,
        public array $meta,
        public DateTimeImmutable $createdAt,
        public ContactStatus $status,
    ) {}

    public static function table(): TableDefinition
    {
        return TableDefinition::create('tmswp_fixture_contacts')
            ->id()
            ->string('email')
            ->bool('active')
            ->json('meta')
            ->datetime('created_at')
            ->string('status')
            ->string('phone', nullable: true)
            ->unique('email', 'email_unique')
            ->index('phone', 'phone_idx');
    }
}
