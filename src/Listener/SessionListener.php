<?php

/*
 * This file is part of the Novo SGA project.
 *
 * (c) Rogerio Lino <rogeriolino@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Listener;

use Novosga\Http\Envelope;
use Novosga\Entity\Usuario;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * SessionListener
 *
 * @author Rogerio Lino <rogeriolino@gmail.com>
 */
class SessionListener extends AppListener
{
    /**
     * @var TokenStorage
     */
    private $tokenStorage;
    
    public function __construct(TokenStorageInterface $tokenStorage)
    {
        $this->tokenStorage = $tokenStorage;
    }
    
    public function onKernelRequest(GetResponseEvent $event)
    {
        if (HttpKernelInterface::MASTER_REQUEST !== $event->getRequestType()) {
            return;
        }
        
        $token = $this->tokenStorage->getToken();
        $request = $event->getRequest();
        $session = $request->getSession();

        if (!$this->isApiRequest($request) && $token) {
            $user      = $token->getUser();
            $sessionId = $session ? $session->getId() : '-';

            if ($user instanceof Usuario && $user->getSessionId() !== $sessionId) {
                $request = $event->getRequest();
            
                if ($request->isXmlHttpRequest()) {
                    $envelope = new Envelope();
                    $envelope->setSuccess(false);
                    $envelope->setSessionStatus('inactive');
                    $envelope->setMessage(_('Sessão inválida'));

                    $response = new JsonResponse($envelope);
                } else {
                    $url = $request->getBaseUrl() . '/logout';
                    $response = new RedirectResponse($url);
                }
                $event->setResponse($response);
            }
        }
    }
}
