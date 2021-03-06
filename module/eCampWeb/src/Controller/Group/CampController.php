<?php

namespace eCamp\Web\Controller\Group;

use eCamp\Core\Service\CampService;
use eCamp\Web\Controller\AbstractBaseController;

class CampController extends AbstractBaseController
{
    /** @var CampService */
    private $campService;

    public function __construct(CampService $campService) {
        $this->campService = $campService;
    }

    /**
     * @return array|\Zend\View\Model\ViewModel
     * @throws \eCamp\Lib\Acl\NoAccessException
     */
    public function indexAction() {
        $group = $this->params()->fromRoute('group');
        $camps = $this->campService->fetchAll(['group' => $group]);

        return [
            'group' => $group,
            'camps' => $camps
        ];
    }

}