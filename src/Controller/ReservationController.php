<?php

namespace App\Controller;

use App\Entity\Event;
use App\Entity\Reservation;
use App\Entity\User;
use App\Repository\EventRepository;
use App\Repository\ReservationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/reservations', name: 'api_reservations_')]
final class ReservationController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ReservationRepository $reservationRepository,
        private readonly UserRepository $userRepository,
        private readonly EventRepository $eventRepository,
    ) {
    }

    #[Route('', name: 'get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        $reservations = $this->reservationRepository->findBy([], ['id' => 'DESC']);
        $data = array_map($this->normalizeReservation(...), $reservations);

        return new JsonResponse($data, JsonResponse::HTTP_OK);
    }

    #[Route('', name: 'post', methods: ['POST'])]
    public function post(Request $request): JsonResponse
    {
        $payload = $this->parsePayload($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $requiredFields = ['userId', 'eventId'];
        $missingFields = array_values(array_filter($requiredFields, static fn (string $field): bool => !array_key_exists($field, $payload)));

        if ($missingFields !== []) {
            return new JsonResponse(
                ['error' => 'Missing required fields.', 'fields' => $missingFields],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $userId = filter_var($payload['userId'], FILTER_VALIDATE_INT);
        $eventId = filter_var($payload['eventId'], FILTER_VALIDATE_INT);
        if ($userId === false || $eventId === false) {
            return new JsonResponse(['error' => 'userId and eventId must be integers.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $this->userRepository->find($userId);
        $event = $this->eventRepository->find($eventId);
        if ($user === null || $event === null) {
            return new JsonResponse(['error' => 'User or event not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $existingReservation = $this->reservationRepository->findOneBy(['user' => $user, 'event' => $event]);
        if ($existingReservation !== null) {
            return new JsonResponse(['error' => 'User is already registered to this event.'], JsonResponse::HTTP_CONFLICT);
        }

        if ($this->isEventFull($event, null)) {
            return new JsonResponse(['error' => 'Event is full.'], JsonResponse::HTTP_CONFLICT);
        }

        $reservation = (new Reservation())
            ->setUser($user)
            ->setEvent($event)
            ->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($reservation);
        $this->entityManager->flush();

        return new JsonResponse($this->normalizeReservation($reservation), JsonResponse::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'patch', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function patch(int $id, Request $request): JsonResponse
    {
        $reservation = $this->reservationRepository->find($id);
        if ($reservation === null) {
            return new JsonResponse(['error' => 'Reservation not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $payload = $this->parsePayload($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $targetUser = $reservation->getUser();
        $targetEvent = $reservation->getEvent();

        if (array_key_exists('userId', $payload)) {
            $userId = filter_var($payload['userId'], FILTER_VALIDATE_INT);
            if ($userId === false) {
                return new JsonResponse(['error' => 'userId must be an integer.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
            $targetUser = $this->userRepository->find($userId);
            if ($targetUser === null) {
                return new JsonResponse(['error' => 'User not found.'], JsonResponse::HTTP_NOT_FOUND);
            }
        }

        if (array_key_exists('eventId', $payload)) {
            $eventId = filter_var($payload['eventId'], FILTER_VALIDATE_INT);
            if ($eventId === false) {
                return new JsonResponse(['error' => 'eventId must be an integer.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
            $targetEvent = $this->eventRepository->find($eventId);
            if ($targetEvent === null) {
                return new JsonResponse(['error' => 'Event not found.'], JsonResponse::HTTP_NOT_FOUND);
            }
        }

        if ($targetUser === null || $targetEvent === null) {
            return new JsonResponse(['error' => 'Invalid reservation relation.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $duplicate = $this->reservationRepository->findOneBy(['user' => $targetUser, 'event' => $targetEvent]);
        if ($duplicate !== null && $duplicate->getId() !== $reservation->getId()) {
            return new JsonResponse(['error' => 'User is already registered to this event.'], JsonResponse::HTTP_CONFLICT);
        }

        if ($this->isEventFull($targetEvent, $reservation)) {
            return new JsonResponse(['error' => 'Event is full.'], JsonResponse::HTTP_CONFLICT);
        }

        $reservation->setUser($targetUser);
        $reservation->setEvent($targetEvent);

        $this->entityManager->flush();

        return new JsonResponse($this->normalizeReservation($reservation), JsonResponse::HTTP_OK);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $reservation = $this->reservationRepository->find($id);
        if ($reservation === null) {
            return new JsonResponse(['error' => 'Reservation not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($reservation);
        $this->entityManager->flush();

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }

    private function parsePayload(Request $request): array|JsonResponse
    {
        if (trim($request->getContent()) === '') {
            return [];
        }

        try {
            return $request->toArray();
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'Invalid JSON payload.'], JsonResponse::HTTP_BAD_REQUEST);
        }
    }

    private function isEventFull(Event $event, ?Reservation $ignoredReservation): bool
    {
        $capacity = $event->getCapaciteMax();
        if ($capacity === null) {
            return false;
        }

        $count = 0;
        foreach ($event->getReservations() as $reservation) {
            if ($ignoredReservation !== null && $reservation->getId() === $ignoredReservation->getId()) {
                continue;
            }
            ++$count;
        }

        return $count >= $capacity;
    }

    /**
     * @return array{
     *     id: int|null,
     *     user: array{id: int|null, email: string|null},
     *     event: array{id: int|null, titre: string|null},
     *     createdAt: string|null
     * }
     */
    private function normalizeReservation(Reservation $reservation): array
    {
        return [
            'id' => $reservation->getId(),
            'user' => [
                'id' => $reservation->getUser()?->getId(),
                'email' => $reservation->getUser()?->getEmail(),
            ],
            'event' => [
                'id' => $reservation->getEvent()?->getId(),
                'titre' => $reservation->getEvent()?->getTitre(),
            ],
            'createdAt' => $reservation->getCreatedAt()?->format(DATE_ATOM),
        ];
    }
}
