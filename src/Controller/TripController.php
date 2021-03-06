<?php

namespace App\Controller;

use App\Entity\Trip;
use App\Entity\User;
use App\Form\TripType;
use App\Service\ApiService;
use App\Service\EmailService;
use App\Service\TripService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

class TripController extends AbstractController
{
    /**
     * Route to show more information on a trip (ROLE_USER_VOLUNTEER && ROLE_USER_BENEFICIARY)
     * @Route("/common/trip/{id}", name="trip_show", methods={"GET"})
     * @param ApiService $api
     * @param Trip $trip
     * @return Response
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    public function show(ApiService $api, Trip $trip): Response
    {
        $volunteer = null;
        $userID = $trip->getBeneficiary()->getMobicoopId();
        $api->getToken();
        $user = $api->getUserById($userID);
        $beneficiaryPicture = $trip->getBeneficiary()->getProfilePicture();
        $volunteerPicture = null;

        if ($trip->getVolunteer() != null) {
            $volunteerPicture = $trip->getVolunteer()->getProfilePicture();
            $api->getToken();
            $volunteerId = $trip->getVolunteer()->getMobicoopId();
            $volunteer = $api->getUserById($volunteerId);
        }

        return $this->render('trip/show.html.twig', [
            'trip' => $trip,
            'volunteer' => $volunteer,
            'volunteerPicture' => $volunteerPicture,
            'beneficiary' => $user,
            'beneficiaryPicture' => $beneficiaryPicture,
        ]);
    }

    /**
     * Route for beneficiary trip (only ROLE_USER_BENEFICIARY)
     * @Route("/beneficiary/trip", name="trip_beneficiary", methods={"GET"})
     * @return Response
     */
    public function index(): Response
    {
        return $this->render('trip/index.html.twig', [
            'trips' => $this->getUser()->getTrips(),
            'user' => $this->getUser()
        ]);
    }

    /**
     * Create new trip (only ROLE_USER_BENEFICIARY)
     * @Route("/beneficiary/trip/new", name="trip_new", methods={"GET","POST"})
     * @param Request $request
     * @param SessionInterface $session
     * @param TranslatorInterface $translator
     * @param EmailService $emailService
     * @return Response
     * @throws \Exception
     */
    public function new(
        Request $request,
        SessionInterface $session,
        TranslatorInterface $translator,
        EmailService $emailService
    ): Response {
        $beneficiary = $this->getDoctrine()
            ->getRepository(User::class)
            ->findOneBy(['mobicoopId' => $session->get('user')->getMobicoopId()]);

        $trip = new Trip();

        $form = $this->createForm(TripType::class, $trip);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $trip->setBeneficiary($beneficiary);
            $date = $request->request->get('datePicker');
            $time = $request->request->get('timePicker');
            if (substr($time, -2) === 'AM') {
                $trip->setIsMorning(true);
                $trip->setIsAfternoon(false);
            } else {
                $trip->setIsMorning(false);
                $trip->setIsAfternoon(true);
            }
            $dateTime = $date . $time;
            $trip->setDate(new DateTime($dateTime));
            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($trip);
            $entityManager->flush();

            $message = $translator->trans('New trip added');
            $this->addFlash('success', $message);

            $emailService->newTrip($trip);

            return $this->redirectToRoute('trip_beneficiary');
        }

