<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Unit;

use CarMoneyLab\Domain\ApplicationValidator;
use CarMoneyLab\Domain\AssessmentService;
use CarMoneyLab\Domain\DecisionEngine;
use CarMoneyLab\Domain\LtvCalculator;
use CarMoneyLab\Domain\VehicleAge;
use CarMoneyLab\Domain\VinValidator;
use CarMoneyLab\Http\ApplicationController;
use CarMoneyLab\Repository\ApplicationRepository;
use CarMoneyLab\Tests\Support\MysqlErrors;
use CarMoneyLab\Tests\Support\StubPdo;
use PDOException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * POST /api/applications целиком: 201, 409 и неизменный 422.
 *
 * Вместо базы — PDO-двойка: она пишет настоящий AssessmentService, а запросы
 * к хранилищу не доходят до MySQL.
 */
final class ApplicationControllerCreateTest extends TestCase
{
    public function testReturns201WithSavedApplication(): void
    {
        $pdo = new StubPdo(null, [], '42');

        $response = $this->create($this->controller($pdo), self::payload());

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(42, $this->body($response)['id'] ?? null);
        self::assertSame('approve', $this->body($response)['decision'] ?? null);
    }

    /** AC-2: у клиента уже есть активная заявка — 409 с телом, которое умеет рисовать фронт. */
    public function testReturns409WhenClientAlreadyHasActiveApplication(): void
    {
        $pdo = new StubPdo(MysqlErrors::duplicateEntryOnActiveRef('CL-5001'));

        $response = $this->create($this->controller($pdo), self::payload());

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));

        $errors = $this->body($response)['errors'] ?? [];
        self::assertArrayHasKey('applicant_ref', $errors);
        self::assertNotSame('', $errors['applicant_ref']);
    }

    /** Ошибка 409 не должна выглядеть как 422: фронт рисует оба одинаково, код ответа разный. */
    public function testDoesNotSaveAnythingOnValidationError(): void
    {
        $pdo = new StubPdo();

        $response = $this->create($this->controller($pdo), ['applicant_ref' => 'CL-5001', 'vin' => 'SHORT']);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('vin', $this->body($response)['errors'] ?? []);
        self::assertSame([], $pdo->preparedQueries);
    }

    public function testDoesNotTurnOtherStorageFailuresInto409(): void
    {
        $pdo = new StubPdo(MysqlErrors::connectionFailed());

        $this->expectException(PDOException::class);

        $this->create($this->controller($pdo), self::payload());
    }

    /** @return array<string,mixed> */
    private static function payload(): array
    {
        return [
            'vin' => 'XTA21099998765432',
            'year' => (int) date('Y') - 4,
            'mileage' => 96000,
            'market_value' => 900000,
            'requested_amount' => 450000,
            'term_months' => 24,
            'applicant_ref' => 'CL-5001',
        ];
    }

    private function controller(StubPdo $pdo): ApplicationController
    {
        $rules = require __DIR__ . '/../../backend/config/rules.php';
        $age = new VehicleAge((int) date('Y'));

        return new ApplicationController(
            new AssessmentService(
                new ApplicationValidator($rules, new VinValidator($rules['vin']), $age),
                new LtvCalculator(),
                new DecisionEngine($rules['ltv'], $rules['vehicle']['review_max_mileage_km']),
                $age,
            ),
            new ApplicationRepository($pdo),
        );
    }

    /** @param array<string,mixed> $payload */
    private function create(ApplicationController $controller, array $payload): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/applications')
            ->withParsedBody($payload);

        return $controller->create($request, (new ResponseFactory())->createResponse());
    }

    /** @return array<string,mixed> */
    private function body(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }
}