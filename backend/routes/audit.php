<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/admin_guard.php';
require_once __DIR__ . '/../config/database.php';

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 25)));
$offset = ($page - 1) * $perPage;
$search = trim($_GET['search'] ?? '');
$action = trim($_GET['action_filter'] ?? '');
$entityType = trim($_GET['entity_type'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$conditions = [];
$params = [];

if ($search !== '') {
    $conditions[] = '(a.details LIKE :search_details OR u.full_name LIKE :search_user)';
    $searchValue = '%' . $search . '%';
    $params[':search_details'] = $searchValue;
    $params[':search_user'] = $searchValue;
}

if ($action !== '') {
    $conditions[] = 'a.action = :action';
    $params[':action'] = $action;
}
if ($entityType !== '') {
    $conditions[] = 'a.entity_type = :entity_type';
    $params[':entity_type'] = $entityType;
}
if ($dateFrom !== '' && DateTimeImmutable::createFromFormat('!Y-m-d', $dateFrom) !== false) {
    $conditions[] = 'a.created_at >= :date_from';
    $params[':date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '' && DateTimeImmutable::createFromFormat('!Y-m-d', $dateTo) !== false) {
    $conditions[] = 'a.created_at < :date_to';
    $params[':date_to'] = (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d 00:00:00');
}

$whereSql = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

try {
    $pdo = getDbConnection();

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM audit a LEFT JOIN users u ON u.id = a.user_id{$whereSql}"
    );
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $logsStmt = $pdo->prepare(
        "SELECT a.id, a.user_id, u.full_name AS user_name, a.action, a.entity_type,
                a.entity_id, a.details, a.created_at
         FROM audit a
         LEFT JOIN users u ON u.id = a.user_id
         {$whereSql}
         ORDER BY a.created_at DESC, a.id DESC
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $key => $value) {
        $logsStmt->bindValue($key, $value);
    }
    $logsStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $logsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $logsStmt->execute();

    echo json_encode([
        'success' => true,
        'logs' => $logsStmt->fetchAll(PDO::FETCH_ASSOC),
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('Audit log query failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to load audit logs. Please check the server logs.']);
}
