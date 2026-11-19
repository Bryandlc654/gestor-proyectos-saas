<?php
// api/app/cash.php - Caja: ingresos, egresos, cuentas, categorías y automatización para React.
require_once __DIR__ . '/_bootstrap.php';

if (!in_array($role, ['admin', 'project_manager'], true) || !moduleAllowed('cash')) {
    appJson(['error' => 'Módulo de caja no disponible'], 403);
}

$normalizeDate = static function (mixed $value, string $fallback): string {
    $value = trim((string)$value);
    if (!isIsoDate($value)) {
        return $fallback;
    }
    [$year, $month, $day] = array_map('intval', explode('-', $value));
    return checkdate($month, $day, $year) ? $value : $fallback;
};

$normalizeColor = static function (mixed $value): ?string {
    $value = strtolower(trim((string)$value));
    return preg_match('/^#[0-9a-f]{6}$/', $value) ? $value : null;
};

$visibleProjectIds = array_map('intval', projectRepository()->visibleProjectIds($userId, $role));

$movementData = static function () use ($visibleProjectIds, $normalizeDate): array {
    $type = (string)($_POST['type'] ?? '');
    if (!in_array($type, ['ingreso', 'egreso'], true)) {
        throw new DomainException('Selecciona si el movimiento es ingreso o egreso.');
    }

    $accountId = (int)($_POST['account_id'] ?? 0);
    if ($accountId <= 0) {
        throw new DomainException('Selecciona una cuenta de caja.');
    }

    $categoryId = (int)($_POST['category_id'] ?? 0);
    if ($categoryId <= 0) {
        throw new DomainException('Selecciona una categoría.');
    }

    $amount = round((float)($_POST['amount'] ?? 0), 2);
    if ($amount <= 0) {
        throw new DomainException('El importe debe ser mayor que cero.');
    }
    if ($amount > 9999999999.99) {
        throw new DomainException('El importe es demasiado alto.');
    }

    $description = trim((string)($_POST['description'] ?? ''));
    if ($description === '' || mb_strlen($description) > 255) {
        throw new DomainException('Escribe una descripción (máximo 255 caracteres).');
    }

    $reference = trim((string)($_POST['reference'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $projectId = (int)($_POST['project_id'] ?? 0);
    if ($projectId > 0 && !in_array($projectId, $visibleProjectIds, true)) {
        throw new DomainException('El proyecto seleccionado no está disponible para tu usuario.');
    }

    $occurredOn = trim((string)($_POST['occurred_on'] ?? ''));
    if (!isIsoDate($occurredOn)) {
        throw new DomainException('La fecha no es válida.');
    }
    [$year, $month, $day] = array_map('intval', explode('-', $occurredOn));
    if (!checkdate($month, $day, $year)) {
        throw new DomainException('La fecha no es válida.');
    }

    return [
        'account_id' => $accountId,
        'category_id' => $categoryId,
        'project_id' => $projectId > 0 ? $projectId : null,
        'type' => $type,
        'amount' => $amount,
        'occurred_on' => $occurredOn,
        'description' => $description,
        'reference' => $reference !== '' ? mb_substr($reference, 0, 80) : null,
        'notes' => $notes !== '' ? mb_substr($notes, 0, 2000) : null,
    ];
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        switch ($action) {
            case 'save_movement':
                $data = $movementData();
                $movementId = (int)($_POST['movement_id'] ?? 0);

                if ($movementId > 0) {
                    cashRepository()->updateTransaction($movementId, $agencyId, $data);
                    $saved = cashRepository()->findTransaction($movementId, $agencyId);
                    activityRepository()->logByUser($userId, $data['project_id'], 'cash.movement_updated', 'Actualizó el movimiento de caja "' . $data['description'] . '" por ' . formatMoney($data['amount'], $saved['currency'] ?? 'USD'));
                    appJson(['success' => true, 'id' => $movementId, 'message' => 'Movimiento actualizado.']);
                }

                $movementId = cashRepository()->createTransaction($agencyId, $userId, $data);
                $saved = cashRepository()->findTransaction($movementId, $agencyId);
                activityRepository()->logByUser($userId, $data['project_id'], 'cash.movement_created', 'Registró un ' . $data['type'] . ' de caja "' . $data['description'] . '" por ' . formatMoney($data['amount'], $saved['currency'] ?? 'USD'));
                appJson(['success' => true, 'id' => $movementId, 'message' => 'Movimiento registrado.']);

            case 'delete_movement':
                $movementId = (int)($_POST['movement_id'] ?? 0);
                $movement = cashRepository()->findTransaction($movementId, $agencyId);
                if ($movement === null) {
                    appJson(['error' => 'Movimiento de caja no encontrado.'], 404);
                }
                cashRepository()->deleteTransaction($movementId, $agencyId);
                activityRepository()->logByUser($userId, $movement['project_id'] !== null ? (int)$movement['project_id'] : null, 'cash.movement_deleted', 'Eliminó el movimiento de caja "' . $movement['description'] . '"');
                appJson(['success' => true, 'message' => 'Movimiento eliminado.']);

            case 'save_category':
                $categoryId = (int)($_POST['category_id'] ?? 0);
                $type = (string)($_POST['type'] ?? '');
                $name = trim((string)($_POST['name'] ?? ''));
                if ($name === '' || mb_strlen($name) > 80) {
                    appJson(['error' => 'El nombre de la categoría es obligatorio (máximo 80 caracteres).'], 422);
                }
                $color = $normalizeColor($_POST['color'] ?? '');

                if ($categoryId > 0) {
                    cashRepository()->updateCategory($categoryId, $agencyId, $type, $name, $color);
                    activityRepository()->logByUser($userId, null, 'cash.category_updated', 'Actualizó la categoría de caja "' . $name . '"');
                    appJson(['success' => true, 'id' => $categoryId, 'message' => 'Categoría actualizada.']);
                }

                $categoryId = cashRepository()->createCategory($agencyId, $type, $name, $color);
                activityRepository()->logByUser($userId, null, 'cash.category_created', 'Creó la categoría de caja "' . $name . '"');
                appJson(['success' => true, 'id' => $categoryId, 'message' => 'Categoría creada.']);

            case 'delete_category':
                $categoryId = (int)($_POST['category_id'] ?? 0);
                $category = cashRepository()->findCategory($categoryId, $agencyId);
                if ($category === null) {
                    appJson(['error' => 'Categoría no encontrada.'], 404);
                }
                cashRepository()->deleteCategory($categoryId, $agencyId);
                activityRepository()->logByUser($userId, null, 'cash.category_deleted', 'Eliminó la categoría de caja "' . $category['name'] . '"');
                appJson(['success' => true, 'message' => 'Categoría eliminada.']);

            case 'toggle_category':
                $categoryId = (int)($_POST['category_id'] ?? 0);
                $active = cashRepository()->toggleCategory($categoryId, $agencyId);
                activityRepository()->logByUser($userId, null, 'cash.category_toggled', ($active ? 'Activó' : 'Desactivó') . ' una categoría de caja');
                appJson(['success' => true, 'active' => $active, 'message' => $active ? 'Categoría activada.' : 'Categoría desactivada.']);

            case 'save_account':
                $accountId = (int)($_POST['account_id'] ?? 0);
                $name = trim((string)($_POST['name'] ?? ''));
                if ($name === '' || mb_strlen($name) > 80) {
                    appJson(['error' => 'El nombre de la cuenta es obligatorio (máximo 80 caracteres).'], 422);
                }
                $type = (string)($_POST['type'] ?? 'efectivo');
                $currency = (string)($_POST['currency'] ?? 'USD');
                $opening = round((float)($_POST['opening_balance'] ?? 0), 2);
                $color = $normalizeColor($_POST['color'] ?? '');

                if (abs($opening) > 9999999999.99) {
                    appJson(['error' => 'El saldo inicial es demasiado alto.'], 422);
                }

                if ($accountId > 0) {
                    cashRepository()->updateAccount($accountId, $agencyId, $name, $type, $currency, $opening, $color);
                    activityRepository()->logByUser($userId, null, 'cash.account_updated', 'Actualizó la cuenta de caja "' . $name . '"');
                    appJson(['success' => true, 'id' => $accountId, 'message' => 'Cuenta actualizada.']);
                }

                $accountId = cashRepository()->createAccount($agencyId, $name, $type, $currency, $opening, $color);
                activityRepository()->logByUser($userId, null, 'cash.account_created', 'Creó la cuenta de caja "' . $name . '"');
                appJson(['success' => true, 'id' => $accountId, 'message' => 'Cuenta creada.']);

            case 'delete_account':
                $accountId = (int)($_POST['account_id'] ?? 0);
                $account = cashRepository()->findAccount($accountId, $agencyId);
                if ($account === null) {
                    appJson(['error' => 'Cuenta no encontrada.'], 404);
                }
                cashRepository()->deleteAccount($accountId, $agencyId);
                activityRepository()->logByUser($userId, null, 'cash.account_deleted', 'Eliminó la cuenta de caja "' . $account['name'] . '"');
                appJson(['success' => true, 'message' => 'Cuenta eliminada.']);

            case 'toggle_account':
                $accountId = (int)($_POST['account_id'] ?? 0);
                $active = cashRepository()->toggleAccount($accountId, $agencyId);
                activityRepository()->logByUser($userId, null, 'cash.account_toggled', ($active ? 'Activó' : 'Desactivó') . ' una cuenta de caja');
                appJson(['success' => true, 'active' => $active, 'message' => $active ? 'Cuenta activada.' : 'Cuenta desactivada.']);

            case 'save_automation':
                $posted = $_POST['rules'] ?? [];
                if (is_string($posted)) {
                    $decoded = json_decode($posted, true);
                    $posted = is_array($decoded) ? $decoded : [];
                }
                $rules = [];
                foreach ((array)$posted as $rule) {
                    if (!is_array($rule)) {
                        continue;
                    }
                    $ruleId = (int)($rule['id'] ?? 0);
                    if ($ruleId <= 0) {
                        continue;
                    }
                    $rules[$ruleId] = [
                        'account_id' => (int)($rule['account_id'] ?? 0),
                        'category_id' => (int)($rule['category_id'] ?? 0),
                        'is_active' => !empty($rule['is_active']) ? 1 : 0,
                    ];
                }
                cashAutomationService()->saveRules($agencyId, $rules, $userId);
                activityRepository()->logByUser($userId, null, 'cash.automation_saved', 'Actualizó las reglas de automatización de caja');
                appJson(['success' => true, 'message' => 'Automatización actualizada.']);

            case 'delete_generated_movement':
                $movementId = (int)($_POST['movement_id'] ?? 0);
                cashAutomationService()->deleteGeneratedMovement($movementId, $agencyId);
                activityRepository()->logByUser($userId, null, 'cash.automation_removed', 'Quitó un movimiento automático de caja');
                appJson(['success' => true, 'message' => 'Movimiento automático quitado de caja.']);

            default:
                appJson(['error' => 'Acción no reconocida.'], 400);
        }
    } catch (\DomainException $e) {
        appJson(['error' => $e->getMessage()], 422);
    }
}

appMethod('GET');

cashRepository()->ensureDefaults($agencyId);
cashAutomationService()->ensureDefaults($agencyId);

$allowedTabs = ['movimientos', 'categorias', 'cuentas', 'automatizacion'];
$activeTab = (string)($_GET['tab'] ?? 'movimientos');
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'movimientos';
}

$today = date('Y-m-d');
$defaultFrom = date('Y-m-01');
$defaultTo = date('Y-m-t');
$from = $normalizeDate($_GET['from'] ?? '', $defaultFrom);
$to = $normalizeDate($_GET['to'] ?? '', $defaultTo);
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$filterType = (string)($_GET['type'] ?? 'todos');
if (!in_array($filterType, ['todos', 'ingreso', 'egreso'], true)) {
    $filterType = 'todos';
}
$filterAccount = (int)($_GET['account_id'] ?? 0);
$filterCategory = (int)($_GET['category_id'] ?? 0);
$filterProject = (int)($_GET['project_id'] ?? 0);
$search = trim((string)($_GET['search'] ?? ''));
if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}

