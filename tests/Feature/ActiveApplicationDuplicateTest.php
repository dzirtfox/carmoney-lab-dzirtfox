<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Feature;

use CarMoneyLab\Database;
use CarMoneyLab\Domain\DuplicateActiveApplicationException;
use CarMoneyLab\Repository\ApplicationRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Ограничение «одна активная заявка на клиента» на настоящей MySQL.
 *
 * Тест пишет в базу, поэтому по умолчанию пропускается. Запуск на своём стенде:
 *
 *   docker compose run --rm --no-deps \
 *     -e CARMONEY_FEATURE_DB=1 backend vendor/bin/phpunit --testsuite feature
 *
 * DSN, пользователь и пароль берутся из окружения (Database::connect()).
 * Данные синтетические: клиент на каждый тест свой, строки за собой удаляются.
 * Настоящую одновременность двух HTTP-запросов по инструкции из
 * db/migrations/002_active_application_unique.sql проверяют руками.
 */
final class ActiveApplicationDuplicateTest extends TestCase
{
    /** @var array{vin:string,year:int,mileage:int,market_value:int,requested_amount:int,term_months:int} */
    private const INPUT = [
        'vin' => 'XTA21099998765432',
        'year' => 2022,
        'mileage' => 96000,
        'market_value' => 900000,
        'requested_amount' => 450000,
        'term_months' => 24,
    ];

    /** @var array{ltv:float,decision:string,approved_limit:int} */
    private const ASSESSMENT = ['ltv' => 50.0, 'decision' => 'approve', 'approved_limit' => 450000];

    private PDO $pdo;

    private ApplicationRepository $repository;

    private string $applicantRef;

    protected function setUp(): void
    {
        if (getenv('CARMONEY_FEATURE_DB') !== '1') {
            self::markTestSkipped('Нужен реальный MySQL: DB_DSN, DB_USER, DB_PASSWORD и CARMONEY_FEATURE_DB=1');
        }

        try {
            $this->pdo = Database::connect();
        } catch (PDOException $exception) {
            self::markTestSkipped('MySQL недоступна: ' . $exception->getMessage());
        }

        $this->repository = new ApplicationRepository($this->pdo);
        $this->applicantRef = 'CL-T' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->applicantRef)) {
            return;
        }

        $delete = $this->pdo->prepare('DELETE FROM applications WHERE applicant_ref = :ref');
        $delete->execute([':ref' => $this->applicantRef]);
    }

    public function testSchemaKeepsUniqueSlotForActiveApplication(): void
    {
        $statement = $this->pdo->prepare(
            "SELECT NON_UNIQUE, COLUMN_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'applications'
                AND INDEX_NAME = 'uq_applications_active_ref'"
        );
        $statement->execute();

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $rows);
        self::assertSame(0, (int) $rows[0]['NON_UNIQUE']);
        self::assertSame('active_ref', $rows[0]['COLUMN_NAME']);
    }

    public function testSavesFirstApplicationOfClient(): void
    {
        $id = $this->repository->save($this->applicantRef, self::INPUT, self::ASSESSMENT);

        self::assertGreaterThan(0, $id);
        self::assertTrue($this->repository->hasActiveApplication($this->applicantRef));
    }

    /** AC-2 на настоящей MySQL: 1062 по uq_applications_active_ref распознаётся как дубль активной заявки. */
    public function testRejectsSecondActiveApplicationOfClient(): void
    {
        $this->repository->save($this->applicantRef, self::INPUT, self::ASSESSMENT);

        try {
            $this->repository->save($this->applicantRef, self::INPUT, self::ASSESSMENT);
            self::fail('Ожидался DuplicateActiveApplicationException');
        } catch (DuplicateActiveApplicationException $exception) {
            self::assertSame($this->applicantRef, $exception->applicantRef());
            self::assertInstanceOf(PDOException::class, $exception->getPrevious());
            self::assertSame('23000', (string) $exception->getPrevious()?->getCode());
            self::assertStringContainsString(
                'uq_applications_active_ref',
                $exception->getPrevious()?->getMessage() ?? '',
            );
        }
    }

    /** AC-3: закрываем предыдущую заявку прямой правкой в базе — механизма закрытия в продукте нет (решение B2). */
    public function testAllowsNewApplicationAfterPreviousOneIsCancelled(): void
    {
        $first = $this->repository->save($this->applicantRef, self::INPUT, self::ASSESSMENT);
        $this->cancel($first);

        $second = $this->repository->save($this->applicantRef, self::INPUT, self::ASSESSMENT);

        self::assertNotSame($first, $second);
        self::assertSame(1, $this->activeCount());
    }

    /**
     * Инвариант AC-4: сколько бы запросов ни пришло, активная заявка у клиента одна.
     * Само столкновение настоящих одновременных запросов проверяется руками —
     * решает его уникальный ключ, а не код.
     */
    public function testKeepsSingleActiveApplicationAfterRejectedDuplicate(): void
    {
        $this->repository->save($this->applicantRef, self::INPUT, self::ASSESSMENT);

        try {
            $this->repository->save($this->applicantRef, self::INPUT, self::ASSESSMENT);
            self::fail('Ожидался DuplicateActiveApplicationException');
        } catch (DuplicateActiveApplicationException) {
            self::assertSame(1, $this->activeCount());
        }
    }

    private function cancel(int $applicationId): void
    {
        $statement = $this->pdo->prepare("UPDATE applications SET status = 'cancelled' WHERE id = :id");
        $statement->execute([':id' => $applicationId]);
    }

    private function activeCount(): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM applications WHERE applicant_ref = :ref AND status <> 'cancelled'"
        );
        $statement->execute([':ref' => $this->applicantRef]);

        return (int) $statement->fetchColumn();
    }
}