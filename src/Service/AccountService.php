<?php

namespace App\Service;

use App\Repository\AgencyRepository;
use App\Repository\InvitationRepository;
use DomainException;
use PDO;
use Throwable;

/**
 * Altas de cuentas: registro de una agencia nueva (con su primer administrador)
 * y aceptación de invitaciones al equipo o como cliente de una agencia.
 * Los errores de validación se lanzan como DomainException con un mensaje para el usuario.
 */
final class AccountService
{
    public function __construct(
        private readonly PDO $db,
        private readonly AgencyRepository $agencies,
        private readonly InvitationRepository $invitations,
    ) {
    }

    /** @return array{id: int, agency_id: int, name: string, email: string, role: string} */
    public function registerAgency(string $agencyName, string $name, string $email, string $password): array
    {
        $agencyName = trim($agencyName);
        if ($agencyName === '' || mb_strlen($agencyName) > 150) {
            throw new DomainException('Indica el nombre de tu agencia (máximo 150 caracteres).');
        }
        [$name, $email] = $this->validatePerson($name, $email, $password);

        return $this->transaction(function () use ($agencyName, $name, $email, $password) {
            $agencyId = $this->agencies->create($agencyName);
            return $this->insertUser($agencyId, $name, $email, $password, 'admin');
        });
    }

    /** @return array{id: int, agency_id: int, name: string, email: string, role: string} */
    public function acceptInvitation(string $token, string $name, string $password): array
    {
        $invitation = $this->invitations->findValid($token);
        if (!$invitation) {
            throw new DomainException('La invitación no es válida o ya caducó. Pide a tu agencia un enlace nuevo.');
        }
        [$name, $email] = $this->validatePerson($name, $invitation['email'], $password);

        return $this->transaction(function () use ($invitation, $name, $email, $password) {
            $user = $this->insertUser((int)$invitation['agency_id'], $name, $email, $password, $invitation['role']);
            $this->invitations->markAccepted((int)$invitation['id']);
            return $user;
        });
    }

    /** @return array{0: string, 1: string} nombre y correo normalizados */
    private function validatePerson(string $name, string $email, string $password): array
    {
        $name = trim($name);
        $email = mb_strtolower(trim($email));
        if ($name === '' || mb_strlen($name) > 100) {
            throw new DomainException('Indica tu nombre completo (máximo 100 caracteres).');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('El correo electrónico no tiene un formato válido.');
        }
        if (strlen($password) < 8) {
            throw new DomainException('La contraseña debe tener al menos 8 caracteres.');
        }
        $stmt = $this->db->prepare("SELECT 1 FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn()) {
            throw new DomainException('Este correo electrónico ya está registrado.');
        }
        return [$name, $email];
    }

    private function insertUser(int $agencyId, string $name, string $email, string $password, string $role): array
    {
        $this->db->prepare("INSERT INTO users (agency_id, name, email, role, password) VALUES (?, ?, ?, ?, ?)")
            ->execute([$agencyId, $name, $email, $role, password_hash($password, PASSWORD_DEFAULT)]);
        return [
            'id' => (int)$this->db->lastInsertId(),
            'agency_id' => $agencyId,
            'name' => $name,
            'email' => $email,
            'role' => $role,
        ];
    }

    private function transaction(callable $work): array
    {
        $this->db->beginTransaction();
        try {
            $result = $work();
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
