<?php

declare(strict_types=1);

namespace OCA\opacit_mail\Listeners;

use OCA\opacit_mail\Service\LogService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\ISession;

/** @implements IEventListener<Event> */
class ImpersonateListener implements IEventListener
{
    public function __construct(
        private ISession $session,
        private LogService $logService,
    ) {
    }

    public function handle(Event $event): void
    {
        $class = get_class($event);
        if (
            $class !== 'OCA\\Impersonate\\Events\\BeginImpersonateEvent'
            && $class !== 'OCA\\Impersonate\\Events\\EndImpersonateEvent'
        ) {
            return;
        }

        $this->session->remove('opacit_mail-uid');
        $this->logService->debug("Session cleared on impersonate: {$class}");
    }
}
