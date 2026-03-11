<?php

namespace App\DataFixtures;

use App\Entity\Event;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(private readonly UserPasswordHasherInterface $passwordHasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        // Admin account
        $admin = new User();
        $admin->setEmail('admin@securevent.fr')
            ->setPrenom('Admin')
            ->setNom('SecurEvent')
            ->setRoles(['ROLE_ADMIN'])
            ->setCreatedAt(new \DateTimeImmutable())
            ->setPassword($this->passwordHasher->hashPassword($admin, 'Admin1234!'));
        $manager->persist($admin);

        // Test user
        $user = new User();
        $user->setEmail('user@securevent.fr')
            ->setPrenom('Alice')
            ->setNom('Dupont')
            ->setRoles(['ROLE_USER'])
            ->setCreatedAt(new \DateTimeImmutable())
            ->setPassword($this->passwordHasher->hashPassword($user, 'User1234!'));
        $manager->persist($user);

        // Sample events
        $eventsData = [
            [
                'titre' => 'CTF - Web Exploitation #1',
                'description' => "Compétition Capture The Flag axée sur l'exploitation de vulnérabilités web : XSS, SQLi, CSRF, et plus. Idéal pour les débutants et intermédiaires.",
                'dateDebut' => new \DateTimeImmutable('+10 days'),
                'lieu' => 'Paris — ESDI Campus',
                'capaciteMax' => 30,
                'isPublished' => true,
            ],
            [
                'titre' => "Workshop — Sécurisation d'une API REST",
                'description' => 'Atelier pratique pour apprendre à sécuriser une API REST avec authentification JWT, rate limiting, validation et audit de logs.',
                'dateDebut' => new \DateTimeImmutable('+20 days'),
                'lieu' => 'Lyon — Espace Numérique',
                'capaciteMax' => 20,
                'isPublished' => true,
            ],
            [
                'titre' => 'Conférence — OWASP Top 10 en 2026',
                'description' => "Retour sur les 10 vulnérabilités les plus critiques selon l'OWASP en 2026, avec des démonstrations live d'exploitation et de remédiation.",
                'dateDebut' => new \DateTimeImmutable('+35 days'),
                'lieu' => 'Bordeaux — Médiathèque Jacques Tati',
                'capaciteMax' => 100,
                'isPublished' => true,
            ],
            [
                'titre' => 'CTF Avancé — Binary Exploitation',
                'description' => 'Événement réservé aux profils avancés. Reverse engineering, buffer overflow, ROP chains. Niveau : expert.',
                'dateDebut' => new \DateTimeImmutable('+50 days'),
                'lieu' => 'Nantes — Fablab Artilect',
                'capaciteMax' => 15,
                'isPublished' => false,
            ],
        ];

        foreach ($eventsData as $data) {
            $event = new Event();
            $event->setTitre($data['titre'])
                ->setDescription($data['description'])
                ->setDateDebut($data['dateDebut'])
                ->setLieu($data['lieu'])
                ->setCapaciteMax($data['capaciteMax'])
                ->setIsPublished($data['isPublished']);
            $manager->persist($event);
        }

        $manager->flush();
    }
}
