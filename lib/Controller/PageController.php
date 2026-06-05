<?php

namespace OCA\X2Mail\Controller;

use OCA\X2Mail\Service\DomainConfigService;
use OCA\X2Mail\Util\EngineHelper;
use OCA\X2Mail\ContentSecurityPolicy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\IAppConfig;
use OCP\INavigationManager;
use OCP\IRequest;
use OCP\IURLGenerator;

class PageController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private INavigationManager $navigationManager,
        private IAppConfig $appConfig,
        private DomainConfigService $domainService,
        private IGroupManager $groupManager,
        private EngineHelper $engineHelper,
        private IURLGenerator $urlGenerator,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    /** @return TemplateResponse|void */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(string $target = '')
    {
        // No domain configured → show setup hint instead of useless login form
        if (empty($this->domainService->listDomains())) {
            $isAdmin = $this->userId && $this->groupManager->isAdmin($this->userId);
            return new TemplateResponse('x2mail', 'not_configured', [
                'isAdmin' => $isAdmin,
            ]);
        }

        $queryString = $this->request->server['QUERY_STRING'] ?? '';
        if ($queryString !== '') {
            $this->engineHelper->loadApp();
            $this->engineHelper->startApp(true);

            return;
        }

        $this->navigationManager->setActiveEntry('x2mail');

        \OCP\Util::addStyle('x2mail', 'embed');

        $this->engineHelper->startApp();

        // In SSO mode, show a clear token error instead of the engine login form.
        // In plain mode, the engine login form is the intended authentication path.
        $oidcAutoLogin = $this->appConfig->getValueString('x2mail', 'autologin-oidc', '0') !== '0';
        if ($oidcAutoLogin && !$this->engineHelper->hasAuthenticatedAccount()) {
            return new TemplateResponse('x2mail', 'auth_error', [
                'isOidcLogin' => $this->engineHelper->isOIDCLogin(),
                'reloadUrl' => $this->urlGenerator->linkToRoute('x2mail.page.index'),
            ]);
        }

        $oConfig = \X2Mail\Engine\Api::Config();
        $oActions = \X2Mail\Engine\Api::Actions();
        $oHttp = \X2Mail\Mail\Base\Http::SingletonInstance();
        $oServiceActions = new \X2Mail\Engine\ServiceActions($oHttp, $oActions);
        $sLanguage = $oActions->GetLanguage(false);

        $csp = new ContentSecurityPolicy();
        $sNonce = $csp->getEngineNonce();

        $params = [
            'Admin' => 0,
            'LoadingDescriptionEsc' => \htmlspecialchars(
                $oConfig->Get('webmail', 'loading_description', 'OpacIT Mail'),
                ENT_QUOTES | ENT_IGNORE,
                'UTF-8'
            ),
            'BaseTemplates' => \X2Mail\Engine\Utils::ClearHtmlOutput(
                $oServiceActions->compileTemplates()
            ),
            'BaseAppBootScript' => \file_get_contents(
                APP_VERSION_ROOT_PATH . 'static/js/boot.js'
            ),
            'BaseAppBootScriptNonce' => $sNonce,
            'BaseLanguage' => $oActions->compileLanguage($sLanguage, false),
            'BaseAppBootCss' => \file_get_contents(APP_VERSION_ROOT_PATH . 'static/css/boot.css'),
            'BaseAppThemeCss' => \preg_replace(
                '/\\s*([:;{},]+)\\s*/s',
                '$1',
                $oActions->compileCss($oActions->GetTheme(false), false)
            ),
        ];

        \OCP\Util::addHeader('link', [
            'type' => 'text/css',
            'rel' => 'stylesheet',
            'href' => \X2Mail\Engine\Utils::WebStaticPath('css/app.css'),
        ], '');

        $response = new TemplateResponse('x2mail', 'index_embed', $params);

        $response->setContentSecurityPolicy($csp);

        return $response;
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function appGet(): void
    {
        $this->engineHelper->startApp(true);
    }

    // NoCSRFRequired: the engine's internal AJAX does not carry Nextcloud CSRF
    // tokens; it uses its own CSRF protection within the engine session.
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function appPost(): void
    {
        $this->engineHelper->startApp(true);
    }

    // NoCSRFRequired: the engine's internal AJAX does not carry Nextcloud CSRF
    // tokens; it uses its own CSRF protection within the engine session.
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function indexPost(): void
    {
        $this->engineHelper->startApp(true);
    }
}
