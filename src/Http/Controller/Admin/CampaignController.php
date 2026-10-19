<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Mail\Mailer;
use App\Http\AdminController;
use App\Newsletter\Campaign;
use App\Newsletter\CampaignRepository;
use App\Newsletter\CampaignService;
use App\Notification\MailService;
use DateTimeImmutable;

/** Email-кампании: сегменты по подписке и активности, отправка через очередь. */
final class CampaignController extends AdminController
{
    public function __construct(
        private readonly CampaignRepository $campaigns,
        private readonly CampaignService $service,
        private readonly MailService $mail,
        private readonly Mailer $mailer,
        private readonly Database $db,
    ) {
    }

    public function index(): Response
    {
        return $this->admin('campaigns/index', ['title' => 'Рассылки', 'section' => 'campaigns', 'items' => $this->campaigns->all()]);
    }

    public function create(): Response
    {
        return $this->form(new Campaign(), 'Новая рассылка');
    }

    public function edit(int $id): Response
    {
        $campaign = $this->campaigns->find($id) ?? $this->notFound();

        return $this->form($campaign, $campaign->name);
    }

    public function save(Request $request, ?int $id = null): Response
    {
        $savedId = $this->service->save($id, $request->all(), $this->requireUser());
        $this->flash('success', 'Рассылка сохранена');

        return $this->redirect('/admin/campaigns/' . $savedId . '/edit');
    }

    public function schedule(Request $request, int $id): Response
    {
        $campaign = $this->campaigns->find($id) ?? $this->notFound();
        $when = $request->string('scheduled_at');
        $at = $when !== '' ? new DateTimeImmutable($when, new \DateTimeZone(date_default_timezone_get())) : null;
        $this->service->schedule($campaign, $at, $this->requireUser());

        return $this->done($request, $at ? 'Рассылка запланирована на ' . \App\Support\Format::dateTime($at) : 'Рассылка запущена', '/admin/campaigns/' . $id . '/edit');
    }

    public function cancel(Request $request, int $id): Response
    {
        $campaign = $this->campaigns->find($id) ?? $this->notFound();
        $this->service->cancel($campaign, $this->requireUser());

        return $this->done($request, 'Рассылка остановлена', '/admin/campaigns/' . $id . '/edit');
    }

    /** Тестовое письмо автору рассылки. */
    public function test(Request $request, int $id): Response
    {
        $admin = $this->requireUser();
        $campaign = $this->campaigns->find($id) ?? $this->notFound();
        $message = $this->mail->render($admin->email, '[Тест] ' . $campaign->subject, 'campaign', [
            'body' => strtr($campaign->bodyHtml, ['{{name}}' => htmlspecialchars($admin->firstName(), ENT_QUOTES, 'UTF-8')]),
            'preheader' => $campaign->preheader,
        ], $admin, true);
        try {
            $this->mailer->send($message);
            $this->flash('success', 'Тестовое письмо отправлено на ' . $admin->email);
        } catch (\Throwable $e) {
            $this->flash('error', 'Не удалось отправить: ' . $e->getMessage());
        }

        return $this->redirect('/admin/campaigns/' . $id . '/edit');
    }

    public function preview(int $id): Response
    {
        $admin = $this->requireUser();
        $campaign = $this->campaigns->find($id) ?? $this->notFound();
        $message = $this->mail->render($admin->email, $campaign->subject, 'campaign', [
            'body' => strtr($campaign->bodyHtml, ['{{name}}' => htmlspecialchars($admin->firstName(), ENT_QUOTES, 'UTF-8')]),
            'preheader' => $campaign->preheader,
        ], $admin, true);

        return Response::html($message->html)->withHeader('Content-Security-Policy', "default-src 'none'; img-src * data:; style-src 'unsafe-inline'");
    }

    private function form(Campaign $campaign, string $title): Response
    {
        $deliveries = $campaign->id > 0 ? $this->db->fetchAll(
            "SELECT d.user_id, d.status, d.error, d.sent_at, u.email FROM campaign_deliveries d JOIN users u ON u.id = d.user_id
              WHERE d.campaign_id = :c AND d.status = 'failed' ORDER BY d.sent_at DESC NULLS LAST LIMIT 30",
            ['c' => $campaign->id]
        ) : [];

        return $this->admin('campaigns/form', [
            'title' => $title,
            'section' => 'campaigns',
            'campaign' => $campaign,
            'recipients' => $this->campaigns->countRecipients($campaign->segment),
            'failures' => $deliveries,
        ]);
    }
}
