<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\Auth;
use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Security\Csrf;
use App\Core\Security\Session;
use App\Core\View\View;
use App\Seo\PageMeta;
use App\Settings\SettingsService;
use App\Taxonomy\CategoryRepository;
use App\User\Gate;

/** Данные, доступные во всех шаблонах: сайт, пользователь, рубрики, flash-сообщения, тема, согласие на cookie. */
final class ViewComposer
{
    private bool $composed = false;

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly Auth $auth,
        private readonly Gate $gate,
        private readonly SettingsService $settings,
        private readonly CategoryRepository $categories,
        private readonly Config $config,
    ) {
    }

    public function compose(?Request $request): void
    {
        if ($this->composed) {
            return;
        }
        $this->composed = true;

        $this->view->setCsrfProvider(fn (): string => $this->csrf->token());
        $logo = $this->settings->get('site.logo');
        $user = $this->auth->user();
        [$old, $errors] = $this->session->pullInput();
        $consent = $request?->cookie('cookie_consent');
        $theme = $request?->cookie('theme');

        $this->view->share('site', [
            'name' => $this->settings->siteName(),
            'tagline' => $this->settings->get('site.tagline'),
            'logo' => $logo !== '' ? '/uploads/' . $logo : null,
            'url' => $this->config->appUrl(),
            'contact_email' => $this->settings->get('site.contact_email'),
            'metrika_id' => preg_replace('/\D+/', '', $this->settings->get('analytics.metrika_id')),
            'ga4_id' => preg_match('/^G-[A-Z0-9]{4,15}$/', $this->settings->get('analytics.ga4_id')) ? $this->settings->get('analytics.ga4_id') : '',
            'vapid_public' => $this->settings->get('push.vapid_public'),
        ]);
        $this->view->share('user', $user);
        $this->view->share('gate', $this->gate);
        $this->view->share('categories', $this->categories->all());
        $this->view->share('flash', $this->session->pullFlash());
        $this->view->share('old', $old);
        $this->view->share('errors', $errors);
        $this->view->share('nonce', (string) $request?->attribute('csp_nonce', ''));
        $this->view->share('cookieConsent', in_array($consent, ['all', 'necessary'], true) ? $consent : null);
        $this->view->share('theme', in_array($theme, ['light', 'dark'], true) ? $theme : 'auto');
        $this->view->share('currentPath', $request?->path() ?? '/');
        $this->view->share('meta', new PageMeta(
            $this->settings->siteName(),
            $this->settings->get('site.description'),
            $this->config->appUrl() . ($request?->path() ?? '/'),
        ));
    }
}
