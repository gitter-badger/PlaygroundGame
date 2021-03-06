<?php
/**
 * dependency Core
 * @author gbesson
 *
 */
namespace PlaygroundGame;

use Zend\ModuleManager\ModuleManager;
use Zend\Mvc\ModuleRouteListener;
use Zend\Mvc\MvcEvent;
use Zend\Validator\AbstractValidator;

class Module
{
    public function init(ModuleManager $manager)
    {
        $eventManager = $manager->getEventManager();
    
        /*
         * This event change the config before it's cached
        * The change will apply to 'template_path_stack' and 'assetic_configuration'
        * These 2 config take part in the Playground Theme Management
        */
        $eventManager->attach(\Zend\ModuleManager\ModuleEvent::EVENT_MERGE_CONFIG, array($this, 'onMergeConfig'), 50);
    }
    
    /**
     * This method is called only when the config is not cached.
     * @param \Zend\ModuleManager\ModuleEvent $e
     */
    public function onMergeConfig(\Zend\ModuleManager\ModuleEvent $e)
    {
        $config = $e->getConfigListener()->getMergedConfig(false);
        
        if (isset($config['design']) && isset($config['design']['frontend'])) {
            $parentTheme = array($config['design']['frontend']['package'], $config['design']['frontend']['theme']);
        } else {
            $parentTheme = array('playground','base');
        }
    
        // If custom games need a specific route. I create these routes
        if (PHP_SAPI !== 'cli') {
            if (isset($config['custom_games'])) {
                foreach ($config['custom_games'] as $k => $v) {
                    // add custom language directory
                    $config['translator']['translation_file_patterns'][] = array(
                        'type'         => 'phpArray',
                        'base_dir'     => __DIR__ . '/../../../../../design/frontend/'. $parentTheme[0] .'/'. $parentTheme[1] . '/custom/' . $k . '/language',
                        'pattern'      => '%s.php',
                        'text_domain'  => $k
                    );

                    // create routes
                    if (isset($v['url'])) {
                        if (!is_array($v['url'])) {
                            $v['url'] = array($v['url']);
                        }
                        foreach ($v['url'] as $url) {
                            // I take the url model of the game type
                            if (isset($config['router']['routes']['frontend']['child_routes'][$v['classType']])) {
                                $routeModel = $config['router']['routes']['frontend']['child_routes'][$v['classType']];
                    
                                // Changing the root of the route
                                $routeModel['options']['route'] = '/';
                    
                                // and removing the trailing slash for each subsequent route
                                foreach ($routeModel['child_routes'] as $id => $ar) {
                                    $routeModel['child_routes'][$id]['options']['route'] = ltrim($ar['options']['route'], '/');
                                }
                    
                                // then create the hostname route + appending the model updated
                                $config['router']['routes']['frontend.'.$url] = array(
                                    'type' => 'Zend\Mvc\Router\Http\Hostname',
                                    'options' => array(
                                        'route' => $url,
                                        'defaults' => array(
                                            'id' => $k
                                        )
                                    ),
                                    'may_terminate' => true
                                );
                                $config['router']['routes']['frontend.'.$url]['child_routes'][$v['classType']] = $routeModel;
                                
                                // adding not-found catchall : Any non existent URL will be catched by this controller
                                $config['router']['routes']['frontend.'.$url]['child_routes'][$v['classType']]['child_routes']['catchall'] = array(
                                    'type' => '\Zend\Mvc\Router\Http\Regex',
                                    'priority' => -1000,
                                    'options' => array(
                                        'regex' => '.*',
                                        'spec' => '%url%',
                                        'defaults' => array(
                                            'controller' => 'playgroundgame_'.$v['classType'],
                                            'action' => 'not-found'
                                        ),
                                    ),
                                );
                    
                                $coreLayoutModel = $config['core_layout']['frontend'];
                                $config['core_layout']['frontend.'.$url] = $coreLayoutModel;
                            }
                        }
                    }
                    if (isset($v['assetic_configuration'])) {
                        foreach ($v['assetic_configuration']['modules'] as $m => $d) {
                            $v['assetic_configuration']['modules'][$m]['root_path'][] = __DIR__ . '/../../../../../design/frontend/'. $parentTheme[0] .'/'. $parentTheme[1] . '/custom/' . $k . '/assets';
                        }
                        
                        // I specialize the route config to the game !
                        if (isset($v['assetic_configuration']['routes'])) {
                            $customRoutes = array();
                            if (isset($v['assetic_configuration']['routes']['params'])) {
                                $customRoutes['custom'][$k]['params'] = $v['assetic_configuration']['routes']['params'];
                                unset($v['assetic_configuration']['routes']['params']);
                            }
                            $customRoutes['custom'][$k]['params']['id'] = $k;
                            $customRoutes['custom'][$k]['routes'] = $v['assetic_configuration']['routes'];
                            $v['assetic_configuration']['routes'] = $customRoutes;
                        }
                        
                        $config['assetic_configuration'] = array_replace_recursive($config['assetic_configuration'], $v['assetic_configuration']);
                    }
                }
            }
        }

        $e->getConfigListener()->setMergedConfig($config);
    }
            