$accounts = cashRepository()->accounts($agencyId, true);
$accountsById = [];
foreach ($accounts as $account) {
    $accountsById[(int)$account['id']] = $account;
}

$categories = cashRepository()->categories($agencyId, null, true);
$categoriesById = [];
foreach ($categories as $category) {
    $categoriesById[(int)$category['id']] = $category;
}

$accountUsage = cashRepository()->accountUsage($agencyId);
$categoryUsage = cashRepository()->categoryUsage($agencyId);

if (!isset($accountsById[$filterAccount])) {
    $filterAccount = 0;
}
if (!isset($categoriesById[$filterCategory])) {
    $filterCategory = 0;
}

$projectsList = [];
if ($visibleProjectIds !== []) {
    $placeholders = implode(',', array_fill(0, count($visibleProjectIds), '?'));
    $stmtProjects = getDB()->prepare("SELECT id, name, currency FROM projects WHERE agency_id = ? AND id IN ($placeholders) ORDER BY name ASC");
    $stmtProjects->execute([$agencyId, ...$visibleProjectIds]);
    $projectsList = $stmtProjects->fetchAll(PDO::FETCH_ASSOC);
}
$projectIds = array_map(static fn(array $project): int => (int)$project['id'], $projectsList);
if ($filterProject > 0 && !in_array($filterProject, $projectIds, true)) {
    $filterProject = 0;
}

