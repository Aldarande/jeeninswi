<?php
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

// SECURITY: restreindre aux appels locaux uniquement (le démon tourne sur 127.0.0.1)
$remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($remote_ip !== '127.0.0.1' && $remote_ip !== '::1') {
    log::add('jeeninswi', 'warning', 'callback.php : IP non autorisée (' . $remote_ip . ')');
    http_response_code(403);
    die('Forbidden');
}

// (F-003) Clé API lue depuis le header X-Api-Key en priorité,
// avec fallback sur le paramètre GET pour rétrocompatibilité.
// Le header est préféré car il n'apparaît pas dans les logs de serveur web.
$apikey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['apikey'] ?? $_POST['apikey'] ?? '';
if (empty($apikey) || $apikey !== jeedom::getApiKey('jeeninswi')) {
    log::add('jeeninswi', 'warning', 'callback.php : clé API invalide (IP=' . ($_SERVER['REMOTE_ADDR'] ?? '?') . ')');
    http_response_code(403);
    die('Forbidden');
}

$rawInput = file_get_contents('php://input');
log::add('jeeninswi', 'debug', '[callback.php] Payload reçu (' . strlen($rawInput) . ' octets) depuis IP=' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    log::add('jeeninswi', 'debug', '[callback.php] Payload invalide (non-JSON) : ' . substr($rawInput, 0, 200));
    http_response_code(400);
    die('Bad Request');
}
log::add('jeeninswi', 'debug', '[callback.php] device_id=' . ($data['device_id'] ?? '?') . ' | clés=' . implode(', ', array_keys($data)));

try {
    jeeninswi::callback($data);
    log::add('jeeninswi', 'debug', '[callback.php] Traitement terminé avec succès');
    echo json_encode(['status' => 'ok']);
} catch (Exception $e) {
    log::add('jeeninswi', 'error', 'callback.php:' . __LINE__ . ' ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
