<?php

declare(strict_types=1);

namespace App\Seed;

use App\Article\ArticleRepository;
use App\Article\ArticleService;
use App\Article\ArticleStatus;
use App\Article\GalleryRepository;
use App\Article\LiveEntryRepository;
use App\Comment\CommentRepository;
use App\Core\Database\Database;
use App\Core\Security\PasswordHasher;
use App\Media\MediaService;
use App\Taxonomy\CategoryRepository;
use App\User\Role;
use App\User\User;
use App\User\UserRepository;
use DateTimeImmutable;

/** Демо-наполнение: авторы, статьи всех типов с обложками, комментарии. Только для разработки/показа. */
final class DemoContentSeeder
{
    private const TITLES = [
        'Как нейросети меняют работу редакций: первые итоги года',
        'Российский рынок облачных сервисов вырос на треть',
        'Почему стартапы всё чаще отказываются от венчурных денег',
        'Города будущего: что ждёт общественный транспорт',
        'Квантовые компьютеры: между обещаниями и реальностью',
        'Удалённая работа пять лет спустя: кто вернулся в офис',
        'Новый взгляд на старую физику: итоги эксперимента на коллайдере',
        'Книжный рынок после бума аудиокниг',
        'Как устроена кибербезопасность небольшого банка',
        'Энергетика: солнечные панели дешевеют быстрее прогнозов',
        'Образование без оценок: опыт школ, которые попробовали',
        'Кино и стриминг: почему сериалы стали короче',
        'Что такое программная инженерия и почему её недооценивают',
        'Малый бизнес и маркетплейсы: цена зависимости',
        'Мозг и сон: что показали новые исследования',
        'Фестивали лета: репортаж с главных площадок',
        'Электромобили зимой: честный тест на 3000 километров',
        'Платёжные системы: как работает безналичный мир',
        'Открытый код как бизнес-модель',
        'Поколение Z на рынке труда: ожидания и реальность',
        'Космос ближе: частные запуски и новые правила',
        'Данные и приватность: что о нас знают приложения',
        'Урбанистика: двор без машин — утопия или норма',
        'Искусственный интеллект в медицине: диагностика на подходе',
    ];

    private const SENTENCES = [
        'Эксперты отмечают, что динамика последних месяцев превзошла самые оптимистичные ожидания рынка.',
        'По данным аналитиков, около сорока процентов компаний уже протестировали новые инструменты на практике.',
        'Главный вопрос теперь не в том, нужна ли технология, а в том, как встроить её в привычные процессы.',
        'Участники исследования подчёркивают: решающую роль играют не алгоритмы, а качество исходных данных.',
        'Разработчики признают, что первые версии продукта были далеки от идеала, но обратная связь помогла быстро исправить недостатки.',
        'Скептики напоминают, что подобные циклы ажиотажа уже случались, и каждый раз оставляли после себя лишь часть обещанного.',
        'Тем не менее инвесторы продолжают вкладываться в проекты, которые показывают устойчивый рост выручки.',
        'На практике это означает пересмотр бюджетов, перестройку команд и, нередко, отказ от привычных подходов.',
        'Показательно, что самые заметные результаты демонстрируют небольшие команды с ясной целью.',
        'Регуляторы тем временем готовят новые правила, которые могут заметно изменить условия игры уже в следующем году.',
        'Пользователи, в свою очередь, всё внимательнее относятся к вопросам приватности и прозрачности сервисов.',
        'Как рассказала одна из участниц рынка, главный урок последних лет — не торопиться с масштабированием.',
        'Цифры говорят сами за себя: за год показатель удвоился, а затраты на привлечение клиента, напротив, снизились.',
        'Впрочем, не всё так однозначно: региональные различия остаются значительными, и в некоторых городах эффект почти незаметен.',
        'Этот опыт стоит учитывать всем, кто планирует подобные проекты в ближайшее время.',
    ];

    private const TAGS = ['ИИ', 'стартапы', 'облака', 'кибербезопасность', 'урбанистика', 'наука', 'финтех', 'образование', 'кино', 'энергетика', 'работа', 'здоровье'];

    public function __construct(
        private readonly Database $db,
        private readonly UserRepository $users,
        private readonly CategoryRepository $categories,
        private readonly ArticleService $articles,
        private readonly ArticleRepository $articleRepo,
        private readonly GalleryRepository $gallery,
        private readonly LiveEntryRepository $live,
        private readonly CommentRepository $comments,
        private readonly MediaService $media,
        private readonly PasswordHasher $hasher,
    ) {
    }

