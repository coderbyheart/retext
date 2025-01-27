<?php

namespace Retext\ApiBundle\Controller;

use Retext\ApiBundle\Controller\RequestParameter,
Retext\ApiBundle\Model\LinkedData,
Retext\ApiBundle\Model\Element;

use Symfony\Bundle\FrameworkBundle\Controller\Controller,
Symfony\Component\HttpFoundation\Response,
Symfony\Component\HttpKernel\Exception\HttpException;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;

/**
 * Basisklasse für alle Controller
 *
 * @author Markus Tacker <m@tckr.cc>
 */
abstract class Base extends Controller
{
    /**
     * @param mixed|null $data
     * @return \Retext\ApiBundle\Controller\ApiResponse
     */
    public function createResponse($data = null)
    {
        $request = $this->getRequest();
        $response = new ApiResponse();

        /* CORS stuff
            ->addHeader('Access-Control-Allow-Origin', $this->getRequest()->headers->get('Origin'))
            ->addHeader('Access-Control-Allow-Credentials', 'true')
            ->addHeader('Access-Control-Max-Age', '604800')
            ->addHeader('Access-Control-Allow-Methods', 'GET,POST,PUT,DELETE,OPTIONS')
            ->addHeader('Access-Control-Allow-Headers', 'X-CSRF-Token, X-Requested-With, Accept, Accept-Version, Content-Length, Content-MD5, Content-Type, Date, X-Api-Version');
        */
        if ($data !== null) {
            if (!in_array('application/json', $request->getAcceptableContentTypes())) throw $this->createException(406, 'Not Acceptable | You must accept application/json');
            $response->addHeader('Content-Type', 'application/json');
            $response->setContent($this->container->get('serializer')->serialize($data, 'json'));
        }
        return $response;
    }

    /**
     * @param mixed|null $data
     * @param mixed|null $order list of ids in the order the items should appear in the list
     * @return \Retext\ApiBundle\Controller\ApiResponse
     */
    public function createListResponse($data = null, $order = null)
    {
        if ($data instanceof \Iterator || is_array($data)) {
            $items = array();
            $itemPos = array();
            $orderItems = $order !== null;
            if ($orderItems) $idPos = array_flip($order);
            foreach ($data as $d) {
                $items[] = $d;
                if ($orderItems && $d instanceof \Retext\ApiBundle\Model\Element) $itemPos[] = $idPos[$d->getId()];
            }
            if ($orderItems) array_multisort($itemPos, SORT_ASC, $items);
        } else {
            $items = array($data);
        }
        return $this->createResponse($items);
    }

    /**
     * @return \Symfony\Component\HttpKernel\Exception\HttpException
     */
    public function createException($code, $message)
    {
        return new HttpException($code, $message, null, array('Content-Type' => 'application/json'));
    }

    /**
     * @return \Symfony\Component\HttpKernel\Exception\HttpException
     */
    public function createForbiddenException($message = null)
    {
        return $this->createException(403, $message == null ? 'Forbidden' : $message);
    }


    /**
     * @return \Symfony\Component\HttpKernel\Exception\HttpException
     */
    public function createGoneException($message = null)
    {
        return $this->createException(410, $message == null ? 'Gone' : $message);
    }

    /**
     * Make sure the current user is logged in
     */
    public function ensureLoggedIn()
    {
        if (!$this->getRequest()->getSession()->has('User')) throw $this->createForbiddenException();
    }

    /**
     * @return \Retext\ApiBundle\Document\User|null
     */
    public function getUser()
    {
        $this->ensureLoggedIn();
        return $this->getRequest()->getSession()->get('User');
    }

