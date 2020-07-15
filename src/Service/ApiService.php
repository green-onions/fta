<?php

namespace App\Service;

use App\Entity\UserMobicoop;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Class ApiService
 * @package App\Service
 */
class ApiService
{
    /**
     *
     */
    const BASE_URL = 'https://api.mobicoop.io';

    /**
     * @var SessionInterface
     */
    private SessionInterface $session;
    /**
     * @var ContainerInterface
     */
    private ContainerInterface $container;

    /**
     * ApiService constructor.
     * @param SessionInterface $session
     * @param ContainerInterface $container
     */
    public function __construct(SessionInterface $session, ContainerInterface $container)
    {
        $this->session = $session;
        $this->container = $container;
    }

    /**
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    public function getToken(): void
    {
        $client = HttpClient::create();
        $username = $this->container->getParameter('mobicoop_user');
        $password = $this->container->getParameter('mobicoop_password');
        $response = $client->request('POST', self::BASE_URL . '/auth', [
            'json' => ['username' => $username, 'password' => $password]
        ]);
        $allToken = ApiService::decodeJson($response->getContent());
        $token = $allToken['token'];
        $refreshToken = $allToken['refreshToken'];
        $this->session->set('token', $token);
        $this->session->set('refreshToken', $refreshToken);
    }

    public function baseUri()
    {
        $client = HttpClient::createForBaseUri(self::BASE_URL, [
            // HTTP Bearer authentication (also called token authentication)
            'auth_bearer' => $this->session->get('token'),
        ]);
        return $client;
    }

    /**
     * @param FormInterface $form
     * @return array
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    public function getUser(FormInterface $form): array
    {
        $client = $this->baseUri();
        $response = $client->request('GET', '/users', [
            'query' => [
                'email' => $form->getData()['email']
            ]
        ]);
        return ApiService::decodeJson($response->getContent());
    }

    /**
     * @param int $mobicoopId
     * @return array
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    public function getUserById(int $mobicoopId): array
    {
        $client = $this->baseUri();
        $response = $client->request('GET', '/users/' . $mobicoopId);
        return ApiService::decodeJson($response->getContent());
    }

    public function getUserByGivenName(string $name): array
    {
        $client = $this->baseUri();
        $response = $client->request('GET', '/users', [
            'query' => [
                'givenName' => $name
            ]
        ]);
        return ApiService::decodeJson($response->getContent());
    }

    public function getAllUsers(): array
    {
        $client = $this->baseUri();
        $response = $client->request('GET', '/users');
        return ApiService::decodeJson($response->getContent());
    }

    public function setFullName(array $usersMobicoop, array $users): ?array
    {
        $result = null;
        foreach ($usersMobicoop['hydra:member'] as $userMobicoop) {
            foreach ($users as $user) {
                if ($userMobicoop['id'] === $user->getMobicoopId()) {
                    $user->setGivenName($userMobicoop['givenName']);
                    $user->setFamilyName($userMobicoop['familyName']);
                    $result = $users;
                }
            }
        }
        return $result;
    }

    /**
     * @param array $array
     * @return UserMobicoop
     */
    public function makeUser(array $array): UserMobicoop
    {
        $user = new UserMobicoop();
        $user->setMobicoopId($array['hydra:member'][0]['id']);
        $user->setGivenName($array['hydra:member'][0]['givenName']);
        $user->setFamilyName($array['hydra:member'][0]['familyName']);
        $user->setGender($array['hydra:member'][0]['gender']);
        $user->setPhone($array['hydra:member'][0]['telephone']);
        $user->setAvatar($array['hydra:member'][0]['avatars'][0]);
        $user->setRole($array['hydra:member'][0]['roles'][0]);
        return $user;
    }

    /**
     * @param string $passwordSaved
     * @param string $password
     * @return bool
     */
    public static function passwordVerify(string $passwordSaved, string $password): bool
    {
        return password_verify($password, $passwordSaved);
    }

    /**
     * @param array $array
     * @return array
     */
    public static function addPhoneDisplay(array $array): array
    {
        $array['phoneDisplay'] = 1;
        return $array;
    }

    /**
     * @param string $string
     * @return array
     */
    public static function decodeJson(string $string): array
    {
        return json_decode($string, true);
    }

    public static function createAjaxUserArray(array $usersMobicoop, $usersCommon): array
    {
        $newArray = [];
        $inc = 0;
        foreach ($usersMobicoop['hydra:member'] as $user) {
            foreach ($usersCommon as $data) {
                if ($user['id'] === $data->getMobicoopId()) {
                    $newArray[$inc]['id'] = $data->getId();
                    $newArray[$inc]['mobicoopId'] = $data->getMobicoopId();
                    $newArray[$inc]['givenName'] = $user['givenName'];
                    $newArray[$inc]['familyName'] = $user['familyName'];
                    $newArray[$inc]['status'] = $data->getStatus();
                    $newArray[$inc]['isActive'] = $data->getIsActive();
                    $inc++;
                }
            }
        }
        return $newArray;
    }
}