    public function onBootstrap(MvcEvent $e)
    {
        $serviceManager = $e->getApplication()->getServiceManager();

        $options = $serviceManager->get('playgroundcore_module_options');
        $locale = $options->getLocale();
        $translator = $serviceManager->get('translator');
        if (!empty($locale)) {
            //translator
            $translator->setLocale($locale);

            // plugins
            $translate = $serviceManager->get('viewhelpermanager')->get('translate');
            $translate->getTranslator()->setLocale($locale);
        }

        AbstractValidator::setDefaultTranslator($translator, 'playgroundcore');

        $eventManager        = $e->getApplication()->getEventManager();
        $moduleRouteListener = new ModuleRouteListener();
        $moduleRouteListener->attach($eventManager);

        // If PlaygroundCms is installed, I can add my own dynareas to benefit from this feature
        $e->getApplication()->getEventManager()->getSharedManager()->attach('Zend\Mvc\Application', 'getDynareas', array($this, 'updateDynareas'));

        // I can post cron tasks to be scheduled by the core cron service
        $e->getApplication()->getEventManager()->getSharedManager()->attach('Zend\Mvc\Application', 'getCronjobs', array($this, 'addCronjob'));

        // If cron is called, the $e->getRequest()->getPost() produces an error so I protect it with
        // this test
        if ((get_class($e->getRequest()) == 'Zend\Console\Request')) {
            return;
        }
            
        /**
         * This listener gives the possibility to select the layout on module / controller / action level
         * This is triggered after the PlaygroundDesign one so that it's the last triggered for games.
         */
        $e->getApplication()->getEventManager()->getSharedManager()->attach('Zend\Mvc\Controller\AbstractActionController', 'dispatch', function (MvcEvent $e) {
            $config     = $e->getApplication()->getServiceManager()->get('config');
            if (isset($config['core_layout'])) {
                $controller      = $e->getTarget();
                $controllerClass = get_class($controller);
                $moduleName      = strtolower(substr($controllerClass, 0, strpos($controllerClass, '\\')));
                $match           = $e->getRouteMatch();
                $routeName       = $match->getMatchedRouteName();
                $areaName        = (strpos($routeName, '/'))?substr($routeName, 0, strpos($routeName, '/')):$routeName;
                $areaName        = (strpos($areaName, '.'))?substr($areaName, 0, strpos($areaName, '.')):$areaName;
                $areaName        = ($areaName == 'frontend' || $areaName == 'admin')? $areaName : 'frontend';
                $controllerName  = $match->getParam('controller', 'not-found');
                $actionName      = $match->getParam('action', 'not-found');
                $channel         = $match->getParam('channel', 'not-found');
                $slug            = $match->getParam('id', '');
                $viewModel       = $e->getViewModel();
                 
                //echo ' slug:' . $slug . " route:" . $routeName . ' area:' . $areaName . ' module:' . $moduleName . ' ctrl:' . $controllerName . ' action:' . $actionName . "\n";

                /**
                 * Assign the correct layout
                */
                if (!empty($slug)) {
                    if (isset($config['custom_games'][$slug]['core_layout'][$areaName]['modules'][$moduleName]['controllers'][$controllerName]['actions'][$actionName]['channel'][$channel]['layout'])) {
                        $controller->layout($config['custom_games'][$slug]['core_layout'][$areaName]['modules'][$moduleName]['controllers'][$controllerName]['actions'][$actionName]['channel'][$channel]['layout']);
                    } elseif (isset($config['custom_games'][$slug]['core_layout'][$areaName]['modules'][$moduleName]['controllers'][$controllerName]['actions'][$actionName]['layout'])) {
                        $controller->layout($config['custom_games'][$slug]['core_layout'][$areaName]['modules'][$moduleName]['controllers'][$controllerName]['actions'][$actionName]['layout']);
                    } elseif (isset($config['custom_games'][$slug]['core_layout'][$areaName]['modules'][$moduleName]['controllers'][$controllerName]['channel'][$channel]['layout'])) {
                        $controller->layout($config['custom_games'][$slug]['core_layout'][$areaName]['modules'][$moduleName]['controllers'][$controllerName]['channel'][$channel]['layout']);
                    } elseif (isset($config['custom_games'][$slug]['core_layout'][$areaName]['modules'][$moduleName]['controllers'][$controllerName]['layout'])) {
                        $controller->layout($config['custom_games'][$slug]['core_layout'][$areaName]['modules'][$moduleName]['controllers'][$controllerName]['layout']);
                    } elseif (isset($config['custom_games'][$slug]['core_layout'][$areaName]['modules'][$moduleName]['channel'][$channel]['layout'])) {
                        $controller->layout($config['custom_games'][$slug]['core_layout'][$areaName]['modules'][$moduleName]['channel'][$channel]['layout']);
                    } elseif (isset($config['custom_games'][$slug]['core_layout'][$areaName]['modules'][$moduleName]['layout'])) {
                        $controller->layout($config['custom_games'][$slug]['core_layout'][$areaName]['modules'][$moduleName]['layout']);
                    } elseif (isset($config['custom_games'][$slug]['core_layout'][$areaName]['channel'][$channel]['layout'])) {
                        $controller->layout($config['custom_games'][$slug]['core_layout'][$areaName]['channel'][$channel]['layout']);
                    } elseif (isset($config['custom_games'][$slug]['core_layout'][$areaName]['layout'])) {
                        $controller->layout($config['custom_games'][$slug]['core_layout'][$areaName]['layout']);
                    }
            

                    // the area needs to be updated if I'm in a custom game for frontendUrl to work
                    if (isset($config['custom_games'][$slug])) {
                        $url = (is_array($config['custom_games'][$slug]['url']))? $config['custom_games'][$slug]['url']:array($config['custom_games'][$slug]['url']);
                        $from = strtolower($e->getRequest()->getUri()->getHost());
                        $areaName = ($areaName === 'frontend' && in_array($from, $url))? $areaName.'.'.$from:$areaName;
                    }
                    // I add this area param so that it can be used by Controller plugin frontendUrl
                    // and View helper frontendUrl
                    $match->setParam('area', $areaName);
                    /**
                     * Create variables attached to layout containing path views
                     * cascading assignment is managed
                     */
                    if (isset($config['custom_games'][$slug]['core_layout'][$areaName]['modules'][$moduleName]['children_views'])) {
                        foreach ($config['custom_games'][$slug]['core_layout'][$areaName]['modules'][$moduleName]['children_views'] as $k => $v) {
                            $viewModel->$k  = $v;
                        }
                    }
                    if (isset($config['custom_games'][$slug]['core_layout'][$areaName]['modules'][$moduleName]['controllers'][$controllerName]['children_views'])) {
                        foreach ($config['custom_games'][$slug]['core_layout'][$areaName]['modules'][$moduleName]['controllers'][$controllerName]['children_views'] as $k => $v) {
                            $viewModel->$k  = $v;
                        }
                    }
                    if (isset($config['custom_games'][$slug]['core_layout'][$areaName]['modules'][$moduleName]['controllers'][$controllerName]['actions'][$actionName]['children_views'])) {
                        foreach ($config['custom_games'][$slug]['core_layout'][$areaName]['modules'][$moduleName]['controllers'][$controllerName]['actions'][$actionName]['children_views'] as $k => $v) {
                            $viewModel->$k  = $v;
                        }
                    }
                }
            }
        }, 10);
    }