$balances = cashRepository()->accountBalances($agencyId);
$summary = cashRepository()->summary(
    $agencyId,
    $from,
    $to,
    $filterAccount > 0 ? $filterAccount : null,
    $filterCategory > 0 ? $filterCategory : null,
    $filterProject > 0 ? $filterProject : null
);

$chartCurrencies = [];
foreach ($balances as $balance) {
    $chartCurrencies[$balance['currency']] = true;
}
foreach (array_merge(array_keys($summary['income']), array_keys($summary['expense'])) as $currency) {
    $chartCurrencies[(string)$currency] = true;
}
$chartCurrencies = array_keys($chartCurrencies);
sort($chartCurrencies);
if ($chartCurrencies === []) {
    $chartCurrencies = ['USD'];
}
$chartCurrency = strtoupper(trim((string)($_GET['chart_currency'] ?? '')));
if (!in_array($chartCurrency, $chartCurrencies, true)) {
    $chartCurrency = in_array('USD', $chartCurrencies, true) ? 'USD' : $chartCurrencies[0];
}

$chartFrom = date('Y-m-01', strtotime($to . ' -5 months'));
$chartSeries = cashRepository()->monthlySeries(
    $agencyId,
    $chartFrom,
    $to,
    $chartCurrency,
    $filterAccount > 0 ? $filterAccount : null,
    $filterProject > 0 ? $filterProject : null
);

