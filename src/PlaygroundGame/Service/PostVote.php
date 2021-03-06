<?php

namespace PlaygroundGame\Service;

use Zend\ServiceManager\ServiceManagerAwareInterface;
use Zend\Stdlib\ErrorHandler;

class PostVote extends Game implements ServiceManagerAwareInterface
{
    protected $postvoteMapper;
    protected $postvoteformMapper;
    protected $postVotePostMapper;
    protected $postVoteVoteMapper;
    protected $postVotePostElementMapper;

    public function getGameEntity()
    {
        return new \PlaygroundGame\Entity\PostVote;
    }

    public function uploadFileToPost($data, $game, $user)
    {
        $result = false;
        $postvotePostMapper = $this->getPostVotePostMapper();
        $postVotePostElementMapper = $this->getPostVotePostElementMapper();

        $entry = $this->findLastActiveEntry($game, $user);

        if (!$entry) {
            return '0';
        }

        $post = $postvotePostMapper->findOneBy(array('entry' => $entry));

        if (! $post) {
            $post = new \PlaygroundGame\Entity\PostVotePost();
            $post->setPostvote($game);
            $post->setUser($user);
            $post->setEntry($entry);
            $post = $postvotePostMapper->insert($post);
        }

        $path = $this->getOptions()->getMediaPath() . DIRECTORY_SEPARATOR . 'game' . $game->getId() . DIRECTORY_SEPARATOR;
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $path .= 'post'. $post->getId() . DIRECTORY_SEPARATOR;
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        $media_url = $this->getOptions()->getMediaUrl() . '/' . 'game' . $game->getId() . '/' . 'post'. $post->getId() . '/';

        $key = key($data);
        $uploadFile = $this->uploadFile($path, $data[$key]);

        if ($uploadFile) {
            $postElement = $postVotePostElementMapper->findOneBy(array('post' => $post, 'name' => $key));
            if (! $postElement) {
                $postElement = new \PlaygroundGame\Entity\PostVotePostElement();
            }
            $postElement->setName($key);
            $postElement->setPosition(0);
            $postElement->setValue($media_url.$uploadFile);
            $postElement->setPost($post);
            $postElement = $postVotePostElementMapper->insert($postElement);

            $result = $media_url.$uploadFile;
        }

        return $result;
    }

