<?php

namespace eCamp\Core\Controller\Auth;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use eCamp\Core\Auth\AuthService;
use eCamp\Core\Entity\User;
use eCamp\Core\Entity\UserIdentity;
use eCamp\Core\Repository\UserRepository;
use eCamp\Core\Service\UserIdentityService;
use eCamp\Core\Service\UserService;
use eCamp\Lib\Acl\NoAccessException;
use eCamp\Lib\Auth\OAuthAdapter;
use Hybridauth\Adapter\AdapterInterface;
use Hybridauth\Exception\InvalidArgumentException;
use Hybridauth\Exception\UnexpectedValueException;
use Zend\Http\Request;
use Zend\Http\Response;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\Session\Container;
use Zend\View\Model\ViewModel;

abstract class BaseController extends AbstractActionController
{
    public const SESSION_NAMESPACE = self::class;


    /** @var EntityManager */
    protected $entityManager;

    /** @var UserIdentityService */
    protected $userIdentityService;

    /** @var UserService */
    protected $userService;

    /** @var AuthService */
    protected $authService;

    /** @var string */
    protected $providerName;


    /** @var Container */
    private $sessionContainer;

    /** @var AdapterInterface */
    private $authAdapter;



    public function __construct
    ( EntityManager $entityManager
    , UserIdentityService $userIdentityService
    , UserService $userService
    , AuthService $authService
    , string $providerName
    ) {
        $this->entityManager = $entityManager;
        $this->userIdentityService = $userIdentityService;
        $this->userService = $userService;
        $this->authService = $authService;
        $this->providerName = $providerName;
    }


    /**
     * @return Response|ViewModel
     * @throws NoAccessException
     * @throws NonUniqueResultException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws \Exception
     */
    public function indexAction() {
        /** @var Request $request */
        $request = $this->getRequest();
        $this->setRedirect($request->getQuery('redirect'));

        $this->getAuthAdapter()->disconnect();
        $this->getAuthAdapter()->authenticate();

        return $this->callbackAction();
    }

    /**
     * @return Response
     * @throws NonUniqueResultException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws \Exception
     * @throws NoAccessException
     */
    public function callbackAction() {
        $this->getAuthAdapter()->authenticate();

        $profile = $this->getAuthAdapter()->getUserProfile();
        $identity = $this->userIdentityService->find($this->providerName, $profile->identifier);

        $user = null;
        if ($identity) {
            $user = $identity->getUser();
        } else {
            /** @var UserRepository $userRepository */
            $userRepository = $this->entityManager->getRepository(User::class);
            $user = $userRepository->findByMail($profile->email);
        }

        if ($user == null) {
            $user = $this->userService->create($profile);
        } else {
            $user = $this->userService->update($user, $profile);
        }

        if ($identity == null) {
            /** @var UserIdentity $identity */
            $identity = $this->userIdentityService->create((object)[
                'provider' => $this->providerName,
                'providerId' => $profile->identifier
            ]);
            $identity->setUser($user);
        }
        $this->entityManager->flush();

        $result = $this->authService->authenticate(
            new OAuthAdapter($user->getId())
        );

        if ($result->isValid()) {
            $redirect = $this->getRedirect();
            if (isset($redirect)) {
                return $this->redirect()->toUrl($redirect);
            }
        }

        die('login ok');
    }

    /**
     * @throws InvalidArgumentException
     * @throws UnexpectedValueException
     */
    public function logoutAction() {
        $this->authService->clearIdentity();
        $this->getAuthAdapter()->disconnect();

        // TODO: Redirect

        die('logout');
    }


    protected function getRedirect() {
        if ($this->sessionContainer == null) {
            $this->sessionContainer = new Container(self::SESSION_NAMESPACE);
        }
        return $this->sessionContainer->redirect;
    }

    protected function setRedirect($redirect) {
        if ($this->sessionContainer == null) {
            $this->sessionContainer = new Container(self::SESSION_NAMESPACE);
        }
        $this->sessionContainer->redirect = $redirect;
    }


    protected function getCallbackUri($route = null, $params = [], $options = []) {
        /** @var Request $request */
        $request = $this->getRequest();

        $uri = $request->getUri();
        $port = $uri->getPort();
        $callbackUri = sprintf('%s://%s', $uri->getScheme(), $uri->getHost());
        if ($port !== null) { $callbackUri .= (':' . $port); }
        $callbackUri .= $this->url()->fromRoute($route, $params, $options);

        return $callbackUri;
    }

    /**
     * @return AdapterInterface
     * @throws InvalidArgumentException
     * @throws UnexpectedValueException
     */
    protected function getAuthAdapter() {
        if ($this->authAdapter == null) {
            $this->authAdapter = $this->createAuthAdapter();
        }
        return $this->authAdapter;
    }

    /**
     * @return AdapterInterface
     * @throws InvalidArgumentException
     * @throws UnexpectedValueException
     */
    protected function createAuthAdapter() {
        $route = $this->getCallbackRoute();
        $callback = $this->getCallbackUri($route, [ 'action' => 'callback' ]);
        $config = ['provider' => $this->providerName, 'callback' => $callback];

        return $this->authService->createOAuthAdapter($this->providerName, $config);
    }

    /** @return string */
    abstract protected function getCallbackRoute();
}
