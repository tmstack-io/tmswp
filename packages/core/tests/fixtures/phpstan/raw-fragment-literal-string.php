<?php

declare(strict_types=1);

namespace TMSWP\Core\Tests\Fixtures\PHPStan\RawFragment;

use TMSWP\Core\Model\QueryBuilder;

/**
 * raw断片の引数(docs/06 R2の規約4)に、PHPの変数を連結した断片を渡す。5つの引数のすべてでPHPStanのエラーになる。
 */
function variable_fragments(QueryBuilder $query, string $column, string $table): void
{
    $query->whereRaw("{$column} = %s", ['a']);
    $query->orWhereRaw($column . ' = %s', ['a']);
    $query->selectRaw("COUNT({$column}) AS total");
    $query->innerJoinRaw('users AS u', "u.ID = {$table}.user_id");
    $query->leftJoinRaw('users AS u', 'u.ID = ' . $table . '.user_id');
}

/**
 * リテラルの断片(リテラル同士の連結・リテラルの書式のsprintfを含む)と、識別子を%iで渡す断片はエラーにならない。
 */
function literal_fragments(QueryBuilder $query, string $column): void
{
    $query->whereRaw('%i = %s', [$column, 'a']);
    $query->orWhereRaw('status = ' . '%s', ['a']);
    $query->selectRaw(\sprintf('COUNT(%s) AS total', 'id'));
    $query->innerJoinRaw('users AS u', 'u.ID = contacts.user_id');
    $query->leftJoinRaw('users AS u', 'u.ID = contacts.user_id AND u.user_status = %d', [0]);
}
