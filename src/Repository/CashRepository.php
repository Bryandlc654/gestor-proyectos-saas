<?php

namespace App\Repository;

use App\Support\CashSummary;
use DomainException;
use PDO;
use PDOException;

/**
 * Libro de caja: cuentas, categorías y movimientos de ingreso/egreso.
 *
 * Todo se filtra por `agency_id`; las cuentas y categorías solo pueden usarse
 * dentro de la agencia que las creó. Es independiente de facturas y cuentas
 * por pagar para no duplicar importes.
 */
final class CashRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** Crea la cuenta y categorías iniciales la primera vez que se usa el módulo. */
    public function ensureDefaults(int $agencyId): void
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM cash_accounts WHERE agency_id = ?');
        $stmt->execute([$agencyId]);
        if ((int)$stmt->fetchColumn() === 0) {
            $this->db->prepare("
                INSERT IGNORE INTO cash_accounts (agency_id, name, type, currency, opening_balance, color, is_active)
                VALUES (?, 'Caja general', 'efectivo', 'USD', 0, '#0E66FF', 1)
            ")->execute([$agencyId]);
        }

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM cash_categories WHERE agency_id = ?');
        $stmt->execute([$agencyId]);
        if ((int)$stmt->fetchColumn() > 0) {
            return;
        }

        $defaults = [
            ['ingreso', 'Ventas', '#059669'],
            ['ingreso', 'Cobro de facturas', '#0D9488'],
            ['ingreso', 'Anticipos', '#0E66FF'],
            ['ingreso', 'Otros ingresos', '#64748B'],
            ['egreso', 'Proveedores', '#E11D48'],
            ['egreso', 'Nómina', '#DC2626'],
            ['egreso', 'Renta', '#EA580C'],
            ['egreso', 'Servicios', '#CA8A04'],
            ['egreso', 'Impuestos', '#7C3AED'],
            ['egreso', 'Otros egresos', '#64748B'],
        ];

        $insert = $this->db->prepare("
            INSERT IGNORE INTO cash_categories (agency_id, type, name, color, is_active)
            VALUES (?, ?, ?, ?, 1)
        ");
        foreach ($defaults as [$type, $name, $color]) {
            $insert->execute([$agencyId, $type, $name, $color]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function accounts(int $agencyId, bool $includeInactive = true): array
    {
        $sql = 'SELECT * FROM cash_accounts WHERE agency_id = ?';
        if (!$includeInactive) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY is_active DESC, name ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$agencyId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array<string, mixed>> */
    public function categories(int $agencyId, ?string $type = null, bool $includeInactive = true): array
    {
        $sql = 'SELECT * FROM cash_categories WHERE agency_id = ?';
        $params = [$agencyId];
        if ($type !== null && in_array($type, ['ingreso', 'egreso'], true)) {
            $sql .= ' AND type = ?';
            $params[] = $type;
        }
        if (!$includeInactive) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY type ASC, name ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function findAccount(int $accountId, int $agencyId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM cash_accounts WHERE id = ? AND agency_id = ?');
        $stmt->execute([$accountId, $agencyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findCategory(int $categoryId, int $agencyId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM cash_categories WHERE id = ? AND agency_id = ?');
        $stmt->execute([$categoryId, $agencyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findTransaction(int $transactionId, int $agencyId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT t.*, a.name AS account_name, a.color AS account_color,
                   c.name AS category_name, c.color AS category_color
            FROM cash_transactions t
            INNER JOIN cash_accounts a ON a.id = t.account_id
            INNER JOIN cash_categories c ON c.id = t.category_id
            WHERE t.id = ? AND t.agency_id = ?
        ");
        $stmt->execute([$transactionId, $agencyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Saldo actual de cada cuenta: apertura + todos los movimientos.
     *
     * @return array<int, array{opening:float,income:float,expense:float,balance:float,currency:string}>
     */
    public function accountBalances(int $agencyId, ?int $accountId = null): array
    {
        $sql = "
            SELECT a.id, a.currency, a.opening_balance,
                   COALESCE(SUM(CASE WHEN t.type = 'ingreso' THEN t.amount ELSE 0 END), 0) AS income,
                   COALESCE(SUM(CASE WHEN t.type = 'egreso' THEN t.amount ELSE 0 END), 0) AS expense
            FROM cash_accounts a
            LEFT JOIN cash_transactions t ON t.account_id = a.id
            WHERE a.agency_id = ?
        ";
        $params = [$agencyId];
        if ($accountId !== null && $accountId > 0) {
            $sql .= ' AND a.id = ?';
            $params[] = $accountId;
        }
        $sql .= ' GROUP BY a.id';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $balances = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $opening = (float)$row['opening_balance'];
            $income = (float)$row['income'];
            $expense = (float)$row['expense'];
            $balances[(int)$row['id']] = [
                'opening' => $opening,
                'income' => $income,
                'expense' => $expense,
                'balance' => CashSummary::balance($opening, $income, $expense),
                'currency' => strtoupper((string)$row['currency']),
            ];
        }

        return $balances;
    }

    /**
     * Resumen del periodo y saldo global por moneda.
     *
     * @return array{
     *   income:array<string,float>,
     *   expense:array<string,float>,
     *   net:array<string,float>,
     *   balance:array<string,float>,
     *   count:int,
     *   income_count:int,
     *   expense_count:int,
     *   categories:array<int, array<string, mixed>>
     * }
     */
    public function summary(
        int $agencyId,
        string $from,
        string $to,
        ?int $accountId = null,
        ?int $categoryId = null,
        ?int $projectId = null
    ): array {
        $where = ['t.agency_id = ?', 't.occurred_on BETWEEN ? AND ?'];
        $params = [$agencyId, $from, $to];
        if ($accountId !== null && $accountId > 0) {
            $where[] = 't.account_id = ?';
            $params[] = $accountId;
        }
        if ($categoryId !== null && $categoryId > 0) {
            $where[] = 't.category_id = ?';
            $params[] = $categoryId;
        }
        if ($projectId !== null && $projectId > 0) {
            $where[] = 't.project_id = ?';
            $params[] = $projectId;
        }
        $whereSql = implode(' AND ', $where);

        $summary = [
            'income' => [],
            'expense' => [],
            'net' => [],
            'balance' => [],
            'count' => 0,
            'income_count' => 0,
            'expense_count' => 0,
            'categories' => [],
        ];

        $stmt = $this->db->prepare("
            SELECT t.type, t.currency,
                   COALESCE(SUM(t.amount), 0) AS total,
                   COUNT(*) AS movements
            FROM cash_transactions t
            WHERE {$whereSql}
            GROUP BY t.type, t.currency
        ");
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $currency = strtoupper((string)$row['currency']);
            $total = (float)$row['total'];
            $count = (int)$row['movements'];
            $summary['count'] += $count;
            if ($row['type'] === 'ingreso') {
                $summary['income'][$currency] = ($summary['income'][$currency] ?? 0) + $total;
                $summary['income_count'] += $count;
            } else {
                $summary['expense'][$currency] = ($summary['expense'][$currency] ?? 0) + $total;
                $summary['expense_count'] += $count;
            }
        }

        $currencies = array_unique(array_merge(
            array_keys($summary['income']),
            array_keys($summary['expense'])
        ));
        foreach ($currencies as $currency) {
            $summary['net'][$currency] = CashSummary::net(
                $summary['income'][$currency] ?? 0,
                $summary['expense'][$currency] ?? 0
            );
        }

        // Saldo actual: apertura de cuentas + todos los movimientos hasta la fecha final.
        $balanceWhere = ['agency_id = ?'];
        $balanceParams = [$agencyId];
        if ($accountId !== null && $accountId > 0) {
            $balanceWhere[] = 'id = ?';
            $balanceParams[] = $accountId;
        }
        $stmt = $this->db->prepare("
            SELECT currency, COALESCE(SUM(opening_balance), 0) AS opening
            FROM cash_accounts
            WHERE " . implode(' AND ', $balanceWhere) . "
            GROUP BY currency
        ");
        $stmt->execute($balanceParams);

        $openingByCurrency = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $openingByCurrency[strtoupper((string)$row['currency'])] = (float)$row['opening'];
        }

        $movementWhere = ['agency_id = ?', 'occurred_on <= ?'];
        $movementParams = [$agencyId, $to];
        if ($accountId !== null && $accountId > 0) {
            $movementWhere[] = 'account_id = ?';
            $movementParams[] = $accountId;
        }
        $stmt = $this->db->prepare("
            SELECT currency, type, COALESCE(SUM(amount), 0) AS total
            FROM cash_transactions
            WHERE " . implode(' AND ', $movementWhere) . "
            GROUP BY currency, type
        ");
        $stmt->execute($movementParams);

        $allIncome = [];
        $allExpense = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $currency = strtoupper((string)$row['currency']);
            if ($row['type'] === 'ingreso') {
                $allIncome[$currency] = ($allIncome[$currency] ?? 0) + (float)$row['total'];
            } else {
                $allExpense[$currency] = ($allExpense[$currency] ?? 0) + (float)$row['total'];
            }
        }

        $balanceCurrencies = array_unique(array_merge(
            array_keys($openingByCurrency),
            array_keys($allIncome),
            array_keys($allExpense)
        ));
        foreach ($balanceCurrencies as $currency) {
            $summary['balance'][$currency] = CashSummary::balance(
                $openingByCurrency[$currency] ?? 0,
                $allIncome[$currency] ?? 0,
                $allExpense[$currency] ?? 0
            );
        }

        // Desglose por categoría del periodo visible.
        $stmt = $this->db->prepare("
            SELECT c.id, c.name, c.color, t.type, t.currency,
                   COALESCE(SUM(t.amount), 0) AS total,
                   COUNT(*) AS movements
            FROM cash_transactions t
            INNER JOIN cash_categories c ON c.id = t.category_id
            WHERE {$whereSql}
            GROUP BY c.id, c.name, c.color, t.type, t.currency
            ORDER BY total DESC
            LIMIT 12
        ");
        $stmt->execute($params);
        $summary['categories'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $summary;
    }

    /**
     * Serie mensual para el gráfico, limitada a una moneda.
     *
     * @return array{labels:array<int,string>,income:array<int,float>,expense:array<int,float>}
     */
    public function monthlySeries(
        int $agencyId,
        string $from,
        string $to,
        string $currency,
        ?int $accountId = null,
        ?int $projectId = null
    ): array {
        $where = ['agency_id = ?', 'currency = ?', 'occurred_on BETWEEN ? AND ?'];
        $params = [$agencyId, strtoupper($currency), $from, $to];
        if ($accountId !== null && $accountId > 0) {
            $where[] = 'account_id = ?';
            $params[] = $accountId;
        }
        if ($projectId !== null && $projectId > 0) {
            $where[] = 'project_id = ?';
            $params[] = $projectId;
        }

        $stmt = $this->db->prepare("
            SELECT DATE_FORMAT(occurred_on, '%Y-%m') AS ym, type,
                   COALESCE(SUM(amount), 0) AS total
            FROM cash_transactions
            WHERE " . implode(' AND ', $where) . "
            GROUP BY ym, type
            ORDER BY ym ASC
        ");
        $stmt->execute($params);

        $totals = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $totals[(string)$row['ym']][$row['type']] = (float)$row['total'];
        }

        $months = CashSummary::months($from, $to);
        $series = ['labels' => [], 'income' => [], 'expense' => []];
        foreach ($months as $key => $label) {
            $series['labels'][] = $label;
            $series['income'][] = (float)($totals[$key]['ingreso'] ?? 0);
            $series['expense'][] = (float)($totals[$key]['egreso'] ?? 0);
        }

        return $series;
    }

    /**
     * Movimientos paginados con filtros.
     *
     * @param array{from:string,to:string,type:string,account_id:int,category_id:int,project_id:int,search:string} $filters
     * @return array{rows:array<int, array<string, mixed>>, total:int}
     */
    public function transactions(int $agencyId, array $filters, int $limit, int $offset): array
    {
        $joins = "
            FROM cash_transactions t
            INNER JOIN cash_accounts a ON a.id = t.account_id
            INNER JOIN cash_categories c ON c.id = t.category_id
            LEFT JOIN projects p ON p.id = t.project_id
            LEFT JOIN users u ON u.id = t.created_by
        ";

        $where = ['t.agency_id = ?'];
        $params = [$agencyId];

        if ($filters['from'] !== '') {
            $where[] = 't.occurred_on >= ?';
            $params[] = $filters['from'];
        }
        if ($filters['to'] !== '') {
            $where[] = 't.occurred_on <= ?';
            $params[] = $filters['to'];
        }
        if (in_array($filters['type'], ['ingreso', 'egreso'], true)) {
            $where[] = 't.type = ?';
            $params[] = $filters['type'];
        }
        if ($filters['account_id'] > 0) {
            $where[] = 't.account_id = ?';
            $params[] = $filters['account_id'];
        }
        if ($filters['category_id'] > 0) {
            $where[] = 't.category_id = ?';
            $params[] = $filters['category_id'];
        }
        if ($filters['project_id'] > 0) {
            $where[] = 't.project_id = ?';
            $params[] = $filters['project_id'];
        }
        if ($filters['search'] !== '') {
            $where[] = '(t.description LIKE ? OR t.reference LIKE ? OR c.name LIKE ? OR a.name LIKE ? OR p.name LIKE ?)';
            $needle = '%' . $filters['search'] . '%';
            array_push($params, $needle, $needle, $needle, $needle, $needle);
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $stmt = $this->db->prepare('SELECT COUNT(*) ' . $joins . ' ' . $whereSql);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $limit = max(1, $limit);
        $offset = max(0, $offset);

        $stmt = $this->db->prepare("
            SELECT t.*, a.name AS account_name, a.color AS account_color, a.type AS account_type,
                   c.name AS category_name, c.color AS category_color,
                   p.name AS project_name, p.color AS project_color,
                   u.name AS user_name
            {$joins}
            {$whereSql}
            ORDER BY t.occurred_on DESC, t.id DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    /** @return array<int, int> account_id => movimientos */
    public function accountUsage(int $agencyId): array
    {
        $stmt = $this->db->prepare('
            SELECT account_id, COUNT(*) AS total
            FROM cash_transactions
            WHERE agency_id = ?
            GROUP BY account_id
        ');
        $stmt->execute([$agencyId]);

        $usage = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $usage[(int)$row['account_id']] = (int)$row['total'];
        }

        return $usage;
    }

    /** @return array<int, int> category_id => movimientos */
    public function categoryUsage(int $agencyId): array
    {
        $stmt = $this->db->prepare('
            SELECT category_id, COUNT(*) AS total
            FROM cash_transactions
            WHERE agency_id = ?
            GROUP BY category_id
        ');
        $stmt->execute([$agencyId]);

        $usage = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $usage[(int)$row['category_id']] = (int)$row['total'];
        }

        return $usage;
    }

    /**
     * @param array{account_id:int,category_id:int,project_id:?int,type:string,amount:float,occurred_on:string,description:string,reference:?string,notes:?string} $data
     */
    public function createTransaction(int $agencyId, ?int $userId, array $data, string $sourceType = 'manual', ?int $sourceId = null): int
    {
        if (!in_array($sourceType, ['manual', 'invoice', 'invoice_payment', 'payable_payment'], true)) {
            throw new DomainException('Origen de movimiento no válido.');
        }

        $account = $this->requireAccount((int)$data['account_id'], $agencyId);
        if ((int)$account['is_active'] !== 1) {
            throw new DomainException('La cuenta seleccionada está inactiva.');
        }
        $category = $this->requireCategory((int)$data['category_id'], $agencyId);
        if ((int)$category['is_active'] !== 1) {
            throw new DomainException('La categoría seleccionada está inactiva.');
        }
        $this->assertCategoryType($category, $data['type']);
        $projectId = $this->normalizeProject($data['project_id'] ?? null, $agencyId);

        try {
            $this->db->prepare("
                INSERT INTO cash_transactions
                    (agency_id, account_id, category_id, project_id, type, amount, currency, occurred_on, description, reference, notes, created_by, source_type, source_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $agencyId,
                $data['account_id'],
                $data['category_id'],
                $projectId,
                $data['type'],
                $data['amount'],
                strtoupper((string)$account['currency']),
                $data['occurred_on'],
                $data['description'],
                $data['reference'],
                $data['notes'],
                $userId,
                $sourceType,
                $sourceId,
            ]);
        } catch (PDOException $e) {
            // La clave única evita duplicar un movimiento si se reintenta la sincronización.
            if (($e->errorInfo[1] ?? null) === 1062 && $sourceId !== null) {
                $existing = $this->findBySource($agencyId, $sourceType, $sourceId);
                if ($existing !== null) {
                    return (int)$existing['id'];
                }
            }
            throw $e;
        }

        return (int)$this->db->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findBySource(int $agencyId, string $sourceType, int $sourceId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM cash_transactions
            WHERE agency_id = ? AND source_type = ? AND source_id = ?
            LIMIT 1
        ");
        $stmt->execute([$agencyId, $sourceType, $sourceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** Elimina el movimiento vinculado a un origen. Devuelve cuántos borró. */
    public function deleteBySource(int $agencyId, string $sourceType, int $sourceId): int
    {
        $stmt = $this->db->prepare("
            DELETE FROM cash_transactions
            WHERE agency_id = ? AND source_type = ? AND source_id = ?
        ");
        $stmt->execute([$agencyId, $sourceType, $sourceId]);

        return $stmt->rowCount();
    }

    /** Elimina un movimiento generado automáticamente desde la pantalla de revisión. */
    public function deleteGeneratedTransaction(int $transactionId, int $agencyId): void
    {
        $stmt = $this->db->prepare("
            DELETE FROM cash_transactions
            WHERE id = ? AND agency_id = ? AND source_type <> 'manual'
        ");
        $stmt->execute([$transactionId, $agencyId]);
        if ($stmt->rowCount() === 0) {
            throw new DomainException('El movimiento automático no existe o es manual.');
        }
    }

    /**
     * Movimientos creados por facturas o pagos, para la pantalla de revisión.
     *
     * @return array{rows:array<int, array<string, mixed>>, total:int}
     */
    public function generatedTransactions(int $agencyId, int $limit, int $offset): array
    {
        $joins = "
            FROM cash_transactions t
            INNER JOIN cash_accounts a ON a.id = t.account_id
            INNER JOIN cash_categories c ON c.id = t.category_id
            LEFT JOIN invoice_payments ipay ON t.source_type = 'invoice_payment' AND ipay.id = t.source_id
            LEFT JOIN invoices i ON (t.source_type = 'invoice' AND i.id = t.source_id)
                OR (t.source_type = 'invoice_payment' AND i.id = ipay.invoice_id)
            LEFT JOIN payable_payments pp ON t.source_type = 'payable_payment' AND pp.id = t.source_id
            LEFT JOIN payables p ON p.id = pp.payable_id
        ";
        $where = "WHERE t.agency_id = ? AND t.source_type <> 'manual'";

        $stmt = $this->db->prepare('SELECT COUNT(*) ' . $joins . ' ' . $where);
        $stmt->execute([$agencyId]);
        $total = (int)$stmt->fetchColumn();

        $limit = max(1, $limit);
        $offset = max(0, $offset);

        $stmt = $this->db->prepare("
            SELECT t.*, a.name AS account_name, a.color AS account_color,
                   c.name AS category_name, c.color AS category_color,
                   i.invoice_number, i.concept AS invoice_concept,
                   p.supplier, p.concept AS payable_concept
            {$joins}
            {$where}
            ORDER BY t.occurred_on DESC, t.id DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute([$agencyId]);

        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    /**
     * @param array{account_id:int,category_id:int,project_id:?int,type:string,amount:float,occurred_on:string,description:string,reference:?string,notes:?string} $data
     */
    public function updateTransaction(int $transactionId, int $agencyId, array $data): void
    {
        $current = $this->findTransaction($transactionId, $agencyId);
        if ($current === null) {
            throw new DomainException('Movimiento de caja no encontrado.');
        }

        $account = $this->requireAccount((int)$data['account_id'], $agencyId);
        if ((int)$account['is_active'] !== 1 && (int)$account['id'] !== (int)$current['account_id']) {
            throw new DomainException('La cuenta seleccionada está inactiva.');
        }
        $category = $this->requireCategory((int)$data['category_id'], $agencyId);
        if ((int)$category['is_active'] !== 1 && (int)$category['id'] !== (int)$current['category_id']) {
            throw new DomainException('La categoría seleccionada está inactiva.');
        }
        $this->assertCategoryType($category, $data['type']);
        $projectId = $this->normalizeProject($data['project_id'] ?? null, $agencyId);

        $this->db->prepare("
            UPDATE cash_transactions
            SET account_id = ?, category_id = ?, project_id = ?, type = ?, amount = ?,
                currency = ?, occurred_on = ?, description = ?, reference = ?, notes = ?
            WHERE id = ? AND agency_id = ?
        ")->execute([
            $data['account_id'],
            $data['category_id'],
            $projectId,
            $data['type'],
            $data['amount'],
            strtoupper((string)$account['currency']),
            $data['occurred_on'],
            $data['description'],
            $data['reference'],
            $data['notes'],
            $transactionId,
            $agencyId,
        ]);
    }

    public function deleteTransaction(int $transactionId, int $agencyId): void
    {
        $stmt = $this->db->prepare('DELETE FROM cash_transactions WHERE id = ? AND agency_id = ?');
        $stmt->execute([$transactionId, $agencyId]);
        if ($stmt->rowCount() === 0) {
            throw new DomainException('Movimiento de caja no encontrado.');
        }
    }

    public function createCategory(int $agencyId, string $type, string $name, ?string $color): int
    {
        $this->assertCategoryTypeInput($type);
        $name = $this->normalizeName($name, 'categoría');

        try {
            $this->db->prepare('
                INSERT INTO cash_categories (agency_id, type, name, color, is_active)
                VALUES (?, ?, ?, ?, 1)
            ')->execute([$agencyId, $type, $name, $color]);
        } catch (PDOException $e) {
            $this->translateDuplicate($e, 'Ya existe una categoría con ese nombre y tipo.');
        }

        return (int)$this->db->lastInsertId();
    }

    public function updateCategory(int $categoryId, int $agencyId, string $type, string $name, ?string $color): void
    {
        $category = $this->requireCategory($categoryId, $agencyId);
        $this->assertCategoryTypeInput($type);
        $name = $this->normalizeName($name, 'categoría');

        if ($category['type'] !== $type && (($this->categoryUsage($agencyId)[$categoryId] ?? 0) > 0)) {
            throw new DomainException('No puedes cambiar el tipo de una categoría que ya tiene movimientos.');
        }

        try {
            $this->db->prepare('
                UPDATE cash_categories
                SET type = ?, name = ?, color = ?
                WHERE id = ? AND agency_id = ?
            ')->execute([$type, $name, $color, $categoryId, $agencyId]);
        } catch (PDOException $e) {
            $this->translateDuplicate($e, 'Ya existe una categoría con ese nombre y tipo.');
        }
    }

    public function deleteCategory(int $categoryId, int $agencyId): void
    {
        $category = $this->requireCategory($categoryId, $agencyId);
        $usage = $this->categoryUsage($agencyId);
        if (($usage[$categoryId] ?? 0) > 0) {
            throw new DomainException('La categoría "' . $category['name'] . '" tiene movimientos. Desactívala en lugar de eliminarla.');
        }

        $this->db->prepare('DELETE FROM cash_categories WHERE id = ? AND agency_id = ?')
            ->execute([$categoryId, $agencyId]);
    }

    public function toggleCategory(int $categoryId, int $agencyId): bool
    {
        $category = $this->requireCategory($categoryId, $agencyId);
        $this->db->prepare('UPDATE cash_categories SET is_active = ? WHERE id = ? AND agency_id = ?')
            ->execute([(int)$category['is_active'] === 1 ? 0 : 1, $categoryId, $agencyId]);

        return (int)$category['is_active'] !== 1;
    }

    public function createAccount(
        int $agencyId,
        string $name,
        string $type,
        string $currency,
        float $openingBalance,
        ?string $color
    ): int {
        $name = $this->normalizeName($name, 'cuenta');
        $this->assertAccountTypeInput($type);
        $currency = $this->normalizeCurrency($currency);

        try {
            $this->db->prepare('
                INSERT INTO cash_accounts (agency_id, name, type, currency, opening_balance, color, is_active)
                VALUES (?, ?, ?, ?, ?, ?, 1)
            ')->execute([$agencyId, $name, $type, $currency, $openingBalance, $color]);
        } catch (PDOException $e) {
            $this->translateDuplicate($e, 'Ya existe una cuenta de caja con ese nombre.');
        }

        return (int)$this->db->lastInsertId();
    }

    public function updateAccount(
        int $accountId,
        int $agencyId,
        string $name,
        string $type,
        string $currency,
        float $openingBalance,
        ?string $color
    ): void {
        $account = $this->requireAccount($accountId, $agencyId);
        $name = $this->normalizeName($name, 'cuenta');
        $this->assertAccountTypeInput($type);
        $currency = $this->normalizeCurrency($currency);

        $usage = $this->accountUsage($agencyId);
        if ($currency !== strtoupper((string)$account['currency']) && ($usage[$accountId] ?? 0) > 0) {
            throw new DomainException('No puedes cambiar la moneda de una cuenta que ya tiene movimientos.');
        }

        try {
            $this->db->prepare('
                UPDATE cash_accounts
                SET name = ?, type = ?, currency = ?, opening_balance = ?, color = ?
                WHERE id = ? AND agency_id = ?
            ')->execute([$name, $type, $currency, $openingBalance, $color, $accountId, $agencyId]);
        } catch (PDOException $e) {
            $this->translateDuplicate($e, 'Ya existe una cuenta de caja con ese nombre.');
        }
    }

    public function deleteAccount(int $accountId, int $agencyId): void
    {
        $account = $this->requireAccount($accountId, $agencyId);
        $usage = $this->accountUsage($agencyId);
        if (($usage[$accountId] ?? 0) > 0) {
            throw new DomainException('La cuenta "' . $account['name'] . '" tiene movimientos. Desactívala en lugar de eliminarla.');
        }

        $this->db->prepare('DELETE FROM cash_accounts WHERE id = ? AND agency_id = ?')
            ->execute([$accountId, $agencyId]);
    }

    public function toggleAccount(int $accountId, int $agencyId): bool
    {
        $account = $this->requireAccount($accountId, $agencyId);
        $this->db->prepare('UPDATE cash_accounts SET is_active = ? WHERE id = ? AND agency_id = ?')
            ->execute([(int)$account['is_active'] === 1 ? 0 : 1, $accountId, $agencyId]);

        return (int)$account['is_active'] !== 1;
    }

    /** @return array<string, mixed> */
    private function requireAccount(int $accountId, int $agencyId): array
    {
        $account = $this->findAccount($accountId, $agencyId);
        if ($account === null) {
            throw new DomainException('La cuenta de caja no existe en esta agencia.');
        }

        return $account;
    }

    /** @return array<string, mixed> */
    private function requireCategory(int $categoryId, int $agencyId): array
    {
        $category = $this->findCategory($categoryId, $agencyId);
        if ($category === null) {
            throw new DomainException('La categoría no existe en esta agencia.');
        }

        return $category;
    }

    private function assertCategoryType(array $category, string $type): void
    {
        if (($category['type'] ?? '') !== $type) {
            throw new DomainException('La categoría seleccionada no corresponde al tipo de movimiento.');
        }
    }

    private function normalizeProject(mixed $projectId, int $agencyId): ?int
    {
        $projectId = (int)($projectId ?? 0);
        if ($projectId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT 1 FROM projects WHERE id = ? AND agency_id = ?');
        $stmt->execute([$projectId, $agencyId]);
        if ($stmt->fetchColumn() === false) {
            throw new DomainException('El proyecto seleccionado no pertenece a esta agencia.');
        }

        return $projectId;
    }

    private function assertCategoryTypeInput(string $type): void
    {
        if (!in_array($type, ['ingreso', 'egreso'], true)) {
            throw new DomainException('Tipo de categoría no válido.');
        }
    }

    private function assertAccountTypeInput(string $type): void
    {
        if (!in_array($type, ['efectivo', 'banco', 'tarjeta', 'otro'], true)) {
            throw new DomainException('Tipo de cuenta no válido.');
        }
    }

    private function normalizeName(string $name, string $what): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            throw new DomainException('El nombre de la ' . $what . ' es obligatorio (máximo 80 caracteres).');
        }

        return $name;
    }

    private function normalizeCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if (!\App\Support\Format::isValidCurrency($currency)) {
            throw new DomainException('Moneda no válida.');
        }

        return $currency;
    }

    private function translateDuplicate(PDOException $e, string $message): void
    {
        if (($e->errorInfo[1] ?? null) === 1062) {
            throw new DomainException($message);
        }

        throw $e;
    }
}