        return $this->render('trip/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * Route to edit a trip (only ROLE_USER_BENEFICIARY)
     * @Route("/beneficiary/trip/{id}/edit", name="trip_edit", methods={"GET","POST"})
     * @param Request $request
     * @param Trip $trip
     * @return Response
     * @throws \Exception
     */
    public function edit(Request $request, Trip $trip): Response
    {
        $form = $this->createForm(TripType::class, $trip);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $date = $request->request->get('datePicker');
            $time = $request->request->get('timePicker');
            $dateTime = $date . $time;
            $trip->setDate(new DateTime($dateTime));
            $trip->setUpdatedAt(new DateTime('now'));
            $this->getDoctrine()->getManager()->flush();

            return $this->redirectToRoute('trip_beneficiary');
        }

        return $this->render('trip/edit.html.twig', [
            'trip' => $trip,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Route to delete a trip (only ROLE_USER_BENEFICIARY)
     * @Route("/beneficiary/{id}", name="trip_delete", methods={"DELETE"})
     * @param Request $request
     * @param Trip $trip
     * @param EmailService $emailService
     * @return Response
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     * @throws \Symfony\Component\Mailer\Exception\TransportExceptionInterface
     */
    public function delete(
        Request $request,
        Trip $trip,
        EmailService $emailService
    ): Response {
        if ($this->isCsrfTokenValid('delete' . $trip->getId(), $request->request->get('_token'))) {
            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->remove($trip);
            $entityManager->flush();
            $emailService->canceledTrip($trip);
        }

        return $this->redirectToRoute('trip_beneficiary');
    }

    /**
     * Route to see volunteer trips (only ROLE_USER_VOLUNTEER)
     * @Route("/volunteer/trip", name="trip_volunteer")
     * @return Response
     */
    public function myTrip(): Response
    {
        return $this->render('trip/index.html.twig', [
            'trips' => $this->getUser()->getTripsVolunteer(),
            'user' => $this->getUser()
        ]);
    }

    /**
     * Route to see matching trips with availability (only ROLE_USER_VOLUNTEER)
     * @Route("/volunteer/matching", name="trip_matching")
     * @param TripService $tripService
     * @return Response
     */
    public function allTrip(TripService $tripService): Response
    {
        $user = $this->getUser()->getScheduleVolunteers();
        $trips = null;
        foreach ($user as $key => $scheduleVolunteer) {
            $trips[$key] = $this->getDoctrine()->getRepository(Trip::class)
                ->matchingAvailability(
                    $scheduleVolunteer->getIsMorning(),
                    $scheduleVolunteer->getIsAfternoon(),
                    $scheduleVolunteer->getDate()->format('Y-m-d')
                );
        }

        $tripsMatching = $tripService->getMatchingTrips($trips);

        return $this->render('trip/index.html.twig', [
            'trips' => $tripsMatching,
            'user' => $this->getUser()
        ]);
    }

    /**
     * Route to accept a trip created by a beneficiary (only ROLE_USER_VOLUNTEER)
     * @Route("/volunteer/accept/{tripId}", name="trip_accept", methods={"GET","POST"})
     * @param int $tripId
     * @param EntityManagerInterface $entityManager
     * @param EmailService $emailService
     * @return RedirectResponse
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     * @throws \Symfony\Component\Mailer\Exception\TransportExceptionInterface
     */
    public function addVolunteerToTrip(
        int $tripId,
        EntityManagerInterface $entityManager,
        EmailService $emailService
    ) {
        $trip = $this->getDoctrine()
            ->getRepository(Trip::class)
            ->findOneById($tripId);
        $trip->setVolunteer($this->getUser());

        $entityManager->persist($trip);
        $entityManager->flush();

        $emailService->acceptedTrip($trip);

        return $this->redirectToRoute('trip_volunteer');
    }

    /**
     * Route to remove acceptance to a trip created by a beneficiary (only ROLE_USER_VOLUNTEER)
     * @Route("/volunteer/disengage/{tripId}", name="trip_revert_accept", methods={"GET","POST"})
     * @param int $tripId
     * @param EntityManagerInterface $entityManager
     * @param EmailService $emailService
     * @return RedirectResponse
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     * @throws \Symfony\Component\Mailer\Exception\TransportExceptionInterface
     */
    public function removeVolunteerToTrip(
        int $tripId,
        EntityManagerInterface $entityManager,
        EmailService $emailService
    ): Response {
        $trip = $this->getDoctrine()
            ->getRepository(Trip::class)
            ->findOneById($tripId);
        $volunteer = $trip->getVolunteer();
        $trip->setVolunteer(null);
        $entityManager->persist($trip);
        $entityManager->flush();

        $emailService->canceledTrip($trip, $volunteer);

        return $this->redirectToRoute('trip_volunteer');
    }
}
