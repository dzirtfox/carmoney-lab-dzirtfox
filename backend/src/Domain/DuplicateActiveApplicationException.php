<?php

declare(strict_types=1);

namespace CarMoneyLab\Domain;

use RuntimeException;
use Throwable;

/**
 * У клиента уже есть активная заявка, поэтому новую не принимаем.
 *
 * Активной считается заявка со статусом, отличным от 'cancelled'
 * (решение риск-менеджмента B1). Исключение поднимает хранилище:
 * MySQL отдаёт SQLSTATE 23000 по ключу uq_applications_active_ref,
 * когда клиент пытается занять уже занятый слот.
 */
final class DuplicateActiveApplicationException extends RuntimeException
{
    public function __construct(
        private readonly string $applicantRef,
        ?Throwable $previous = null,
    ) {
        parent::__construct(sprintf('У клиента %s уже есть активная заявка', $applicantRef), 0, $previous);
    }

    public function applicantRef(): string
    {
        return $this->applicantRef;
    }

    /**
     * Тело ответа для HTTP 409: ключ errors читает фронт (frontend/app.js).
     *
     * @return array<string,string>
     */
    public function errors(): array
    {
        return ['applicant_ref' => 'По этому клиенту уже есть активная заявка'];
    }
}