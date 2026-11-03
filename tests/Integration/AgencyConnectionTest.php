<?php

namespace Tests\Integration;

use App\Repository\AgencyRepository;
use App\Repository\ConnectionRepository;
use App\Repository\ProjectRepository;
use DomainException;

/**
 * Conexión entre agencias por código. Datos demo: agencia 1 (código AGDM-2345) y agencia 2 (NRTE-6789),
 * sin conectar. Cada test deja la tabla de conexiones vacía.
 */
final class AgencyConnectionTest extends DatabaseTestCase
{
    private function connections(): ConnectionRepository
    {
        return new ConnectionRepository(static::$db, new AgencyRepository(static::$db));
    }

    protected function tearDown(): void
    {
        static::$db?->exec("DELETE FROM agency_connections");
    }

    public function testRequestAndAcceptConnection(): void
    {
        $connections = $this->connections();

        $this->assertSame('pending', $connections->request(1, 'nrte 6789', self::ADMIN['id']), 'El código admite minúsculas, espacios y sin guion');
        $this->assertFalse($connections->areConnected(1, 2));

        $incoming = $connections->forAgency(2)['incoming'];
        $this->assertCount(1, $incoming);
        $this->assertSame('Agencia Demo', $incoming[0]['agency_name']);
        $this->assertSame('Estudio Norte', $connections->forAgency(1)['outgoing'][0]['agency_name']);

        $this->assertFalse($connections->accept($incoming[0]['id'], 1), 'Quien la envía no puede aceptarla');
        $this->assertTrue($connections->accept($incoming[0]['id'], 2));
        $this->assertTrue($connections->areConnected(2, 1));
        $this->assertSame('Agencia Demo', $connections->forAgency(2)['connected'][0]['agency_name']);
    }

    public function testConnectingSharesNothing(): void
    {
        $connections = $this->connections();
        $connections->request(1, 'NRTE-6789', self::ADMIN['id']);
        $connections->accept($connections->forAgency(2)['incoming'][0]['id'], 2);

        $projects = new ProjectRepository(static::$db);
        $this->assertFalse($projects->canAccess(3, self::ADMIN['id'], 'admin'));
        $this->assertFalse($projects->canAccess(1, self::ADMIN_B['id'], 'admin'));
    }

    public function testInvalidRequestsAreRejected(): void
    {
        $connections = $this->connections();

        foreach (['ZZZZ-9999' => 'No existe', 'corto' => 'No existe', 'AGDM-2345' => 'propia agencia'] as $code => $message) {
            try {
                $connections->request(1, $code, null);
                $this->fail("Debía rechazar {$code}");
            } catch (DomainException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        $connections->request(1, 'NRTE-6789', null);
        $this->expectExceptionMessage('Ya enviaste una solicitud');
        $connections->request(1, 'NRTE-6789', null);
    }

    public function testCrossRequestAcceptsAutomatically(): void
    {
        $connections = $this->connections();
        $connections->request(1, 'NRTE-6789', null);

        $this->assertSame('accepted', $connections->request(2, 'AGDM-2345', null));
        $this->assertTrue($connections->areConnected(1, 2));
        $this->assertSame(1, (int)static::$db->query("SELECT COUNT(*) FROM agency_connections")->fetchColumn());

        $this->expectExceptionMessage('Ya estás conectado');
        $connections->request(1, 'NRTE-6789', null);
    }

    public function testOnlyInvolvedAgenciesCanRemove(): void
    {
        $connections = $this->connections();
        $outsider = (new AgencyRepository(static::$db))->create('Agencia Ajena');
        $connections->request(1, 'NRTE-6789', null);
        $id = $connections->forAgency(1)['outgoing'][0]['id'];

        $this->assertFalse($connections->remove($id, $outsider));
        $this->assertFalse($connections->accept($id, $outsider));
        $this->assertSame([], $connections->forAgency($outsider)['incoming']);
        $this->assertTrue($connections->remove($id, 2), 'Rechazar');
        $this->assertSame(['connected' => [], 'incoming' => [], 'outgoing' => []], $connections->forAgency(1));
    }

    public function testCodeIsGeneratedOnceAndCanBeRegenerated(): void
    {
        $agencies = new AgencyRepository(static::$db);
        $id = $agencies->create('Agencia Sin Código');

        $code = $agencies->connectCode($id);
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $code);
        $this->assertSame($code, $agencies->connectCode($id), 'El código se conserva');

        $new = $agencies->regenerateConnectCode($id);
        $this->assertNotSame($code, $new);
        $this->assertNull($agencies->findByConnectCode($code), 'El código anterior deja de servir');
        $this->assertSame($id, $agencies->findByConnectCode($new)['id']);
    }
}