    public function run(int $count = 24): int
    {
        mt_srand(42);
        $authors = $this->authors();
        $categories = $this->categories->all();
        if ($categories === []) {
            throw new \RuntimeException('Сначала выполните: php bin/console seed');
        }
        $editor = $authors[0];
        $created = 0;
        $types = ['news', 'news', 'news', 'analytics', 'longread', 'opinion', 'news', 'analytics'];

        foreach (array_slice(self::TITLES, 0, $count) as $i => $title) {
            if ($this->db->fetchValue('SELECT 1 FROM articles WHERE title = :t', ['t' => $title])) {
                continue;
            }
            $category = $categories[$i % count($categories)];
            $type = $i === 3 ? 'photo' : ($i === 5 ? 'liveblog' : $types[$i % count($types)]);
            $access = match (true) {
                $type === 'longread' => 'premium',
                $i % 4 === 1 || $type === 'analytics' => 'digital',
                default => 'free',
            };
            $cover = $this->makeCover($i, $category->color, $title);
            $article = $this->articles->create($authors[$i % count($authors)], [
                'title' => $title,
                'subtitle' => $this->sentence(),
                'lead' => $this->sentence() . ' ' . $this->sentence(),
                'body' => $this->body($type === 'longread' ? 14 : 7),
                'type' => $type,
                'access' => $access,
                'category_id' => $category->id,
                'cover_media_id' => $cover,
                'cover_caption' => 'Иллюстрация: демо-данные',
                'tags' => implode(', ', $this->pick(self::TAGS, 3)),
                'is_featured' => $i < 2,
                'allow_comments' => true,
                'is_live' => $type === 'liveblog',
            ]);
            $article = $this->articles->transition($article, $editor, ArticleStatus::Published);
            $publishedAt = new DateTimeImmutable('-' . ($i * 19 + mt_rand(1, 12)) . ' hours');
            $this->articleRepo->update($article->id, ['published_at' => $publishedAt, 'views_count' => mt_rand(40, 4000)]);
            $created++;

            if ($type === 'photo') {
                $items = [];
                foreach (range(1, 6) as $n) {
                    $items[] = ['media_id' => $this->makeCover($i * 10 + $n, $category->color, 'Фото ' . $n), 'caption' => 'Кадр ' . $n . ': ' . $this->sentence()];
                }
                $this->gallery->replace($article->id, $items);
            }
            if ($type === 'liveblog') {
                foreach ([['10:05', 'Начало трансляции'], ['10:40', 'Первые данные'], ['11:15', 'Комментарии экспертов']] as [$time, $head]) {
                    $this->live->add($article->id, $editor->id, $time . ' — ' . $head, '<p>' . $this->sentence() . ' ' . $this->sentence() . '</p>');
                }
            }
        }
        $this->comments($authors);

        return $created;
    }

    /** @return list<User> */
    private function authors(): array
    {
        $result = [];
        foreach ([
            ['editor@demo.test', 'Мария Соколова', Role::Editor],
            ['author1@demo.test', 'Алексей Громов', Role::Author],
            ['author2@demo.test', 'Ирина Левина', Role::Author],
        ] as [$email, $name, $role]) {
            $user = $this->users->findByEmail($email) ?? $this->users->createUser([
                'email' => $email, 'name' => $name, 'role' => $role->value, 'email_verified' => true,
                'password_hash' => $this->hasher->hash('Demo12345!'), 'digest_frequency' => 'none',
            ]);
            $this->users->update($user->id, ['bio' => 'Пишет о технологиях, бизнесе и обществе. Демо-автор.']);
            $this->users->ensureSlug($user);
            $result[] = $this->users->find($user->id) ?? $user;
        }

        return $result;
    }

    private function makeCover(int $seed, string $hex, string $label): int
    {
        $w = 1600;
        $h = 900;
        $image = imagecreatetruecolor($w, $h);
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x') ?: [37, 99, 235];
        $shift = ($seed * 37) % 120;
        for ($y = 0; $y < $h; ++$y) {
            $t = $y / $h;
            $color = imagecolorallocate(
                $image,
                (int) max(0, min(255, $r * (1 - $t) + (40 + $shift) * $t)),
                (int) max(0, min(255, $g * (1 - $t) + (30 + $shift / 2) * $t)),
                (int) max(0, min(255, $b * (1 - $t) + (90 + $shift) * $t)),
            );
            imageline($image, 0, $y, $w, $y, (int) $color);
        }
        imagealphablending($image, true);
        for ($n = 0; $n < 6; ++$n) {
            $c = imagecolorallocatealpha($image, 255, 255, 255, 112 + ($n * 2));
            imagefilledellipse($image, mt_rand(0, $w), mt_rand(0, $h), mt_rand(200, 700), mt_rand(200, 700), (int) $c);
        }
        $file = tempnam(sys_get_temp_dir(), 'cover') . '.png';
        imagepng($image, $file);
        $media = $this->media->importFile($file, 'demo-cover-' . $seed . '.png', null, $label);
        @unlink($file);

        return $media->id;
    }

    private function body(int $paragraphs): string
    {
        $html = '';
        for ($i = 0; $i < $paragraphs; ++$i) {
            if ($i > 0 && $i % 4 === 0) {
                $html .= '<h2>' . rtrim($this->sentence(), '.') . '</h2>';
            }
            if ($i === 3) {
                $html .= '<blockquote><p>' . $this->sentence() . '</p></blockquote>';
            }
            $html .= '<p>' . implode(' ', $this->pick(self::SENTENCES, mt_rand(3, 5))) . '</p>';
        }

        return $html;
    }

    private function sentence(): string
    {
        return self::SENTENCES[mt_rand(0, count(self::SENTENCES) - 1)];
    }

    /** @param list<string> $pool @return list<string> */
    private function pick(array $pool, int $n): array
    {
        shuffle($pool);

        return array_slice($pool, 0, $n);
    }

    /** @param list<User> $authors */
    private function comments(array $authors): void
    {
        $articleId = $this->db->fetchValue("SELECT id FROM articles WHERE status = 'published' ORDER BY published_at DESC LIMIT 1");
        if ($articleId === null || $this->db->fetchValue('SELECT 1 FROM comments WHERE article_id = :a', ['a' => $articleId])) {
            return;
        }
        $root = $this->comments->create(['article_id' => (int) $articleId, 'user_id' => $authors[1]->id, 'body' => 'Интересный материал, спасибо! Особенно понравилась часть про данные.', 'status' => 'approved']);
        $this->comments->create(['article_id' => (int) $articleId, 'user_id' => $authors[2]->id, 'parent_id' => $root, 'body' => 'Согласна, хотелось бы увидеть продолжение.', 'status' => 'approved']);
        $this->db->execute('UPDATE articles SET comments_count = 2 WHERE id = :a', ['a' => $articleId]);
    }
}
