<?php

namespace App\Controller;

use App\Entity\Reservation;
use App\Entity\User;
use App\Repository\EventRepository;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/profile', name: 'app_profile_')]
#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    #[Route('/my-events', name: 'my_events')]
    public function myEvents(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('profile/dashboard.html.twig', [
            'reservations' => $user->getReservations(),
        ]);
    }

    #[Route('/event/{id}/register', name: 'event_register', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function register(
        int $id,
        Request $request,
        EventRepository $eventRepository,
        ReservationRepository $reservationRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$this->isCsrfTokenValid('event_register_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('app_event_show', ['id' => $id]);
        }

        $event = $eventRepository->find($id);
        if ($event === null) {
            throw $this->createNotFoundException('Événement introuvable.');
        }

        /** @var User $user */
        $user = $this->getUser();

        $existing = $reservationRepository->findOneBy(['user' => $user, 'event' => $event]);
        if ($existing !== null) {
            $this->addFlash('warning', 'Vous êtes déjà inscrit à cet événement.');

            return $this->redirectToRoute('app_event_show', ['id' => $id]);
        }

        if ($event->getReservations()->count() >= $event->getCapaciteMax()) {
            $this->addFlash('error', "Cet événement est complet.");

            return $this->redirectToRoute('app_event_show', ['id' => $id]);
        }

        $reservation = (new Reservation())
            ->setUser($user)
            ->setEvent($event)
            ->setCreatedAt(new \DateTimeImmutable());

        $entityManager->persist($reservation);
        $entityManager->flush();

        $this->addFlash('success', 'Inscription confirmée !');

        return $this->redirectToRoute('app_event_show', ['id' => $id]);
    }

    #[Route('/event/{id}/unregister', name: 'event_unregister', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function unregister(
        int $id,
        Request $request,
        EventRepository $eventRepository,
        ReservationRepository $reservationRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$this->isCsrfTokenValid('event_unregister_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('app_event_show', ['id' => $id]);
        }

        $event = $eventRepository->find($id);
        if ($event === null) {
            throw $this->createNotFoundException('Événement introuvable.');
        }

        /** @var User $user */
        $user = $this->getUser();

        $reservation = $reservationRepository->findOneBy(['user' => $user, 'event' => $event]);
        if ($reservation === null) {
            $this->addFlash('warning', "Vous n'êtes pas inscrit à cet événement.");

            return $this->redirectToRoute('app_event_show', ['id' => $id]);
        }

        $entityManager->remove($reservation);
        $entityManager->flush();

        $this->addFlash('success', 'Désinscription effectuée.');

        return $this->redirectToRoute('app_event_show', ['id' => $id]);
    }
}
