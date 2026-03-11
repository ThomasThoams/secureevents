<?php

namespace App\Controller;

use App\Entity\Event;
use App\Repository\EventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/events', name: 'api_events_')]
final class EventController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventRepository $eventRepository,
    ) {
    }

    #[Route('', name: 'get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        $events = $this->eventRepository->findPublishedUpcoming();
        $data = array_map($this->normalizeEvent(...), $events);

        return new JsonResponse($data, JsonResponse::HTTP_OK);
    }

    #[Route('', name: 'post', methods: ['POST'])]
    public function post(Request $request): JsonResponse
    {
        $payload = $this->parsePayload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $requiredFields = ['titre', 'description', 'dateDebut', 'lieu', 'capaciteMax', 'isPublished'];
        $missingFields = array_values(array_filter($requiredFields, static fn (string $field): bool => !array_key_exists($field, $payload)));

        if ($missingFields !== []) {
            return new JsonResponse(
                ['error' => 'Missing required fields.', 'fields' => $missingFields],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $dateDebut = $this->buildDate($payload['dateDebut']);
        if ($dateDebut === null) {
            return new JsonResponse(['error' => 'dateDebut must be a valid date string.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $capaciteMax = filter_var($payload['capaciteMax'], FILTER_VALIDATE_INT);
        if ($capaciteMax === false || $capaciteMax <= 0) {
            return new JsonResponse(['error' => 'capaciteMax must be a positive integer.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $isPublished = filter_var($payload['isPublished'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($isPublished === null) {
            return new JsonResponse(['error' => 'isPublished must be a boolean.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!is_string($payload['titre']) || !is_string($payload['description']) || !is_string($payload['lieu'])) {
            return new JsonResponse(['error' => 'titre, description and lieu must be strings.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $event = (new Event())
            ->setTitre(trim($payload['titre']))
            ->setDescription(trim($payload['description']))
            ->setDateDebut($dateDebut)
            ->setLieu(trim($payload['lieu']))
            ->setCapaciteMax($capaciteMax)
            ->setIsPublished($isPublished);

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return new JsonResponse($this->normalizeEvent($event), JsonResponse::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'patch', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function patch(int $id, Request $request): JsonResponse
    {
        $event = $this->eventRepository->find($id);
        if ($event === null) {
            return new JsonResponse(['error' => 'Event not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $payload = $this->parsePayload($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        if (array_key_exists('titre', $payload)) {
            if (!is_string($payload['titre']) || trim($payload['titre']) === '') {
                return new JsonResponse(['error' => 'titre must be a non-empty string.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
            $event->setTitre(trim($payload['titre']));
        }

        if (array_key_exists('description', $payload)) {
            if (!is_string($payload['description']) || trim($payload['description']) === '') {
                return new JsonResponse(['error' => 'description must be a non-empty string.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
            $event->setDescription(trim($payload['description']));
        }

        if (array_key_exists('dateDebut', $payload)) {
            $dateDebut = $this->buildDate($payload['dateDebut']);
            if ($dateDebut === null) {
                return new JsonResponse(['error' => 'dateDebut must be a valid date string.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
            $event->setDateDebut($dateDebut);
        }

        if (array_key_exists('lieu', $payload)) {
            if (!is_string($payload['lieu']) || trim($payload['lieu']) === '') {
                return new JsonResponse(['error' => 'lieu must be a non-empty string.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
            $event->setLieu(trim($payload['lieu']));
        }

        if (array_key_exists('capaciteMax', $payload)) {
            $capaciteMax = filter_var($payload['capaciteMax'], FILTER_VALIDATE_INT);
            if ($capaciteMax === false || $capaciteMax <= 0) {
                return new JsonResponse(['error' => 'capaciteMax must be a positive integer.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
            if ($capaciteMax < $event->getReservations()->count()) {
                return new JsonResponse(
                    ['error' => 'capaciteMax cannot be below current reservation count.'],
                    JsonResponse::HTTP_UNPROCESSABLE_ENTITY
                );
            }
            $event->setCapaciteMax($capaciteMax);
        }

        if (array_key_exists('isPublished', $payload)) {
            $isPublished = filter_var($payload['isPublished'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isPublished === null) {
                return new JsonResponse(['error' => 'isPublished must be a boolean.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
            $event->setIsPublished($isPublished);
        }

        $this->entityManager->flush();

        return new JsonResponse($this->normalizeEvent($event), JsonResponse::HTTP_OK);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $event = $this->eventRepository->find($id);
        if ($event === null) {
            return new JsonResponse(['error' => 'Event not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($event);
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

    private function buildDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return array{
     *     id: int|null,
     *     titre: string|null,
     *     description: string|null,
     *     dateDebut: string|null,
     *     lieu: string|null,
     *     capaciteMax: int|null,
     *     isPublished: bool|null,
     *     reservationsCount: int
     * }
     */
    private function normalizeEvent(Event $event): array
    {
        return [
            'id' => $event->getId(),
            'titre' => $event->getTitre(),
            'description' => $event->getDescription(),
            'dateDebut' => $event->getDateDebut()?->format(DATE_ATOM),
            'lieu' => $event->getLieu(),
            'capaciteMax' => $event->getCapaciteMax(),
            'isPublished' => $event->isPublished(),
            'reservationsCount' => $event->getReservations()->count(),
        ];
    }
}