    /**
     * This method get the games and add them as Dynareas to PlaygroundCms so that blocks can be dynamically added to the games.
     *
     * @param  EventManager $e
     * @return array
     */
    public function updateDynareas(MvcEvent $e)
    {
        $dynareas = $e->getParam('dynareas');

        $gameService = $e->getTarget()->getServiceManager()->get('playgroundgame_game_service');

        $games = $gameService->getActiveGames();

        foreach ($games as $game) {
            $array = array('game'.$game->getId() => array('title' => $game->getTitle(), 'description' => $game->getClassType(), 'location'=>'pages du jeu'));
            $dynareas = array_merge($dynareas, $array);
        }

        return $dynareas;
    }

    /**
     * This method get the cron config for this module an add them to the listener
     *
     * @param  EventManager $e
     * @return array
     */
    public function addCronjob(MvcEvent $e)
    {
        $cronjobs = $e->getParam('cronjobs');

        $cronjobs['adfagame_email'] = array(
            'frequency' => '*/15 * * * *',
            'callback'  => '\PlaygroundGame\Service\Game::cronMail',
            'args'      => array('bar', 'baz'),
        );

        // tous les jours à 5:00 AM
        $cronjobs['adfagame_instantwin_email'] = array(
                'frequency' => '* 5 * * *',
                'callback'  => '\PlaygroundGame\Service\Cron::instantWinEmail',
                'args'      => array(),
        );

        return $cronjobs;
    }

