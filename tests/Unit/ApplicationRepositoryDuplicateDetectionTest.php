<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Unit;

use CarMoneyLab\Domain\DuplicateActiveApplicationException;
use CarMoneyLab\Repository\ApplicationRepository;
use CarMoneyLab\Tests\Support\MysqlErrors;
use CarMoneyLab\Tests\Support\StubPdo;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Как репозиторий читает ошибку БД: отказ по слоту активной заявки становится
 * доменным исключением, всё остальное уходит наружу как есть.
 *
 * Ошибки MySQL подставляются готовыми (tests/Support/MysqlErrors.php), поэтому
 * тесту не нужна ни база, ни драйвер.
 */
final class ApplicationRepositoryDuplicateDetectionTest extends TestCase
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

    /** AC-2 на уровне хранилища: 1062 по uq_applications_active_ref -> доменное исключение. */
    public function testTurnsActiveSlotViolationIntoDomainException(): void
    {
        $pdo = new StubPdo(MysqlErrors::duplicateEntryOnActiveRef('CL-5001'));

        $this->expectException(DuplicateActiveApplicationException::class);

        (new ApplicationRepository($pdo))->save('CL-5001', self::INPUT, self::ASSESSMENT);
    }

    public function testKeepsApplicantRefOfRejectedApplication(): void
    {
        $repository = new ApplicationRepository(new StubPdo(MysqlErrors::duplicateEntryOnActiveRef('CL-5001')));

        try {
            $repository->save('CL-5001', self::INPUT, self::ASSESSMENT);
            self::fail('Ожидался DuplicateActiveApplicationException');
        } catch (DuplicateActiveApplicationException $exception) {
            self::assertSame('CL-5001', $exception->applicantRef());
            self::assertArrayHasKey('applicant_ref', $exception->errors());
            self::assertInstanceOf(PDOException::class, $exception->getPrevious());
        }
    }

    /** Отказ приходит на первом INSERT, поэтому half-записанной заявки не остаётся. */
    public function testStopsAtFirstInsertWhenActiveSlotIsTaken(): void
    {
        $pdo = new StubPdo(MysqlErrors::duplicateEntryOnActiveRef('CL-5001'));

        try {
            (new ApplicationRepository($pdo))->save('CL-5001', self::INPUT, self::ASSESSMENT);
            self::fail('Ожидался DuplicateActiveApplicationException');
        } catch (DuplicateActiveApplicationException) {
            self::assertCount(1, $pdo->statements);
            self::assertStringContainsString('INSERT INTO applications', $pdo->statements[0]->query);
        }
    }

    public function testRethrowsUniqueViolationOnAnotherKey(): void
    {
        $repository = new ApplicationRepository(new StubPdo(MysqlErrors::duplicateEntryOnAnotherKey(7)));

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage("for key 'applications.PRIMARY'");

        $repository->save('CL-5001', self::INPUT, self::ASSESSMENT);
    }

    public function testRethrowsForeignKeyViolation(): void
    {
        $repository = new ApplicationRepository(new StubPdo(MysqlErrors::foreignKeyViolation()));

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('foreign key constraint fails');

        $repository->save('CL-5001', self::INPUT, self::ASSESSMENT);
    }

    public function testRethrowsConnectionFailure(): void
    {
        $repository = new ApplicationRepository(new StubPdo(MysqlErrors::connectionFailed()));

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('MySQL server has gone away');

        $repository->save('CL-5001', self::INPUT, self::ASSESSMENT);
    }

    /** hasActiveApplication читает состояние хранилища, а не угадывает его. */
    public function testReadsActiveApplicationFromStorage(): void
    {
        $repository = new ApplicationRepository(new StubPdo(null, [1]));

        self::assertTrue($repository->hasActiveApplication('CL-5001'));
    }

    public function testFindsNoActiveApplicationWhenStorageIsEmpty(): void
    {
        $repository = new ApplicationRepository(new StubPdo());

        self::assertFalse($repository->hasActiveApplication('CL-5001'));
    }
}