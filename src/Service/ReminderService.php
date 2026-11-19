<?php

namespace App\Service;

use App\Repository\ActivityRepository;
use PDO;

/**
 * Recordatorios de vencimiento. Se ejecuta por cron/CLI y evita duplicados
 * con las columnas reminder_sent_at de tareas y facturas.
 */
final class ReminderService
{
    public function __construct(
        private readonly PDO $db,
        private readonly NotificationService $notifications,
        private readonly ActivityRepository $activity,
    ) {
    }

    /** @return array{tasks: int, invoices: int} */
    public function run(): array
    {
        return [
            'tasks' => $this->taskReminders(),
            'invoices' => $this->invoiceReminders(),
        ];
    }

    private function taskReminders(): int
    {
        $stmt = $this->db->query("
            SELECT t.id, t.title, t.due_date, t.project_id, p.name AS project_name,
                   p.user_id AS owner_id, p.agency_id AS owner_agency_id,
                   u.id AS assignee_id, u.agency_id AS assignee_agency_id
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            LEFT JOIN users u ON u.id = t.assigned_user_id
            WHERE NOT (
                t.status = 'completada'
                OR EXISTS (
                    SELECT 1 FROM agency_task_statuses s
                    WHERE s.agency_id = p.agency_id AND s.status_key = t.status AND s.kind = 'done'
                )
              )
              AND t.due_date IS NOT NULL
              AND t.due_date <= CURDATE()
              AND t.reminder_sent_at IS NULL
              AND p.archived_at IS NULL
        ");

        $count = 0;
        $mark = $this->db->prepare("UPDATE tasks SET reminder_sent_at = NOW() WHERE id = ?");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $task) {
            $userId = $task['assignee_id'] !== null ? (int)$task['assignee_id'] : (int)$task['owner_id'];
            $agencyId = $task['assignee_id'] !== null ? (int)$task['assignee_agency_id'] : (int)$task['owner_agency_id'];
            $overdue = $task['due_date'] < date('Y-m-d');
            $title = $overdue ? 'Tarea vencida' : 'Tarea vence hoy';

            $this->notifications->notifyUser(
                $userId,
                $agencyId,
                'task.due',
                $title,
                '"' . $task['title'] . '" en ' . $task['project_name'] . ' (' . $task['due_date'] . ').',
                '/project/' . (int)$task['project_id'],
                true
            );
            $this->activity->log($agencyId, null, (int)$task['project_id'], 'task.reminder', 'Recordatorio de vencimiento: "' . $task['title'] . '"');
            $mark->execute([(int)$task['id']]);
            $count++;
        }
        return $count;
    }

    private function invoiceReminders(): int
    {
        $stmt = $this->db->query("
            SELECT i.id, i.invoice_number, i.concept, i.amount, i.currency, i.due_date,
                   COALESCE(p.name, 'Sin proyecto') AS project_name, i.agency_id
            FROM invoices i
            LEFT JOIN projects p ON p.id = i.project_id
            WHERE i.status = 'pendiente'
              AND i.due_date IS NOT NULL
              AND i.due_date <= CURDATE()
              AND i.reminder_sent_at IS NULL
              AND (i.project_id IS NULL OR p.archived_at IS NULL)
        ");

        $count = 0;
        $mark = $this->db->prepare("UPDATE invoices SET reminder_sent_at = NOW() WHERE id = ?");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $invoice) {
            $agencyId = (int)$invoice['agency_id'];
            $overdue = $invoice['due_date'] < date('Y-m-d');
            $title = $overdue ? 'Factura vencida' : 'Factura vence hoy';
            $message = 'La factura ' . $invoice['invoice_number'] . ' de ' . $invoice['project_name']
                . ' (' . number_format((float)$invoice['amount'], 2) . ' ' . $invoice['currency'] . ') vence el ' . $invoice['due_date'] . '.';

            $this->notifications->notifyAgencyAdmins(
                $agencyId,
                'invoice.due',
                $title,
                $message,
                'invoices'
            );
            $this->activity->log($agencyId, null, null, 'invoice.reminder', 'Recordatorio de factura ' . $invoice['invoice_number']);
            $mark->execute([(int)$invoice['id']]);
            $count++;
        }
        return $count;
    }
}
