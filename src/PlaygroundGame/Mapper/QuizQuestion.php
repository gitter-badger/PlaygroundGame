<?php

namespace PlaygroundGame\Mapper;

use Doctrine\ORM\EntityManager;
use PlaygroundGame\Options\ModuleOptions;
use PlaygroundGame\Mapper\AbstractMapper;

class QuizQuestion extends AbstractMapper
{

    public function findByGameId($quiz)
    {
        return $this->getEntityRepository()->findBy(array('quiz' => $quiz));
    }

    public function getEntityRepository()
    {
        if (null === $this->er) {
            $this->er = $this->em->getRepository('PlaygroundGame\Entity\QuizQuestion');
        }

        return $this->er;
    }
}
