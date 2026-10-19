<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Audit\AuditLog;
use App\Billing\Money;
use App\Billing\PlanRepository;
use App\Billing\PromoCode;
use App\Billing\PromoCodeRepository;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\ValidationException;
use App\Http\AdminController;

/** Промокоды и скидки. */
final class PromoCodeController extends AdminController
{
    public function __construct(
        private readonly PromoCodeRepository $promos,
        private readonly PlanRepository $plans,
        private readonly AuditLog $audit,
    ) {
    }

    public function index(): Response
    {
        return $this->admin('promocodes/index', ['title' => 'Промокоды', 'section' => 'promocodes', 'items' => $this->promos->all(), 'discounts' => $this->promos->discountTotals()]);
    }

    public function create(): Response
    {
        return $this->form(new PromoCode(), 'Новый промокод');
    }

    public function edit(int $id): Response
    {
        $promo = $this->promos->find($id) ?? $this->notFound();

        return $this->form($promo, 'Промокод ' . $promo->code);
    }

    public function save(Request $request, ?int $id = null): Response
    {
        $data = $this->validate($request, [
            'code' => 'required|string|min:3|max:40|regex:/^[A-Za-z0-9_-]+$/',
            'description' => 'nullable|string|max:200',
            'discount_type' => 'required|in:percent,fixed',
            'discount_value' => 'required|string',
            'max_uses' => 'nullable|int|gte:1',
            'per_user_limit' => 'nullable|int|gte:1|lte:100',
            'valid_from' => 'nullable|string',
            'valid_until' => 'nullable|string',
        ], ['code' => 'Код', 'discount_value' => 'Размер скидки']);
        $value = $data['discount_type'] === 'percent' ? (int) $data['discount_value'] : Money::fromInput((string) $data['discount_value']);
        if ($data['discount_type'] === 'percent' && ($value < 1 || $value > 100)) {
            throw new ValidationException(['discount_value' => 'Скидка в процентах — от 1 до 100']);
        }
        if ($value < 1) {
            throw new ValidationException(['discount_value' => 'Укажите размер скидки']);
        }
        if ($this->promos->codeExists((string) $data['code'], $id)) {
            throw new ValidationException(['code' => 'Такой промокод уже существует']);
        }
        $savedId = $this->promos->save($id, array_merge($data, [
            'discount_value' => $value,
            'plan_ids' => (array) $request->input('plan_ids', []),
            'first_payment_only' => $request->bool('first_payment_only'),
            'is_active' => $request->bool('is_active'),
        ]));
        $this->audit->record($id === null ? 'promo.created' : 'promo.updated', $this->requireUser()->id, 'promo', $savedId);
        $this->flash('success', 'Промокод сохранён');

        return $this->redirect('/admin/promocodes');
    }

    public function delete(Request $request, int $id): Response
    {
        $this->promos->delete($id);
        $this->audit->record('promo.deleted', $this->requireUser()->id, 'promo', $id);

        return $this->done($request, 'Промокод удалён', '/admin/promocodes');
    }

    private function form(PromoCode $promo, string $title): Response
    {
        return $this->admin('promocodes/form', ['title' => $title, 'section' => 'promocodes', 'promo' => $promo, 'plans' => $this->plans->all()]);
    }
}
