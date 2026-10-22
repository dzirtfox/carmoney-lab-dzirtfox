<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Support;

use PDO;
use PDOException;
use PDOStatement;

/**
 * PDO-двойник для тестов слоя хранения: реальная база не нужна.
 *
 * Каждый prepare() отдаёт StubStatement, который на execute() бросает заранее
 * заданное исключение (либо, если его нет, просто отрабатывает), а fetchColumn()
 * возвращает заранее заданные значения.
 */
final class StubPdo extends PDO
{
    /** @var list<string> тексты, которые до этого просили подготовить */
    public array $preparedQueries = [];

    /** @var list<StubStatement> подготовленные выражения */
    public array $statements = [];

    /** @param list<mixed> $fetchColumnValues значения, которые по очереди вернёт fetchColumn() */
    public function __construct(
        private readonly ?PDOException $executeFailsWith = null,
        private readonly array $fetchColumnValues = [],
        private readonly string|false $insertId = '1',
    ) {
        // Конструктор родителя намеренно не зовём: подключения к БД тут нет,
        // а все методы, которые трогает репозиторий, переопределены ниже.
    }

    /** @param array<int,mixed> $options */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->preparedQueries[] = $query;
        $statement = new StubStatement($query, $this->executeFailsWith, $this->fetchColumnValues);
        $this->statements[] = $statement;

        return $statement;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->insertId;
    }
}