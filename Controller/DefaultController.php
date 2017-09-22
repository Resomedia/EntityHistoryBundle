<?php

namespace Resomedia\EntityHistoryBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class DefaultController extends Controller
{
    public function indexAction()
    {
        return $this->render('ResomediaEntityHistoryBundle:Default:index.html.twig');
    }
}
