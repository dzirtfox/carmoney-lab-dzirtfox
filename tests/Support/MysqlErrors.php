<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Support;

use PDOException;
use ReflectionProperty;

/**
 * Ошибки MySQL 8 в том виде, в каком их отдаёт PDO.
 *
 * Тексты сняты с настоящего MySQL 8 на стенде проверки:
 *   ERROR 1062 (23000): Duplicate entry 'CL-5001' for key 'applications.uq_applications_active_ref'
 */
final class MysqlErrors
{
    /** Настоящий PDO кладёт в code SQLSTATE строкой ('23000'), поэтому ставим её reflection'ом. */
    public static function duplicateEntryOnActiveRef(string $applicantRef): PDOException
    {
        return self::withSqlState(
            sprintf(
                "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '%s' for key 'applications.uq_applications_active_ref'",
                $applicantRef,
            ),
            '23000',
        );
    }

    /** То же нарушение уникальности, но по другому ключу — доменным исключением становиться не должен. */
    public static function duplicateEntryOnAnotherKey(int $id): PDOException
    {
        return self::withSqlState(
            sprintf(
                "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '%d' for key 'applications.PRIMARY'",
                $id,
            ),
            '23000',
        );
    }

    public static function foreignKeyViolation(): PDOException
    {
        return self::withSqlState(
            'SQLSTATE[23001]: Integrity constraint violation: 1452 Cannot add or update a child row: a foreign key constraint fails',
            '23001',
        );
    }

    public static function connectionFailed(): PDOException
    {
        return self::withSqlState('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', 'HY000');
    }

    private static function withSqlState(string $message, string $sqlState): PDOException
    {
        $exception = new PDOException($message);
        (new ReflectionProperty(\Exception::class, 'code'))->setValue($exception, $sqlState);

        return $exception;
    }
}