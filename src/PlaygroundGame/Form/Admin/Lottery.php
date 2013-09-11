<?php

namespace PlaygroundGame\Form\Admin;

use Zend\Form\Form;
use PlaygroundCore\Stdlib\Hydrator\DoctrineObject as DoctrineHydrator;
use Zend\Form\Element;
use Zend\I18n\Translator\Translator;
use Zend\ServiceManager\ServiceManager;

class Lottery extends Game
{
    public function __construct($name = null, ServiceManager $sm, Translator $translator)
    {
        $this->setServiceManager($sm);
        $entityManager = $sm->get('doctrine.entitymanager.orm_default');

        // having to fix a Doctrine-module bug :( https://github.com/doctrine/DoctrineModule/issues/180
        $hydrator = new DoctrineHydrator($entityManager, 'PlaygroundGame\Entity\Lottery');
        $hydrator->addStrategy('partner', new \PlaygroundCore\Stdlib\Hydrator\Strategy\ObjectStrategy());
        $this->setHydrator($hydrator);

        parent::__construct($name, $sm, $translator);

        $this->add(array(
            'type' => 'Zend\Form\Element\Select',
            'name' => 'drawAuto',
            'attributes' =>  array(
                'id' => 'drawAuto',
                'options' => array(
                    '0' => $translator->translate('Non', 'playgroundgame'),
                    '1' => $translator->translate('Oui', 'playgroundgame'),
                ),
            ),
            'options' => array(
                'label' => $translator->translate('Tirage au sort automatique', 'playgroundgame'),
            ),
        ));

        $this->add(array(
                'type' => 'Zend\Form\Element\DateTime',
                'name' => 'drawDate',
                'options' => array(
                    'label' => $translator->translate('Date du tirage au sort', 'playgroundgame'),
                    'format'=>'d/m/Y',
                ),
                'attributes' => array(
                    'type' => 'text',
                    'class'=> 'datepicker'
                ),
        ));

        $this->add(array(
            'name' => 'winners',
            'options' => array(
                'label' => $translator->translate('Nombre de gagnants', 'playgroundgame')
            ),
            'attributes' => array(
                'type' => 'text',
                'placeholder' => $translator->translate('Nombre de gagnants', 'playgroundgame')
            )
        ));

        $this->add(array(
            'name' => 'substitutes',
            'options' => array(
                'label' => $translator->translate('Nombre de remplaçants', 'playgroundgame')
            ),
            'attributes' => array(
                'type' => 'text',
                'placeholder' => $translator->translate('Nombre de remplaçants', 'playgroundgame')
            )
        ));

    }
}
