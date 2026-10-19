<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Audit\AuditLog;
use App\Billing\Money;
use App\Billing\Plan;
use App\Billing\PlanPeriod;
use App\Billing\PlanRepository;
use App\Billing\PlanTier;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\ValidationException;
use App\Http\AdminController;

/** Управление тарифами. */
final class PlanController extends AdminController
{
    public function __construct(
        private readonly PlanRepository $plans,
        private readonly AuditLog $audit,
    ) {
    }

    public function index(): Response
    {
        return $this->admin('plans/index', ['title' => 'Тарифы', 'section' => 'plans', 'items' => $this->plans->all()]);
    }

    public function create(): Response
    {
        return $this->form(new Plan(), 'Новый тариф');
    }

    public function edit(int $id): Response
    {
        $plan = $this->plans->find($id) ?? $this->notFound();

        return $this->form($plan, 'Тариф: ' . $plan->title());
    }

    public function save(Request $request, ?int $id = null): Response
    {
        $data = $this->validate($request, [
            'code' => 'required|string|max:40|regex:/^[a-z0-9-]+$/',
            'name' => 'required|string|max:100',
            'tier' => 'required|in:digital,premium',
            'period' => 'required|in:month,year',
            'price' => 'required|string',
            'trial_days' => 'nullable|int|gte:0|lte:60',
            'description' => 'nullable|string|max:500',
            'sort_order' => 'nullable|int',
        ], ['code' => 'Код', 'name' => 'Название', 'price' => 'Цена', 'trial_days' => 'Пробный период']);
        $price = Money::fromInput((string) $data['price']);
        if ($price < 100) {
            throw new ValidationException(['price' => 'Минимальная цена — 1 ₽']);
        }
        if ($this->plans->codeExists((string) $data['code'], $id)) {
            throw new ValidationException(['code' => 'Тариф с таким кодом уже существует']);
        }
        $features = preg_split('/\R/u', $request->string('features')) ?: [];
        $savedId = $this->plans->save($id, array_merge($data, [
            'price' => $price,
            'features' => $features,
            'is_active' => $request->bool('is_active'),
        ]));
        $this->audit->record($id === null ? 'plan.created' : 'plan.updated', $this->requireUser()->id, 'plan', $savedId, ['price' => $price]);
        $this->flash('success', 'Тариф сохранён. Изменение цены не затрагивает уже оформленные подписки до их продления.');

        return $this->redirect('/admin/plans');
    }

    public function delete(Request $request, int $id): Response
    {
        $plan = $this->plans->find($id) ?? $this->notFound();
        if ($this->plans->hasSubscriptions($plan->id)) {
            throw new \DomainException('По тарифу есть подписки или платежи — его можно только отключить (снять «Продаётся»)');
        }
        $this->plans->deleteById($plan->id);
        $this->audit->record('plan.deleted', $this->requireUser()->id, 'plan', $id);

        return $this->done($request, 'Тариф удалён', '/admin/plans');
    }

    private function form(Plan $plan, string $title): Response
    {
        return $this->admin('plans/form', [
            'title' => $title,
            'section' => 'plans',
            'plan' => $plan,
            'tiers' => PlanTier::cases(),
            'periods' => PlanPeriod::cases(),
        ]);
    }
}