$filters = [
    'from' => $from,
    'to' => $to,
    'type' => $filterType,
    'account_id' => $filterAccount,
    'category_id' => $filterCategory,
    'project_id' => $filterProject,
    'search' => $search,
];

$movements = [];
$pagination = ['page' => 1, 'perPage' => 20, 'total' => 0, 'pages' => 1];
if ($activeTab === 'movimientos') {
    $perPage = 20;
    $firstPage = cashRepository()->transactions($agencyId, $filters, 1, 0);
    $total = (int)$firstPage['total'];
    $pages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min($pages, (int)($_GET['page'] ?? 1)));
    $offset = ($page - 1) * $perPage;
    $result = cashRepository()->transactions($agencyId, $filters, $perPage, $offset);
    $pagination = ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'pages' => $pages];

    foreach ($result['rows'] as $movement) {
        $movements[] = [
            'id' => (int)$movement['id'],
            'type' => $movement['type'],
            'amount' => (float)$movement['amount'],
            'currency' => strtoupper((string)$movement['currency']),
            'occurredOn' => $movement['occurred_on'],
            'description' => $movement['description'],
            'reference' => $movement['reference'],
            'notes' => $movement['notes'],
            'sourceType' => $movement['source_type'] ?? 'manual',
            'accountId' => (int)$movement['account_id'],
            'accountName' => $movement['account_name'],
            'accountColor' => $movement['account_color'] ?: '#0E66FF',
            'categoryId' => (int)$movement['category_id'],
            'categoryName' => $movement['category_name'],
            'categoryColor' => $movement['category_color'] ?: '#64748B',
            'projectId' => $movement['project_id'] !== null ? (int)$movement['project_id'] : null,
            'projectName' => $movement['project_name'],
            'projectColor' => $movement['project_color'] ?: '#0E66FF',
            'user' => $movement['user_name'],
        ];
    }
}

