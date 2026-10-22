<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Support;

use PDOException;
use PDOStatement;

/**
 * Выражение-подделка: execute() или отрабатывает, или бросает заданную ошибку БД.
 */
final class StubStatement extends PDOStatement
{
    /** @var list<array<string,mixed>> параметры каждого execute() */
    public array $bindings = [];

    private int $cursor = 0;

    /** @param list<mixed> $fetchColumnValues значения, которые по очереди вернёт fetchColumn() */
    public function __construct(
        public readonly string $query,
        private readonly ?PDOException $executeFailsWith = null,
        private readonly array $fetchColumnValues = [],
    ) {
    }

    /** @param array<string,mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        $this->bindings[] = $params ?? [];

        if ($this->executeFailsWith !== null) {
            throw $this->executeFailsWith;
        }

        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->fetchColumnValues[$this->cursor++] ?? false;
    }
}