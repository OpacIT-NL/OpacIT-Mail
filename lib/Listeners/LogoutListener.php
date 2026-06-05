<?php

declare(strict_types=1);

namespace OCA\opacit_mail\Listeners;

use OCA\opacit_mail\Service\LogService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\ISession;
use OCP\User\Events\BeforeUserLoggedOutEvent;

/** @implements IEventListener<Event> */
class LogoutListener implements IEventListener
{
    public function __construct(
        private ISession $session,
        private LogService $logService,
    ) {
    }

    public function handle(Event $event): void
    {
        if (!($event instanceof BeforeUserLoggedOutEvent)) {
            return;
        }

        $this->session->remove('opacit_mail-uid');
        $this->logService->debug('Session cleared on logout');
    }
}
