<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Http\AdminController;
use App\Media\ImageException;
use App\Media\Media;
use App\Media\MediaRepository;
use App\Media\MediaService;
use App\User\Gate;

/** Медиатека: загрузка с ресайзом и конвертацией в WebP, выбор файлов в редакторе. */
final class MediaController extends AdminController
{
    private const PICKER_SIZE = 24;

    public function __construct(
        private readonly MediaRepository $media,
        private readonly MediaService $service,
        private readonly Gate $gate,
    ) {
    }

    public function index(Request $request): Response
    {
        [$page, $offset] = $this->paging($request, 36);
        $result = $this->media->search($request->string('q') ?: null, 36, $offset);
        $usage = [];
        foreach ($result['items'] as $item) {
            $usage[$item->id] = $this->media->usageCount($item->id);
        }

        return $this->admin('media/index', [
            'title' => 'Медиатека',
            'section' => 'media',
            'items' => $result['items'],
            'usage' => $usage,
            'total' => $result['total'],
            'query' => $request->string('q'),
            'size' => $this->media->totalSize(),
            'page' => $page,
            'pages' => (int) max(1, ceil($result['total'] / 36)),
            'canDelete' => $this->gate->allows($this->requireUser(), 'media.manage'),
        ]);
    }

    public function upload(Request $request): Response
    {
        $user = $this->requireUser();
        $files = $request->files('files');
        if ($files === []) {
            $single = $request->file('file');
            $files = $single ? [$single] : [];
        }
        if ($files === []) {
            throw new \DomainException('Выберите файлы для загрузки');
        }
        $items = [];
        $errors = [];
        foreach (array_slice($files, 0, 20) as $file) {
            try {
                $items[] = self::present($this->service->upload($file, $user, pathinfo($file->name, PATHINFO_FILENAME)));
            } catch (ImageException $e) {
                $errors[] = $file->name . ': ' . $e->getMessage();
            }
        }
        if ($request->expectsJson()) {
            return $this->json(['ok' => $items !== [], 'items' => $items, 'errors' => $errors, 'message' => $errors[0] ?? null], $items === [] ? 422 : 200);
        }
        foreach ($errors as $error) {
            $this->flash('error', $error);
        }
        if ($items !== []) {
            $this->flash('success', 'Загружено файлов: ' . count($items));
        }

        return $this->redirect('/admin/media');
    }

    /** JSON для диалога выбора изображений в редакторе. */
    public function picker(Request $request): Response
    {
        $this->requireUser();
        $page = max(1, $request->int('page', 1));
        $result = $this->media->search($request->string('q') ?: null, self::PICKER_SIZE, ($page - 1) * self::PICKER_SIZE);

        return $this->json([
            'ok' => true,
            'items' => array_map(self::present(...), $result['items']),
            'has_more' => $page * self::PICKER_SIZE < $result['total'],
        ]);
    }

    public function update(Request $request, int $id): Response
    {
        $media = $this->media->find($id) ?? $this->notFound();
        $this->media->updateById($media->id, ['alt' => mb_substr($request->string('alt'), 0, 300)]);

        return $this->done($request, 'Описание сохранено', '/admin/media');
    }

    public function delete(Request $request, int $id): Response
    {
        if (!$this->gate->allows($this->requireUser(), 'media.manage')) {
            throw HttpException::forbidden();
        }
        $media = $this->media->find($id) ?? $this->notFound();
        if ($this->media->usageCount($media->id) > 0) {
            throw new \DomainException('Файл используется в материалах — сначала уберите его оттуда');
        }
        $this->service->delete($media);

        return $this->done($request, 'Файл удалён', '/admin/media');
    }

    /** @return array<string,mixed> */
    private static function present(Media $media): array
    {
        return [
            'id' => $media->id,
            'name' => $media->originalName,
            'alt' => $media->alt,
            'width' => $media->width,
            'height' => $media->height,
            'thumb' => $media->url(320),
            'full' => $media->url(1280),
            'srcset' => $media->srcset(),
        ];
    }
}
