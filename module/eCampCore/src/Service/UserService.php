<?php

namespace eCamp\Core\Service;

use Doctrine\ORM\ORMException;
use eCamp\Core\Hydrator\UserHydrator;
use eCamp\Core\Entity\User;
use eCamp\Core\Repository\UserRepository;
use eCamp\Lib\Acl\NoAccessException;
use eCamp\Lib\Service\BaseService;
use Hybridauth\User\Profile;
use ZF\ApiProblem\ApiProblem;

/**
 * Class UserService
 */
class UserService extends BaseService
{
    public function __construct(UserHydrator $userHydrator) {
        parent::__construct($userHydrator, User::class);
    }

    /**
     * @param mixed $data
     * @return User|mixed|ApiProblem
     * @throws NoAccessException
     * @throws ORMException
     * @throws \Exception
     */
    public function create($data) {
        /** @var Profile $profile */
        $profile = $data;

        if ($profile instanceof Profile) {
            $data = (object)[
                'username' => $profile->displayName,
                'mailAddress' => $profile->email,
                'state' => User::STATE_REGISTERED,
            ];
        }

        $state = isset($data->state) ? $data->state : User::STATE_NONREGISTERED;
        if (!in_array($state, [User::STATE_NONREGISTERED, User::STATE_REGISTERED])) {
            return new ApiProblem(400, 'Invalid state: ' . $state);
        }

        /** @var User $user */
        $user = parent::create($data);
        $user->setState($state);
        $user->setRole(User::ROLE_USER);

        $key = $user->setMailAddress($data->mailAddress);

        if ($profile instanceof Profile) {
            $user->verifyMailAddress($key);
        }

        return $user;
    }

    /**
     * @param mixed $id
     * @param mixed $data
     * @return User|ApiProblem
     * @throws NoAccessException
     */
    public function update($id, $data) {
        if ($data instanceof Profile) {
            /** @var Profile $profile */
            $profile = $data;
            $data = (object)['username' => $profile->displayName];
        }
        return parent::update($id, $data);
    }

}
