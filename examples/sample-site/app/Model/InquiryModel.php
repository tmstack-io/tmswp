<?php

declare(strict_types=1);

namespace App\Model;

use TMSWP\Core\Attribute\AsModel;
use TMSWP\Core\Model\Model;
use TMSWP\Core\Model\TableDefinition;

#[AsModel]
final class InquiryModel extends Model
{
    public static function table(): TableDefinition
    {
        return TableDefinition::create('sample_inquiries')
            ->id()
            ->string('customer_name')
            ->string('email')
            ->text('message')
            ->bigint('attachment_id', nullable: true, unsigned: true)
            ->string('attachment_name', nullable: true)
            ->datetime('created_at')
            ->index('email');
    }
}
