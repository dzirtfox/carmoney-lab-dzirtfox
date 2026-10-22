<?php

declare(strict_types=1);

namespace CarMoneyLab\Http;

use CarMoneyLab\Domain\AssessmentService;
use CarMoneyLab\Domain\DuplicateActiveApplicationException;
use CarMoneyLab\Domain\ValidationException;
use CarMoneyLab\Repository\ApplicationRepository;
use CarMoneyLab\Support\Json;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ApplicationController
{
    public function __construct(
        private readonly AssessmentService $assessment,
        private readonly ApplicationRepository $repository,
    ) {
    }

    /** POST /api/applications — приём заявки и решение по ней */
    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = (array) ($request->getParsedBody() ?? []);

        try {
            $result = $this->assessment->assess($payload);
        } catch (ValidationException $exception) {
            return Json::write($response, ['errors' => $exception->errors()], 422);
        }

        // applicant_ref не валидируется, поэтому заявки без этого поля получают один
        // общий идентификатор CL-ANON: у такого клиента может быть только одна активная
        // заявка, и она держит слот до отмены. Само поле здесь не меняем (это меняет
        // контракт и вне объёма фичи), риск зафиксирован в docs/plan/plan_DUP_ACTIVE.md.
        $applicantRef = (string) ($payload['applicant_ref'] ?? 'CL-ANON');

        try {
            $id = $this->repository->save($applicantRef, $result['input'], $result);
        } catch (DuplicateActiveApplicationException $exception) {
            // Ключ errors читает фронт (frontend/app.js), поэтому тело — такой же JSON, как у 422.
            return Json::write($response, ['errors' => $exception->errors()], 409);
        }

        error_log(sprintf(
            'application=%d ltv=%s decision=%s limit=%d',
            $id,
            $result['ltv'],
            $result['decision'],
            $result['approved_limit'],
        ));

        return Json::write($response, [
            'id' => $id,
            'vehicle_age' => $result['vehicle_age'],
            'ltv' => $result['ltv'],
            'decision' => $result['decision'],
            'approved_limit' => $result['approved_limit'],
        ], 201);
    }

    /** POST /api/ltv — расчёт LTV и решения без сохранения заявки */
    public function ltv(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = (array) ($request->getParsedBody() ?? []);

        try {
            $result = $this->assessment->assess($payload);
        } catch (ValidationException $exception) {
            return Json::write($response, ['errors' => $exception->errors()], 422);
        }

        return Json::write($response, [
            'vehicle_age' => $result['vehicle_age'],
            'ltv' => $result['ltv'],
            'decision' => $result['decision'],
            'approved_limit' => $result['approved_limit'],
        ]);
    }

    /** GET /api/applications/{id} — карточка заявки */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $row = $this->repository->find((int) $args['id']);

        // Если заявки нет, отдаём пустой объект — фронт тогда просто ничего не рисует.
        return Json::write($response, $row ?: (object) []);
    }

    /** GET /api/applications?status=decided — список заявок */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $status = $request->getQueryParams()['status'] ?? null;

        return Json::write($response, [
            'items' => $this->repository->listApplications($status !== null ? (string) $status : null),
        ]);
    }
}