    public function deleteFilePosted($data, $game, $user)
    {
        $postvotePostMapper = $this->getPostVotePostMapper();
        $postVotePostElementMapper = $this->getPostVotePostElementMapper();

        $entry = $this->findLastActiveEntry($game, $user);

        if (!$entry) {
            return 'falsefin0';
        }

        $post = $postvotePostMapper->findOneBy(array('entry' => $entry));
        $element = $postVotePostElementMapper->findOneBy(array('post' => $post->getId(), 'name' => $data['name']));

        if ($element) {
            $element = $postVotePostElementMapper->remove($element);
            if ($element) {
                return true;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    /**
     *
     * @param  array                  $data
     * @return \PlaygroundGame\Entity\Game
     */
    public function createPost(array $data, $game, $user, $form)
    {
        $postvotePostMapper = $this->getPostVotePostMapper();
        $postVotePostElementMapper = $this->getPostVotePostElementMapper();

        $entry = $this->findLastActiveEntry($game, $user);

        if (!$entry) {
            return false;
        }

        $post = $postvotePostMapper->findOneBy(array('entry' => $entry));

        if (! $post) {
            $post = new \PlaygroundGame\Entity\PostVotePost();
            $post->setPostvote($game);
            $post->setUser($user);
            $post->setEntry($entry);
            $post = $postvotePostMapper->insert($post);
        }

        $path = $this->getOptions()->getMediaPath() . DIRECTORY_SEPARATOR . 'game' . $game->getId() . DIRECTORY_SEPARATOR;
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $path .= 'post'. $post->getId() . DIRECTORY_SEPARATOR;
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        $media_url = $this->getOptions()->getMediaUrl() . '/' . 'game' . $game->getId() . '/' . 'post'. $post->getId() . '/';
        $position=1;

        foreach ($data as $name => $value) {
            $postElement = $postVotePostElementMapper->findOneBy(array('post' => $post, 'name' => $name));
            if (! $postElement) {
                $postElement = new \PlaygroundGame\Entity\PostVotePostElement();
            }
            $postElement->setName($name);
            $postElement->setPosition($position);

            if (is_array($value) && isset($value['tmp_name'])) {
                // The file upload has been done in ajax but some weird bugs remain without it

                if (! $value['error']) {
                    ErrorHandler::start();
                    $value['name'] = $this->fileNewname($path, $value['name'], true);
                    move_uploaded_file($value['tmp_name'], $path . $value['name']);
                    $image = $this->getServiceManager()->get('playgroundcore_image_service');
                    $image->setImage($path . $value['name']);

                    if ($image->canCorrectOrientation()) {
                        $image->correctOrientation()->save();
                    }
                    $postElement->setValue($media_url . $value['name']);
                    
                    if (class_exists("Imagick")) {
                        $ext = pathinfo($value['name'], PATHINFO_EXTENSION);
                        $img = new \Imagick($path . $value['name']);
                        $img->cropThumbnailImage(100, 100);
                        $img->setImageCompression(\Imagick::COMPRESSION_JPEG);
                        $img->setImageCompressionQuality(75);
                        // Strip out unneeded meta data
                        $img->stripImage();
                        $img->writeImage($path . str_replace('.'.$ext, '-thumbnail.'.$ext, $value['name']));
                        ErrorHandler::stop(true);
                    }
                }
            } elseif (is_array($value)) {
                $arValues = $form->get($name)->getValueOptions();
                $postElement->setValue($arValues[$value[0]]);
            } elseif (!empty($value)) {
                $postElement->setValue($value);
            }
            $postElement->setPost($post);
            $postVotePostElementMapper->insert($postElement);
            $position++;
        }

        $postvotePostMapper->update($post);

        // If a preview step is not proposed, I confirmPost on this step
        $steps = $game->getStepsArray();
        $previewKey = array_search('preview', $steps);
        if (!$previewKey) {
            $post = $this->confirmPost($game, $user);
        }

        $this->getEventManager()->trigger(__FUNCTION__ . '.post', $this, array(
            'user' => $user,
            'game' => $game,
            'post' => $post,
            'entry' => $entry
        ));

        return $post;
    }

    /**
     *
     * @return \PlaygroundGame\Entity\Game
     */
    public function confirmPost($game, $user)
    {
        $postvotePostMapper = $this->getPostVotePostMapper();

        $entryMapper = $this->getEntryMapper();
        $entry = $this->findLastActiveEntry($game, $user);

        if (!$entry) {
            return false;
        }

        $post = $postvotePostMapper->findOneBy(array('entry' => $entry));

        if (! $post) {
            return false;
        }

        // The post is confirmed by user. I update the status and close the associated entry
        // Post are validated by default, unless pre-moderation is enable for the game
        if ($game->getModerationType()) {
            $post->setStatus(1);
        } else {
            $post->setStatus(2);
        }

        $postvotePostMapper->update($post);

        $entry->setActive(0);
        $entryMapper->update($entry);

        $this->getEventManager()->trigger(__FUNCTION__ . '.post', $this, array(
            'user' => $user,
            'game' => $game,
            'entry' => $entry,
            'post' => $post
        ));

        if ($user) {
            // send mail for participation
            $this->sendGameMail($game, $user, $post, 'postvote');
        }

        return $post;
    }

    /**
     *
     * This service is ready for all types of games
     *
     * @param  array                  $data
     * @return \PlaygroundGame\Entity\Game
     */
    public function createForm(array $data, $game, $form = null)
    {
        $title ='';
        $description = '';

        if ($data['form_jsonified']) {
            $jsonPV = json_decode($data['form_jsonified']);
            foreach ($jsonPV as $element) {
                if ($element->form_properties) {
                    $attributes  = $element->form_properties[0];
                    $title       = $attributes->title;
                    $description = $attributes->description;

                    break;
                }
            }
        }
        if (!$form) {
            $form = new \PlaygroundGame\Entity\PostVoteForm();
        }
        $form->setPostvote($game);
        $form->setTitle($title);
        $form->setDescription($description);
        $form->setForm($data['form_jsonified']);
        $form->setFormTemplate($data['form_template']);

        $form = $this->getPostVoteFormMapper()->insert($form);

        return $form;
    }

    public function findArrayOfValidatedPosts($game, $filter, $search = '')
    {
        $em = $this->getServiceManager()->get('doctrine.entitymanager.orm_default');
        $qb = $em->createQueryBuilder();
        $and = $qb->expr()->andx();
        
        $and->add($qb->expr()->eq('p.status', 2));

        $and->add($qb->expr()->eq('g.id', ':game'));
        $qb->setParameter('game', $game);
        
        if ($search != '') {
            $and->add(
                $qb->expr()->orX(
                    $qb->expr()->like('u.username', $qb->expr()->literal('%:search%')),
                    $qb->expr()->like('u.firstname', $qb->expr()->literal('%:search%')),
                    $qb->expr()->like('u.lastname', $qb->expr()->literal('%:search%')),
                    $qb->expr()->like('e.value', $qb->expr()->literal('%:search%')),
                    $qb->expr()->isNull('g.publicationDate')
                )
            );
            $qb->setParameter('search', $search);
        }
        
        if ('push' == $filter) {
            $and->add(
                $qb->expr()->andX(
                    $qb->expr()->eq('p.pushed', 1)
                )
            );
        }
        
        $qb->select('p, COUNT(v) AS votesCount')
            ->from('PlaygroundGame\Entity\PostVotePost', 'p')
            ->innerJoin('p.postvote', 'g')
            ->leftJoin('p.user', 'u')
            ->innerJoin('p.postElements', 'e')
            ->leftJoin('p.votes', 'v')
            ->where($and)
            ->groupBy('p.id');
 
        switch ($filter) {
            case 'random':
                $qb->orderBy('e.value', 'ASC');
                break;
            case 'vote':
                $qb->orderBy('votesCount', 'DESC');
                break;
            case 'date':
                $qb->orderBy('p.createdAt', 'DESC');
                break;
            case 'push':
                $qb->orderBy('p.createdAt', 'DESC');
                break;
        }
        
        $query = $qb->getQuery();
        
        $posts = $query->getResult();
        $arrayPosts = array();
        $i=0;
        foreach ($posts as $postRaw) {
            $data = array();
            $post = $postRaw[0];
            if ($post) {
                foreach ($post->getPostElements() as $element) {
                    $data[$element->getPosition()] = $element->getValue();
                }
                $arrayPosts[$i]['data']  = $data;
                $arrayPosts[$i]['votes'] = count($post->getVotes());
                $arrayPosts[$i]['id']    = $post->getId();
                $arrayPosts[$i]['user']  = $post->getUser();
                $arrayPosts[$i]['createdAt']  = $post->getCreatedAt();
                $i++;
            }
        }

        return $arrayPosts;
    }

    public function addVote($user, $ipAddress, $post)
    {
        $postvoteVoteMapper = $this->getPostVoteVoteMapper();
        $postId = $post->getId();

        if ($user) {
            $userId = $user->getId();
            $entryUser = count($postvoteVoteMapper->findBy(array('userId' => $userId, 'post' =>$postId)));
        } else {
            $entryUser =count($postvoteVoteMapper->findBy(array('ip' => $ipAddress, 'post' =>$postId)));
        }
        if ($entryUser && $entryUser > 0) {
            return false;
        } else {
            $vote = new \PlaygroundGame\Entity\PostVoteVote();
            $vote->setPost($post);
            $vote->setIp($ipAddress);
            if ($user) {
                $vote->setUserId($user->getId());
            }

            $postvoteVoteMapper->insert($vote);
            $game = $post->getPostvote();
            $this->getEventManager()->trigger('vote_postvote.post', $this, array('user' => $user, 'game' => $game, 'post' => $post, 'vote' => $vote));

            return true;
        }
    }

    public function getEntriesHeader($game)
    {
        $header = parent::getEntriesHeader($game);
        if ($game->getForm()) {
            $form = json_decode($game->getForm()->getForm(), true);
            foreach ($form as $element) {
                foreach ($element as $k => $v) {
                    if ($k !== 'form_properties') {
                        $header[$v[0]['name']] = 1;
                    }
                }
            }
        }

        return $header;
    }

    public function getEntriesQuery($game)
    {
        $em = $this->getServiceManager()->get('doctrine.entitymanager.orm_default');

        $qb = $em->createQueryBuilder();
        $qb->select('
            p.id,
            u.username,
            u.title,
            u.firstname,
            u.lastname,
            u.email,
            u.optin,
            u.optinPartner,
            u.address,
            u.address2,
            u.postalCode,
            u.city,
            u.telephone,
            u.mobile,
            u.created_at,
            u.dob,
            e.winner,
            e.socialShares,
            e.playerData,
            e.updated_at,
            p.status,
            p
            ')
            ->from('PlaygroundGame\Entity\PostVotePost', 'p')
            ->innerJoin('p.entry', 'e')
            ->leftJoin('p.user', 'u')
            ->where($qb->expr()->eq('e.game', ':game'));
        
        $qb->setParameter('game', $game);

        return $qb->getQuery();
    }

    /**
    * getGameEntries : All entries of a game
    *
    * @return Array of PlaygroundGame\Entity\Game
    */
    public function getGameEntries($header, $entries, $game)
    {
        
        $results = array();

        foreach ($entries as $k => $entry) {
            $entryData = json_decode($entry['playerData'], true);
            $postElements = $entry[0]->getPostElements();

            foreach ($header as $key => $v) {
                if (isset($entryData[$key]) && $key !=='id') {
                    $results[$k][$key] = (is_array($entryData[$key]))?implode(', ', $entryData[$key]):$entryData[$key];
                } elseif (array_key_exists($key, $entry)) {
                    $results[$k][$key] = ($entry[$key] instanceof \DateTime)?$entry[$key]->format('Y-m-d'):$entry[$key];
                } else {
                    $results[$k][$key] = '';
                }

                foreach ($postElements as $e) {
                    if ($key === $e->getName()) {
                        $results[$k][$key] = (is_array($e->getValue()))?implode(', ', $e->getValue()):$e->getValue();
                        break;
                    }
                }
            }
        }

        return $results;
    }

    public function getPostVoteFormMapper()
    {
        if (null === $this->postvoteformMapper) {
            $this->postvoteformMapper = $this->getServiceManager()->get('playgroundgame_postvoteform_mapper');
        }

        return $this->postvoteformMapper;
    }

    public function setPostVoteFormMapper($postvoteformMapper)
    {
        $this->postvoteformMapper = $postvoteformMapper;

        return $this;
    }

    public function getPostVotePostElementMapper()
    {
        if (null === $this->postVotePostElementMapper) {
            $this->postVotePostElementMapper = $this->getServiceManager()->get('playgroundgame_postvotepostelement_mapper');
        }

        return $this->postVotePostElementMapper;
    }

    public function setPostVotePostElementMapper($postVotePostElementMapper)
    {
        $this->postVotePostElementMapper = $postVotePostElementMapper;

        return $this;
    }

    public function getPostVoteVoteMapper()
    {
        if (null === $this->postVoteVoteMapper) {
            $this->postVoteVoteMapper = $this->getServiceManager()->get('playgroundgame_postvotevote_mapper');
        }

        return $this->postVoteVoteMapper;
    }

    public function setPostVoteVoteMapper($postVoteVoteMapper)
    {
        $this->postVoteVoteMapper = $postVoteVoteMapper;

        return $this;
    }

    public function getPostVotePostMapper()
    {
        if (null === $this->postVotePostMapper) {
            $this->postVotePostMapper = $this->getServiceManager()->get('playgroundgame_postvotepost_mapper');
        }

        return $this->postVotePostMapper;
    }

    public function setPostVotePostMapper($postVotePostMapper)
    {
        $this->postVotePostMapper = $postVotePostMapper;

        return $this;
    }

    public function getPostVoteMapper()
    {
        if (null === $this->postvoteMapper) {
            $this->postvoteMapper = $this->getServiceManager()->get('playgroundgame_postvote_mapper');
        }

        return $this->postvoteMapper;
    }

    /**
     * setQuizQuestionMapper
     *
     * @return PostVote
     */
    public function setPostVoteMapper($postvoteMapper)
    {
        $this->postvoteMapper = $postvoteMapper;

        return $this;
    }
}
