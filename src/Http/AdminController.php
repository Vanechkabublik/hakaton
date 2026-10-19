<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\Http\Request;
use App\Core\Http\Response;

/** Базовый класс контроллеров админ-панели. */
abstract class AdminController extends Controller
{
    protected const PER_PAGE = 25;

    /**
     * Параметры пагинации: [номер страницы, смещение].
     *
     * @return array{0:int,1:int}
     */
    protected function paging(Request $request, int $perPage = self::PER_PAGE): array
    {
        $page = max(1, $request->int('page', 1));

        return [$page, ($page - 1) * $perPage];
    }

    /** @param array<string,mixed> $data */
    protected function admin(string $template, array $data = [], int $status = 200): Response
    {
        return $this->view('admin/' . $template, $data, $status);
    }

    /** Значения фильтров из query-строки (только заданные ключи, строки). @param list<string> $keys @return array<string,string> */
    protected function filters(Request $request, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $value = $request->query($key);
            if (is_string($value) && $value !== '') {
                $result[$key] = mb_substr($value, 0, 200);
            }
        }

        return $result;
    }

    /** Подтверждение действия в журнале: "ок" через flash и возврат на страницу. */
    protected function done(Request $request, string $message, string $fallback = '/admin'): Response
    {
        $this->flash('success', $message);

        return $this->back($request, $fallback);
    }
}
