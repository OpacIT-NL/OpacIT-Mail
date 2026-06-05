<?php

declare(strict_types=1);

namespace OCA\opacit_mail\Listeners;

use OCA\opacit_mail\Service\LogService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\ISession;
use OCP\User\Events\UserLoggedInEvent;

/**
 * Set opacit_mail-uid on UserLoggedInEvent.
 */
/** @implements IEventListener<Event> */
class LoginBridgeListener implements IEventListener
{
    public function __construct(
        private ISession $session,
        private LogService $logService,
    ) {
    }

    public function handle(Event $event): void
    {
        if (!($event instanceof UserLoggedInEvent)) {
            return;
        }

        $uid = $event->getUser()->getUID();
        $this->session->set('opacit_mail-uid', $uid);

        if ($this->session->get('is_oidc')) {
            $this->logService->info("Login bridge: uid={$uid}, is_oidc=true");
        } else {
            $this->logService->debug("Login bridge: uid={$uid}, is_oidc=false (no SSO session)");
        }
    }
}