    public function getConfig()
    {
        return include __DIR__ . '/../../config/module.config.php';
    }

    public function getAutoloaderConfig()
    {
        return array(
            'Zend\Loader\StandardAutoloader' => array(
                'namespaces' => array(
                    __NAMESPACE__ => __DIR__ . '/../../src/' . __NAMESPACE__,
                ),
            ),
        );
    }

    /**
     * @return array
     */
    public function getViewHelperConfig()
    {
        return array(
            'factories' => array(
                'playgroundPrizeCategory' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $locator = $sm->getServiceLocator();
                    $viewHelper = new View\Helper\PrizeCategory;
                    $viewHelper->setPrizeCategoryService($locator->get('playgroundgame_prizecategory_service'));

                    return $viewHelper;
                },
            ),
        );
    }

    public function getServiceConfig()
    {
        return array(
            'aliases' => array(
                // An alias for linking a partner service with PlaygroundGame without adherence
                'playgroundgame_partner_service' => 'playgroundpartnership_partner_service',
                'playgroundgame_message'         => 'playgroundcore_message',

            ),

            'invokables' => array(
                'playgroundgame_game_service'              => 'PlaygroundGame\Service\Game',
                'playgroundgame_lottery_service'           => 'PlaygroundGame\Service\Lottery',
                'playgroundgame_postvote_service'          => 'PlaygroundGame\Service\PostVote',
                'playgroundgame_quiz_service'              => 'PlaygroundGame\Service\Quiz',
                'playgroundgame_instantwin_service'        => 'PlaygroundGame\Service\InstantWin',
                'playgroundgame_prize_service'                => 'PlaygroundGame\Service\Prize',
                'playgroundgame_prizecategory_service'     => 'PlaygroundGame\Service\PrizeCategory',
                'playgroundgame_prizecategoryuser_service' => 'PlaygroundGame\Service\PrizeCategoryUser',
            ),

            'factories' => array(
                'playgroundgame_module_options' => function (\Zend\ServiceManager\ServiceManager $sm) {
                        $config = $sm->get('Configuration');

                        return new Options\ModuleOptions(isset($config['playgroundgame']) ? $config['playgroundgame'] : array());
                },

                'playgroundgame_game_mapper' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $mapper = new \PlaygroundGame\Mapper\Game(
                        $sm->get('doctrine.entitymanager.orm_default'),
                        $sm->get('playgroundgame_module_options')
                    );

                    return $mapper;
                },
                
                'playgroundgame_playerform_mapper' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $mapper = new \PlaygroundGame\Mapper\PlayerForm(
                        $sm->get('doctrine.entitymanager.orm_default'),
                        $sm->get('playgroundgame_module_options')
                    );
                
                    return $mapper;
                },

                'playgroundgame_lottery_mapper' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $mapper = new \PlaygroundGame\Mapper\Lottery(
                        $sm->get('doctrine.entitymanager.orm_default'),
                        $sm->get('playgroundgame_module_options')
                    );

                    return $mapper;
                },

                'playgroundgame_instantwin_mapper' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $mapper = new \PlaygroundGame\Mapper\InstantWin(
                        $sm->get('doctrine.entitymanager.orm_default'),
                        $sm->get('playgroundgame_module_options')
                    );

                    return $mapper;
                },

                'playgroundgame_instantwinoccurrence_mapper' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $mapper = new \PlaygroundGame\Mapper\InstantWinOccurrence(
                        $sm->get('doctrine.entitymanager.orm_default'),
                        $sm->get('playgroundgame_module_options')
                    );

                    return $mapper;
                },

                'playgroundgame_quiz_mapper' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $mapper = new \PlaygroundGame\Mapper\Quiz(
                        $sm->get('doctrine.entitymanager.orm_default'),
                        $sm->get('playgroundgame_module_options')
                    );

                    return $mapper;
                },

                'playgroundgame_quizquestion_mapper' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $mapper = new \PlaygroundGame\Mapper\QuizQuestion(
                        $sm->get('doctrine.entitymanager.orm_default'),
                        $sm->get('playgroundgame_module_options')
                    );

                    return $mapper;
                },

                'playgroundgame_quizanswer_mapper' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $mapper = new \PlaygroundGame\Mapper\QuizAnswer(
                        $sm->get('doctrine.entitymanager.orm_default'),
                        $sm->get('playgroundgame_module_options')
                    );

                    return $mapper;
                },

                'playgroundgame_quizreply_mapper' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $mapper = new \PlaygroundGame\Mapper\QuizReply(
                        $sm->get('doctrine.entitymanager.orm_default'),
                        $sm->get('playgroundgame_module_options')
                    );

                    return $mapper;
                },

                'playgroundgame_quizreplyanswer_mapper' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $mapper = new \PlaygroundGame\Mapper\QuizReplyAnswer(
                        $sm->get('doctrine.entitymanager.orm_default'),
                        $sm->get('playgroundgame_module_options')
                    );

                    return $mapper;
                },


                'playgroundgame_entry_mapper' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $mapper = new \PlaygroundGame\Mapper\Entry(
                        $sm->get('doctrine.entitymanager.orm_default'),
                        $sm->get('playgroundgame_module_options')
                    );

                    return $mapper;
                },

                'playgroundgame_postvote_mapper' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $mapper = new \PlaygroundGame\Mapper\PostVote(
                        $sm->get('doctrine.entitymanager.orm_default'),
                        $sm->get('playgroundgame_module_options')
                    );

                    return $mapper;
                },

                'playgroundgame_postvoteform_mapper' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $mapper = new \PlaygroundGame\Mapper\PostVoteForm(
                        $sm->get('doctrine.entitymanager.orm_default'),
                        $sm->get('playgroundgame_module_options')
                    );

                    return $mapper;
                },

                'playgroundgame_postvotepost_mapper' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $mapper = new \PlaygroundGame\Mapper\PostVotePost(
                        $sm->get('doctrine.entitymanager.orm_default'),
                        $sm->get('playgroundgame_module_options')
                    );

                    return $mapper;
                },

                'playgroundgame_postvotepostelement_mapper' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $mapper = new \PlaygroundGame\Mapper\PostVotePostElement(
                        $sm->get('doctrine.entitymanager.orm_default'),
                        $sm->get('playgroundgame_module_options')
                    );

                    return $mapper;
                },

                'playgroundgame_postvotevote_mapper' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $mapper = new \PlaygroundGame\Mapper\PostVoteVote(
                        $sm->get('doctrine.entitymanager.orm_default'),
                        $sm->get('playgroundgame_module_options')
                    );

                    return $mapper;
                },

                'playgroundgame_prize_mapper' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $mapper = new \PlaygroundGame\Mapper\Prize(
                        $sm->get('doctrine.entitymanager.orm_default'),
                        $sm->get('playgroundgame_module_options')
                    );

                    return $mapper;
                },

                'playgroundgame_prizecategory_mapper' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $mapper = new \PlaygroundGame\Mapper\PrizeCategory(
                        $sm->get('doctrine.entitymanager.orm_default'),
                        $sm->get('playgroundgame_module_options')
                    );

                    return $mapper;
                },

                'playgroundgame_prizecategoryuser_mapper' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $mapper = new \PlaygroundGame\Mapper\PrizeCategoryUser(
                        $sm->get('doctrine.entitymanager.orm_default'),
                        $sm->get('playgroundgame_module_options')
                    );

                    return $mapper;
                },

                'playgroundgame_game_form' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $translator = $sm->get('translator');
                    $form = new Form\Admin\Game(null, $sm, $translator);
                    $game = new Entity\Game();
                    $form->setInputFilter($game->getInputFilter());

                    return $form;
                },

                'playgroundgame_register_form' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $translator = $sm->get('translator');
                    $zfcUserOptions = $sm->get('zfcuser_module_options');
                    $form = new Form\Frontend\Register(null, $zfcUserOptions, $translator, $sm);
                    //$form->setCaptchaElement($sm->get('zfcuser_captcha_element'));
                    $form->setInputFilter(new \ZfcUser\Form\RegisterFilter(
                        new \ZfcUser\Validator\NoRecordExists(array(
                            'mapper' => $sm->get('zfcuser_user_mapper'),
                            'key'    => 'email'
                        )),
                        new \ZfcUser\Validator\NoRecordExists(array(
                            'mapper' => $sm->get('zfcuser_user_mapper'),
                            'key'    => 'username'
                        )),
                        $zfcUserOptions
                    ));

                    return $form;
                },

                'playgroundgame_import_form' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $translator = $sm->get('translator');
                    $form = new Form\Admin\Import(null, $sm, $translator);
                
                    return $form;
                },

                'playgroundgame_lottery_form' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $translator = $sm->get('translator');
                    $form = new Form\Admin\Lottery(null, $sm, $translator);
                    $lottery = new Entity\Lottery();
                    $form->setInputFilter($lottery->getInputFilter());

                    return $form;
                },

                'playgroundgame_quiz_form' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $translator = $sm->get('translator');
                    $form = new Form\Admin\Quiz(null, $sm, $translator);
                    $quiz = new Entity\Quiz();
                    $form->setInputFilter($quiz->getInputFilter());

                    return $form;
                },

                'playgroundgame_instantwin_form' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $translator = $sm->get('translator');
                    $form = new Form\Admin\InstantWin(null, $sm, $translator);
                    $instantwin = new Entity\InstantWin();
                    $form->setInputFilter($instantwin->getInputFilter());

                    return $form;
                },

                'playgroundgame_quizquestion_form' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $translator = $sm->get('translator');
                    $form = new Form\Admin\QuizQuestion(null, $sm, $translator);
                    $quizQuestion = new Entity\QuizQuestion();
                    $form->setInputFilter($quizQuestion->getInputFilter());

                    return $form;
                },

                'playgroundgame_instantwinoccurrence_form' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $translator = $sm->get('translator');
                    $form = new Form\Admin\InstantWinOccurrence(null, $sm, $translator);
                    $instantwinOccurrence = new Entity\InstantWinOccurrence();
                    $form->setInputFilter($instantwinOccurrence->getInputFilter());

                    return $form;
                },

                'playgroundgame_instantwinoccurrenceimport_form' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $translator = $sm->get('translator');
                    $form = new Form\Admin\InstantWinOccurrenceImport(null, $sm, $translator);
                    return $form;
                },

                'playgroundgame_instantwinoccurrencecode_form' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $translator = $sm->get('translator');
                    $form = new Form\Frontend\InstantWinOccurrenceCode(null, $sm, $translator);
                    $filter = new Form\Frontend\InstantWinOccurrenceCodeFilter();
                    $form->setInputFilter($filter);
                    return $form;
                },

                'playgroundgame_postvote_form' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $translator = $sm->get('translator');
                    $form = new Form\Admin\PostVote(null, $sm, $translator);
                    $postVote = new Entity\PostVote();
                    $form->setInputFilter($postVote->getInputFilter());

                    return $form;
                },

                'playgroundgame_prizecategory_form' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $translator = $sm->get('translator');
                    $form = new Form\Admin\PrizeCategory(null, $sm, $translator);
                    $prizeCategory = new Entity\PrizeCategory();
                    $form->setInputFilter($prizeCategory->getInputFilter());

                    return $form;
                },

                'playgroundgame_prizecategoryuser_form' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $translator = $sm->get('translator');
                    $form = new Form\Frontend\PrizeCategoryUser(null, $sm, $translator);

                    return $form;
                },

                'playgroundgame_sharemail_form' => function (\Zend\ServiceManager\ServiceManager $sm) {
                    $translator = $sm->get('translator');
                    $form = new Form\Frontend\ShareMail(null, $sm, $translator);
                    $form->setInputFilter(new Form\Frontend\ShareMailFilter());

                    return $form;
                },
            ),
        );
    }
}
