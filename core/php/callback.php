<?php
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

// SECURITY: restreindre aux appels locaux uniquement (le démon tourne sur 127.0.0.1)
$remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($remote_ip !== '127.0.0.1' && $remote_ip !== '::1') {
    log::add('jeeninswi', 'warning', 'callback.php : IP non autorisée (' . $remote_ip . ')');
    http_response_code(403);
    die('Forbidden');
}

// Vérification par clé API (fonctionne quel que soit le réseau Docker/local)
$apikey = $_GET['apikey'] ?? $_POST['apikey'] ?? '';
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
// Validation du device_id : format hexadécimal 16 caractères (format Nintendo)
if (empty($data['device_id']) || !preg_match('/^[a-f0-9]{16}$/i', $data['device_id'])) {
    log::add('jeeninswi', 'warning', '[callback.php] device_id invalide ou manquant : ' . htmlspecialchars($data['device_id'] ?? 'null', ENT_QUOTES));
    http_response_code(400);
    die(json_encode(['status' => 'error', 'message' => 'device_id invalide']));
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