$automation = null;
if ($activeTab === 'automatizacion') {
    $rules = [];
    foreach (cashAutomationService()->rules($agencyId) as $rule) {
        $rules[] = [
            'id' => (int)$rule['id'],
            'kind' => $rule['kind'],
            'currency' => strtoupper((string)$rule['currency']),
            'accountId' => (int)$rule['account_id'],
            'categoryId' => (int)$rule['category_id'],
            'accountActive' => (int)$rule['account_active'] === 1,
            'categoryActive' => (int)$rule['category_active'] === 1,
            'isActive' => (int)$rule['is_active'] === 1,
        ];
    }

    $generatedPerPage = 15;
    $firstGenerated = cashAutomationService()->generatedMovements($agencyId, 1, 0);
    $generatedTotal = (int)$firstGenerated['total'];
    $generatedPages = max(1, (int)ceil($generatedTotal / $generatedPerPage));
    $generatedPage = max(1, min($generatedPages, (int)($_GET['generated_page'] ?? 1)));
    $generatedResult = cashAutomationService()->generatedMovements($agencyId, $generatedPerPage, ($generatedPage - 1) * $generatedPerPage);

    $generated = [];
    foreach ($generatedResult['rows'] as $row) {
        $sourceType = (string)($row['source_type'] ?? '');
        $generated[] = [
            'id' => (int)$row['id'],
            'type' => $row['type'],
            'amount' => (float)$row['amount'],
            'currency' => strtoupper((string)$row['currency']),
            'occurredOn' => $row['occurred_on'],
            'description' => $row['description'],
            'accountName' => $row['account_name'],
            'categoryName' => $row['category_name'],
            'sourceType' => $sourceType,
            'invoiceNumber' => $row['invoice_number'] ?? null,
            'supplier' => $row['supplier'] ?? null,
        ];
    }

    $automation = [
        'rules' => $rules,
        'missing' => cashAutomationService()->currenciesWithoutAccount($agencyId),
        'generated' => $generated,
        'generatedPagination' => [
            'page' => $generatedPage,
            'perPage' => $generatedPerPage,
            'total' => $generatedTotal,
            'pages' => $generatedPages,
        ],
    ];
}

$accountsResponse = [];
foreach ($accounts as $account) {
    $accountId = (int)$account['id'];
    $balance = $balances[$accountId] ?? ['balance' => (float)$account['opening_balance'], 'currency' => strtoupper((string)$account['currency'])];
    $accountsResponse[] = [
        'id' => $accountId,
        'name' => $account['name'],
        'type' => $account['type'],
        'currency' => strtoupper((string)$account['currency']),
        'openingBalance' => (float)$account['opening_balance'],
        'color' => $account['color'] ?: '#0E66FF',
        'active' => (int)$account['is_active'] === 1,
        'movements' => (int)($accountUsage[$accountId] ?? 0),
        'balance' => (float)$balance['balance'],
    ];
}

$categoriesResponse = [];
foreach ($categories as $category) {
    $categoryId = (int)$category['id'];
    $categoriesResponse[] = [
        'id' => $categoryId,
        'name' => $category['name'],
        'type' => $category['type'],
        'color' => $category['color'] ?: ($category['type'] === 'ingreso' ? '#059669' : '#E11D48'),
        'active' => (int)$category['is_active'] === 1,
        'movements' => (int)($categoryUsage[$categoryId] ?? 0),
    ];
}

appJson([
    'tab' => $activeTab,
    'today' => $today,
    'period' => ['from' => $from, 'to' => $to, 'defaultFrom' => $defaultFrom, 'defaultTo' => $defaultTo],
    'filtersActive' => $from !== $defaultFrom || $to !== $defaultTo || $filterType !== 'todos' || $filterAccount > 0 || $filterCategory > 0 || $filterProject > 0 || $search !== '',
    'summary' => [
        'income' => $summary['income'],
        'expense' => $summary['expense'],
        'net' => $summary['net'],
        'balance' => $summary['balance'],
        'count' => (int)$summary['count'],
        'incomeCount' => (int)$summary['income_count'],
        'expenseCount' => (int)$summary['expense_count'],
        'categories' => array_map(static fn(array $row): array => [
            'name' => $row['name'],
            'color' => $row['color'],
            'type' => $row['type'],
            'currency' => strtoupper((string)$row['currency']),
            'total' => (float)$row['total'],
            'movements' => (int)$row['movements'],
        ], $summary['categories']),
    ],
    'accounts' => $accountsResponse,
    'categories' => $categoriesResponse,
    'chart' => [
        'currencies' => $chartCurrencies,
        'currency' => $chartCurrency,
        'series' => $chartSeries,
    ],
    'movements' => $movements,
    'pagination' => $pagination,
    'projects' => array_map(static fn(array $project): array => [
        'id' => (int)$project['id'],
        'name' => $project['name'],
        'currency' => strtoupper((string)($project['currency'] ?: 'USD')),
    ], $projectsList),
    'automation' => $automation,
    'accountTypes' => ['efectivo' => 'Efectivo', 'banco' => 'Banco', 'tarjeta' => 'Tarjeta', 'otro' => 'Otra'],
    'currencies' => \App\Support\Format::CURRENCIES,
]);
