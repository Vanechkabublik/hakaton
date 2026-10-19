<?php

declare(strict_types=1);

namespace App\User;

use App\Article\Article;
use App\Article\ArticleStatus;

/** Проверка прав: минимальная роль для каждой возможности + правила уровня объекта. */
final class Gate
{
    /** @var array<string,Role> */
    private const ABILITIES = [
        'admin.access' => Role::Author,
        'articles.create' => Role::Author,
        'articles.edit_any' => Role::Editor,
        'articles.publish' => Role::Editor,
        'articles.delete' => Role::Editor,
        'media.upload' => Role::Author,
        'media.manage' => Role::Editor,
        'taxonomy.manage' => Role::Editor,
        'comments.moderate' => Role::Editor,
        'pages.manage' => Role::Editor,
        'dashboard.view' => Role::Editor,
        'finance.view' => Role::Admin,
        'users.manage' => Role::Admin,
        'payments.manage' => Role::Admin,
        'subscriptions.manage' => Role::Admin,
        'settings.manage' => Role::Admin,
        'newsletters.manage' => Role::Admin,
        'security.manage' => Role::Admin,
    ];

    public function allows(?User $user, string $ability): bool
    {
        if ($user === null || !$user->isActive()) {
            return false;
        }
        $required = self::ABILITIES[$ability] ?? Role::Admin;

        return $user->role->atLeast($required);
    }

    /** Может ли пользователь править статью: редактор+ — любую, автор — свои черновики. */
    public function canEditArticle(?User $user, Article $article): bool
    {
        if ($user === null || !$user->isActive()) {
            return false;
        }
        if ($this->allows($user, 'articles.edit_any')) {
            return true;
        }

        return $user->role === Role::Author
            && $article->authorId === $user->id
            && in_array($article->status, [ArticleStatus::Draft, ArticleStatus::Pending], true);
    }

    /** Автор видит и предпросматривает свои материалы в любом статусе. */
    public function canViewUnpublished(?User $user, Article $article): bool
    {
        if ($user === null || !$user->isActive() || !$user->isStaff()) {
            return false;
        }

        return $this->allows($user, 'articles.edit_any') || $article->authorId === $user->id;
    }

    /** @return list<string> */
    public static function abilities(): array
    {
        return array_keys(self::ABILITIES);
    }
}
