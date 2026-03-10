<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Route('/api/users', name: 'api_users_')]
final class UserController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('', name: 'get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        $users = $this->userRepository->findBy([], ['id' => 'DESC']);
        $data = array_map($this->normalizeUser(...), $users);

        return new JsonResponse($data, JsonResponse::HTTP_OK);
    }

    #[Route('', name: 'post', methods: ['POST'])]
    public function post(Request $request): JsonResponse
    {
        $payload = $this->parsePayload($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $requiredFields = ['email', 'password', 'prenom', 'nom'];
        $missingFields = array_values(array_filter($requiredFields, static fn (string $field): bool => !array_key_exists($field, $payload)));

        if ($missingFields !== []) {
            return new JsonResponse(
                ['error' => 'Missing required fields.', 'fields' => $missingFields],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if (!is_string($payload['email']) || !is_string($payload['password']) || !is_string($payload['prenom']) || !is_string($payload['nom'])) {
            return new JsonResponse(['error' => 'email, password, prenom and nom must be strings.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $email = mb_strtolower(trim($payload['email']));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'email must be a valid email address.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($this->userRepository->findOneBy(['email' => $email]) !== null) {
            return new JsonResponse(['error' => 'email is already used.'], JsonResponse::HTTP_CONFLICT);
        }

        if (trim($payload['password']) === '') {
            return new JsonResponse(['error' => 'password cannot be empty.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $roles = $this->sanitizeRoles($payload['roles'] ?? []);
        if ($roles === null) {
            return new JsonResponse(['error' => 'roles must be an array of strings.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = (new User())
            ->setEmail($email)
            ->setPrenom($payload['prenom'])
            ->setNom($payload['nom'])
            ->setRoles($roles)
            ->setCreatedAt(new \DateTimeImmutable());

        $hashedPassword = $this->passwordHasher->hashPassword($user, $payload['password']);
        $user->setPassword($hashedPassword);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return new JsonResponse($this->normalizeUser($user), JsonResponse::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'patch', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function patch(int $id, Request $request): JsonResponse
    {
        $user = $this->userRepository->find($id);
        if ($user === null) {
            return new JsonResponse(['error' => 'User not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $payload = $this->parsePayload($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        if (array_key_exists('email', $payload)) {
            if (!is_string($payload['email'])) {
                return new JsonResponse(['error' => 'email must be a string.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }

            $email = mb_strtolower(trim($payload['email']));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return new JsonResponse(['error' => 'email must be a valid email address.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }

            $existingUser = $this->userRepository->findOneBy(['email' => $email]);
            if ($existingUser !== null && $existingUser->getId() !== $user->getId()) {
                return new JsonResponse(['error' => 'email is already used.'], JsonResponse::HTTP_CONFLICT);
            }

            $user->setEmail($email);
        }

        if (array_key_exists('password', $payload)) {
            if (!is_string($payload['password']) || trim($payload['password']) === '') {
                return new JsonResponse(['error' => 'password must be a non-empty string.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
            $user->setPassword($this->passwordHasher->hashPassword($user, $payload['password']));
        }

        if (array_key_exists('prenom', $payload)) {
            if (!is_string($payload['prenom']) || trim($payload['prenom']) === '') {
                return new JsonResponse(['error' => 'prenom must be a non-empty string.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
            $user->setPrenom($payload['prenom']);
        }

        if (array_key_exists('nom', $payload)) {
            if (!is_string($payload['nom']) || trim($payload['nom']) === '') {
                return new JsonResponse(['error' => 'nom must be a non-empty string.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
            $user->setNom($payload['nom']);
        }

        if (array_key_exists('roles', $payload)) {
            $roles = $this->sanitizeRoles($payload['roles']);
            if ($roles === null) {
                return new JsonResponse(['error' => 'roles must be an array of strings.'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
            $user->setRoles($roles);
        }

        $this->entityManager->flush();

        return new JsonResponse($this->normalizeUser($user), JsonResponse::HTTP_OK);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $user = $this->userRepository->find($id);
        if ($user === null) {
            return new JsonResponse(['error' => 'User not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($user);
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

    /**
     * @return list<string>|null
     */
    private function sanitizeRoles(mixed $roles): ?array
    {
        if (!is_array($roles)) {
            return null;
        }

        $normalized = [];
        foreach ($roles as $role) {
            if (!is_string($role) || trim($role) === '') {
                return null;
            }

            $role = strtoupper(trim($role));
            if (!str_starts_with($role, 'ROLE_')) {
                $role = 'ROLE_' . $role;
            }
            $normalized[] = $role;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return array{
     *     id: int|null,
     *     email: string|null,
     *     roles: list<string>,
     *     prenom: string|null,
     *     nom: string|null,
     *     createdAt: string|null
     * }
     */
    private function normalizeUser(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'prenom' => $user->getPrenom(),
            'nom' => $user->getNom(),
            'createdAt' => $user->getCreatedAt()?->format(DATE_ATOM),
        ];
    }
}
