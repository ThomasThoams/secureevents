<?php

namespace App\Controller;

use App\Repository\EventRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class FrontEventController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(EventRepository $eventRepository): Response
    {
        $events = $eventRepository->findPublishedUpcoming();

        return $this->render('home/index.html.twig', [
            'events' => $events,
        ]);
    }

    #[Route('/event/{id}', name: 'app_event_show', requirements: ['id' => '\d+'])]
    public function show(int $id, EventRepository $eventRepository): Response
    {
        $event = $eventRepository->find($id);

        if ($event === null) {
            throw $this->createNotFoundException('Événement introuvable.');
        }

        $user = $this->getUser();
        $isRegistered = false;

        if ($user !== null) {
            foreach ($event->getReservations() as $reservation) {
                if ($reservation->getUser() === $user) {
                    $isRegistered = true;
                    break;
                }
            }
        }

        $placesRestantes = $event->getCapaciteMax() - $event->getReservations()->count();

        return $this->render('event/show.html.twig', [
            'event' => $event,
            'isRegistered' => $isRegistered,
            'placesRestantes' => $placesRestantes,
        ]);
    }
}
