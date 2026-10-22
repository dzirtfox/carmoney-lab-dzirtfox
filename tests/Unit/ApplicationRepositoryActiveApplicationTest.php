<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Unit;

use CarMoneyLab\Repository\ApplicationRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Слой хранения на SQLite в памяти: настоящая MySQL не нужна.
 *
 * Набор таблиц повторяет нужную часть db/schema.sql. В MySQL выражение
 * IF(status = 'cancelled', NULL, applicant_ref), в SQLite функции IF нет,
 * поэтому тот же смысл записан через CASE WHEN.
 */
final class ApplicationRepositoryActiveApplicationTest extends TestCase
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

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('Нет драйвера pdo_sqlite — тесты хранилища без MySQL не поедут');
        }

        $this->pdo = self::createStore();
        $this->repository = new ApplicationRepository($this->pdo);
    }

    /** Первая заявка клиента сохраняется и считается активной. */
    public function testSavesFirstApplicationOfClient(): void
    {
        $id = $this->repository->save('CL-5001', self::INPUT, self::ASSESSMENT);

        self::assertGreaterThan(0, $id);
        self::assertTrue($this->repository->hasActiveApplication('CL-5001'));
    }

    /** Рефакторинг save() не должен сломать запись авто и решения. */
    public function testStoresVehicleAndDecisionAlongWithApplication(): void
    {
        $id = $this->repository->save('CL-5001', self::INPUT, self::ASSESSMENT);

        $vehicle = $this->pdo
            ->query("SELECT vin, production_year FROM vehicles WHERE application_id = {$id}")
            ->fetch(PDO::FETCH_ASSOC);
        self::assertSame('XTA21099998765432', $vehicle['vin'] ?? null);

        $decision = $this->pdo
            ->query("SELECT decision, approved_limit FROM decisions WHERE application_id = {$id}")
            ->fetch(PDO::FETCH_ASSOC);
        self::assertSame('approve', $decision['decision'] ?? null);
        self::assertSame(450000, (int) ($decision['approved_limit'] ?? 0));
    }

    public function testFindsNoActiveApplicationForUnknownClient(): void
    {
        self::assertFalse($this->repository->hasActiveApplication('CL-5001'));
    }

    /** Закрытие заявки делаем прямой правкой в хранилище: продуктового механизма закрытия нет (решение B2). */
    public function testFindsNoActiveApplicationWhenOnlyClosedOnesLeft(): void
    {
        $id = $this->repository->save('CL-5001', self::INPUT, self::ASSESSMENT);
        $this->closeApplication($id);

        self::assertFalse($this->repository->hasActiveApplication('CL-5001'));
    }

    /** AC-3: после закрытия предыдущей клиент снова может подать заявку. */
    public function testAllowsNewApplicationAfterPreviousOneIsCancelled(): void
    {
        $first = $this->repository->save('CL-5001', self::INPUT, self::ASSESSMENT);
        $this->closeApplication($first);

        $second = $this->repository->save('CL-5001', self::INPUT, self::ASSESSMENT);

        self::assertNotSame($first, $second);
        self::assertSame(1, $this->activeCount('CL-5001'));
    }

    public function testDoesNotLetDifferentClientsBlockEachOther(): void
    {
        $this->repository->save('CL-5001', self::INPUT, self::ASSESSMENT);

        $second = $this->repository->save('CL-5002', self::INPUT, self::ASSESSMENT);

        self::assertGreaterThan(0, $second);
        self::assertTrue($this->repository->hasActiveApplication('CL-5001'));
        self::assertTrue($this->repository->hasActiveApplication('CL-5002'));
    }

    /**
     * Слой БД отдаёт отказ на второй активной заявке клиента — это и есть защита
     * от гонки (AC-4). Само распознавание ошибки проверяется на настоящей MySQL
     * в tests/Feature/ActiveApplicationDuplicateTest.php: текст ошибки SQLite
     * называет колонку, а не индекс, поэтому сверять его с uq_applications_active_ref нельзя.
     */
    public function testStorageRejectsSecondActiveApplicationOfSameClient(): void
    {
        $this->repository->save('CL-5001', self::INPUT, self::ASSESSMENT);

        try {
            $this->pdo->exec(
                "INSERT INTO applications (applicant_ref, requested_amount, term_months, status)
                 VALUES ('CL-5001', 450000, 24, 'decided')"
            );
            self::fail('Ожидался отказ по слоту активной заявки');
        } catch (PDOException $exception) {
            self::assertSame('23000', (string) $exception->getCode());
        }

        self::assertSame(1, $this->activeCount('CL-5001'));
    }

    private function closeApplication(int $id): void
    {
        $statement = $this->pdo->prepare("UPDATE applications SET status = 'cancelled' WHERE id = :id");
        $statement->execute([':id' => $id]);
    }

    private function activeCount(string $applicantRef): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM applications WHERE applicant_ref = :ref AND status <> 'cancelled'"
        );
        $statement->execute([':ref' => $applicantRef]);

        return (int) $statement->fetchColumn();
    }

    private static function createStore(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec(
            "CREATE TABLE applications (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                applicant_ref    TEXT    NOT NULL,
                requested_amount INTEGER NOT NULL,
                term_months      INTEGER NOT NULL,
                status           TEXT    NOT NULL,
                active_ref       TEXT    GENERATED ALWAYS AS (
                                    CASE WHEN status = 'cancelled' THEN NULL ELSE applicant_ref END
                                ) STORED
            )"
        );
        $pdo->exec('CREATE UNIQUE INDEX uq_applications_active_ref ON applications (active_ref)');
        $pdo->exec('CREATE INDEX idx_applications_applicant_ref ON applications (applicant_ref, status)');

        $pdo->exec(
            'CREATE TABLE vehicles (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                application_id  INTEGER NOT NULL,
                vin             TEXT    NOT NULL,
                production_year INTEGER NOT NULL,
                mileage_km      INTEGER NOT NULL,
                market_value    INTEGER NOT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE decisions (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                application_id INTEGER NOT NULL,
                ltv            REAL    NOT NULL,
                decision       TEXT    NOT NULL,
                approved_limit INTEGER NOT NULL
            )'
        );

        return $pdo;
    }
}