    /**
     * @param $key
     * @return array|string
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    public function getFromRequest()
    {
        $request = $this->getRequest();

        if ($request->getMethod() === 'GET') {
            $hasKey = function(RequestParameter $key) use($request)
            {
                return $request->query->has($key->getName());
            };
            $getKeyValue = function(RequestParameter $key) use($request)
            {
                return $request->query->get($key->getName());
            };
        } else {
            if ((int)$request->headers->get('Content-Length') > 0) if ($request->getContentType() != 'json') throw $this->createException(400, 'Bad Request | Content-Type must be application/json');
            $data = json_decode($request->getContent());
            if ($data === null) $data = new \stdClass();
            if (is_array($data)) throw $this->createException(400, 'Bad Request | Must send object, array sent.');
            $hasKey = function(RequestParameter $key) use($data)
            {
                return property_exists($data, $key->getName());
            };
            $getKeyValue = function(RequestParameter $key) use($data)
            {
                return $data->{$key->getName()};
            };

        }

        $getKey = function($key) use($hasKey, $getKeyValue)
        {
            if (!($key instanceof RequestParameter)) {
                /** @var \Retext\ApiBundle\Controller\RequestParameter $key  */
                $key = RequestParameter::create($key);
            }
            if (!$hasKey($key)) {
                if ($key->isRequired()) {
                    throw $this->createException(400, 'Bad Request | Missing input: ' . $key->getName());
                }
                return $key->getDefaultValue();
            }
            $value = $getKeyValue($key);

            if ($key->isBoolean()) {
                $isEmpty = function($value)
                {
                    if (is_bool($value)) return false;
                    return strlen($value) === 0;
                };
            } else {
                $isEmpty = function($value)
                {
                    return empty($value);
                };
            }

            if ($isEmpty($value)) {
                if ($key->isRequired()) {
                    throw $this->createException(400, 'Bad Request | Empty input: ' . $key->getName());
                }
                return $key->getDefaultValue();
            }
            switch ($key->getFormat()) {
                case RequestParameter::FORMAT_INTEGER:
                    if (!preg_match('/^[0-9]+$/', $value)) throw $this->createException(400, 'Bad Request | input ' . $key->getName() . ' must be integer');
                    return (int)$value;
                case RequestParameter::FORMAT_BOOLEAN:
                    if (is_bool($value)) return $value;
                    if (!preg_match('/^true|false|0|1$/', $value)) throw $this->createException(400, 'Bad Request | input ' . $key->getName() . ' must be boolean');
                    return (boolean)$value;
                case RequestParameter::FORMAT_LIST:
                    $data = $value;
                    if (!is_array($data)) throw $this->createException(400, 'Bad Request | input ' . $key->getName() . ' must be list');
                    return $data;
                case RequestParameter::FORMAT_OBJECT:
                    $data = $value;
                    if (!is_object($data)) throw $this->createException(400, 'Bad Request | input ' . $key->getName() . ' must be object');
                    return $data;
                default:
                    return $value;
            }
        };

        $args = func_get_args();
        if (count($args) == 1) {
            return $getKey($args[0]);
        } else {
            $return = array();
            foreach ($args as $key) $return[] = $getKey($key);
            return $return;
        }

    }

    /**
     * @param string $project_id
     * @return \Retext\ApiBundle\Document\Project
     */
    protected function getProject($project_id)
    {
        $user = $this->getUser();
        $projectByOwner = $this->getDocumentOrNUll('Project', $project_id, function(\Doctrine\ODM\MongoDB\Query\Builder $qb) use($user)
        {
            $qb->field('owner')->equals(new \MongoId($user->getId()));
        });
        if ($projectByOwner) return $projectByOwner;
        $projectByContributor = $this->getDocument('Project', $project_id, function(\Doctrine\ODM\MongoDB\Query\Builder $qb) use($user)
        {
            $qb->field('contributors')->all(array($user->getEmail()));
        });
        if ($projectByContributor) return $projectByContributor;
    }

    /**
     * @param string $email
     * @return \Retext\ApiBundle\Document\User
     */
    protected function getUserByEmail($email)
    {
        return $this->getDocument('User', null, function(\Doctrine\ODM\MongoDB\Query\Builder $qb) use($email)
        {
            $qb->field('email')->equals($email);
        });
    }

    /**
     * @param string $container_id
     * @return \Retext\ApiBundle\Document\Container
     */
    protected function getContainer($container_id)
    {
        return $this->getDocument('Container', $container_id);
    }

    /**
     * @param string $text_id
     * @return \Retext\ApiBundle\Document\Text
     */
    protected function getText($text_id)
    {
        return $this->getDocument('Text', $text_id);
    }

    /**
     * @param string $texttype_id
     * @return \Retext\ApiBundle\Document\TextType
     */
    protected function getTextType($texttype_id)
    {
        return $this->getDocument('TextType', $texttype_id);
    }

    /**
     * @param $collection
     * @param $id
     * @param \closure Closure zum modifizieren des Queries
     * @return \Retext\ApiBundle\Model\Base
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    protected function getDocument($collection, $id = null, \closure $queryModifier = null)
    {
        $dm = $this->get('doctrine.odm.mongodb.document_manager');
        $qb = $dm->getRepository('RetextApiBundle:' . $collection)
            ->createQueryBuilder();
        if ($id !== null) $qb->field('id')->equals(new \MongoId($id));
        if ($queryModifier !== null) $queryModifier($qb);
        $doc = $qb->getQuery()
            ->getSingleResult();

        if ($doc === null)
            throw $this->createNotFoundException($collection . ' ' . $id . ' not found.');
        if ($doc instanceof \Doctrine\ODM\MongoDB\SoftDelete\SoftDeleteable && $doc->getDeletedAt() !== null)
            throw $this->createGoneException($collection . ' ' . $id . ' has been deleted.');
        return $doc;
    }

    /**
     * @see getDocument
     */
    protected function getDocumentOrNull($collection, $id = null, \closure $queryModifier = null)
    {
        try {
            return $this->getDocument($collection, $id, $queryModifier);
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            return null;
        }
    }

    /**
     * Aktualisiert das Eltern-Element von $element, wenn dieses gelöscht wird
     *
     * @param $element
     */
    protected function removedChildElement(Element $element)
    {
        $dm = $this->get('doctrine.odm.mongodb.document_manager');
        $dm->getRepository('RetextApiBundle:Container')
            ->createQueryBuilder()
            ->findAndUpdate()
            ->field('id')->equals(new \MongoId($element->getParent()->getId()))
            ->update()
            ->field('childCount')->inc(-1)
            ->field('childOrder')->pull($element->getId())
            ->getQuery()
            ->execute();
    }

    /**
     * Aktualisiert das Eltern-Element von $element, wenn dieses hinzugefügt wird
     *
     * @param $element
     */
    protected function addedChildElement(Element $element)
    {
        $dm = $this->get('doctrine.odm.mongodb.document_manager');
        $dm->getRepository('RetextApiBundle:Container')
            ->createQueryBuilder()
            ->findAndUpdate()
            ->field('id')->equals(new \MongoId($element->getParent()->getId()))
            ->update()
            ->field('childCount')->inc(1)
            ->field('childOrder')->push($element->getId())
            ->getQuery()
            ->execute();
    }


}
