<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/database.php';

$action = $_GET['action'] ?? '';
if ($action !== 'list') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unsupported client action.']);
    exit;
}

$status = trim((string) ($_GET['status'] ?? 'active'));
if (!in_array($status, ['active', 'inactive', 'all'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid client status filter.']);
    exit;
}

$pdo = getDbConnection();
$sql = 'SELECT id, name, company_name, party_type, status FROM clients';
if ($status !== 'all') {
    $sql .= ' WHERE status = :status';
}
$sql .= ' ORDER BY name ASC';

$stmt = $pdo->prepare($sql);
if ($status !== 'all') {
    $stmt->bindValue(':status', $status);
}
$stmt->execute();

echo json_encode([
    'success' => true,
    'clients' => $stmt->fetchAll(PDO::FETCH_ASSOC),
]);
