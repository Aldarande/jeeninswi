<?php
/**
 * JeeNinSwi - Endpoints AJAX
 * Tous les appels JavaScript du plugin passent par ce fichier.
 * L'authentification est gérée par ajax::init() qui vérifie le token de session Jeedom.
 *
 * Actions disponibles :
 *   - getDeviceStatus       : valeurs courantes des commandes d'un équipement
 *   - sendAction            : exécution d'une commande action sur un équipement
 *   - getAuthUrl            : génère l'URL OAuth Nintendo (étape 1 de l'assistant token)
 *   - exchangeAndSaveToken  : (F-002) échange OAuth + sauvegarde immédiate — token reste serveur
 *   - saveTokenAndDevices   : sauvegarde token + crée équipements (conservé pour compatibilité)
 *   - exchangeToken         : [DÉPRÉCIÉE] retournait le token au navigateur JS — ne plus exposer
 */

/**
 * (F-002) Fonction utilitaire : créer/mettre à jour les équipements Jeedom depuis un token.
 * Utilisée par exchangeAndSaveToken ET saveTokenAndDevices pour éviter la duplication.
 *
 * @param string $token       Token de session Nintendo
 * @param array  $devices     Liste [{id, name}] des consoles détectées
 * @param int    $currentEqId ID de l'équipement Jeedom courant (créé vide par l'UI)
 * @return array              Noms des équipements nouvellement créés
 */
function _jeeninswi_persist_devices(string $token, array $devices, int $currentEqId): array {
    $firstDeviceDone = false;
    $created         = [];

    $currentEq = null;
    if ($currentEqId > 0) {
        $eq = eqLogic::byId($currentEqId);
        if (is_object($eq) && $eq->getEqType_name() === 'jeeninswi') {
            $currentEq = $eq;
        }
    }

    foreach ($devices as $device) {
        $deviceId = $device['id'] ?? '';
        $name     = $device['name'] ?? ('Console ' . $deviceId);

        if (empty($deviceId) || !preg_match('/^[a-f0-9]{16}$/i', $deviceId)) {
            log::add('jeeninswi', 'warning', '[persist_devices] device_id invalide ignoré : "' . $deviceId . '"');
            continue;
        }

        $name = substr(strip_tags($name), 0, 64);
        if (empty($name)) {
            $name = 'Console ' . strtoupper(substr($deviceId, 0, 8));
        }

        $existing = eqLogic::byTypeAndSearchConfiguration('jeeninswi', ['device_id' => $deviceId]);

        if (!empty($existing)) {
            foreach ($existing as $eq) {
                log::add('jeeninswi', 'debug', '[persist_devices] device=' . $deviceId . ' existant #' . $eq->getId() . ' → token mis à jour');
                $eq->setConfiguration('nintendo_token', $token);
                $eq->save();
            }
            if (!$firstDeviceDone && $currentEq !== null) {
                foreach ($existing as $eq) {
                    if ($eq->getId() == $currentEqId) { $firstDeviceDone = true; break; }
                }
            }
            continue;
        }

        if (!$firstDeviceDone && $currentEq !== null) {
            log::add('jeeninswi', 'debug', '[persist_devices] première console "' . $name . '" → équipement courant #' . $currentEqId);
            $currentEq->setName($name);
            $currentEq->setIsEnable(1);
            $currentEq->setIsVisible(1);
            $currentEq->setCategory('multimedia', 1);
            $currentEq->setConfiguration('device_id', $deviceId);
            $currentEq->setConfiguration('nintendo_token', $token);
            $currentEq->save();
            $currentEq->postSave();
            $firstDeviceDone = true;
            continue;
        }

        log::add('jeeninswi', 'debug', '[persist_devices] création équipement "' . $name . '" (device_id=' . $deviceId . ')');
        $eqL = new jeeninswi();
        $eqL->setName($name);
        $eqL->setEqType_name('jeeninswi');
        $eqL->setIsEnable(1);
        $eqL->setIsVisible(1);
        $eqL->setCategory('multimedia', 1);
        $eqL->setConfiguration('device_id', $deviceId);
        $eqL->setConfiguration('nintendo_token', $token);
        $eqL->save();
        $eqL->postSave();
        $created[] = $name;
    }

    return $created;
}

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    // SECURITY: token CSRF validé en premier — toute action non listée lève une exception
    // (F-002) exchangeToken retiré de la whitelist : retournait le token Nintendo au navigateur
    ajax::init(['getDeviceStatus', 'sendAction', 'getAuthUrl', 'exchangeAndSaveToken', 'saveTokenAndDevices']);

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    // ── Chemin vers le Python du venv (partagé par getAuthUrl et exchangeToken) ────
    $venv_python = dirname(__FILE__) . '/../../resources/venv/bin/python3';
    $python_bin  = file_exists($venv_python) ? $venv_python : 'python3';

    // ── Statut courant d'un équipement ───────────────────────────────────────────
    if (init('action') == 'getDeviceStatus') {
        log::add('jeeninswi', 'debug', '[ajax] getDeviceStatus — eqLogic_id=' . init('eqLogic_id'));
        // SECURITY: cast en entier + vérification que l'équipement appartient bien à ce plugin
        $eqLogic = eqLogic::byId(intval(init('eqLogic_id')));
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() !== 'jeeninswi') {
            throw new Exception(__('Équipement introuvable', __FILE__));
        }
        $result = [];
        foreach ($eqLogic->getCmd('info') as $cmd) {
            $result[$cmd->getLogicalId()] = $cmd->execCmd();
        }
        log::add('jeeninswi', 'debug', '[ajax] getDeviceStatus — ' . count($result) . ' commande(s) retournées');
        ajax::success($result);
    }

    // ── Exécuter une commande action ─────────────────────────────────────────────
    if (init('action') == 'sendAction') {
        log::add('jeeninswi', 'debug', '[ajax] sendAction — eqLogic_id=' . init('eqLogic_id') . ' | action_name=' . init('action_name'));
        // SECURITY: cast en entier + vérification du plugin
        $eqLogic = eqLogic::byId(intval(init('eqLogic_id')));
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() !== 'jeeninswi') {
            throw new Exception(__('Équipement introuvable', __FILE__));
        }
        // SECURITY: whitelist des logicalIds d'actions autorisées
        $allowed_actions = ['bloquer_maintenant', 'lever_restriction', 'ajouter_temps_15',
                            'ajouter_temps_30', 'ajouter_temps_60', 'definir_limite',
                            'ajouter_temps', 'soustraire_temps', 'mode_alerte', 'mode_blocage',
                            'signaler', 'gamechat_on', 'gamechat_off', 'rafraichir'];
        $action_name = init('action_name');
        if (!in_array($action_name, $allowed_actions, true)) {
            throw new Exception(__('Commande non autorisée', __FILE__));
        }
        $cmd = $eqLogic->getCmd('action', $action_name);
        if (!is_object($cmd)) {
            throw new Exception(__('Commande introuvable : ', __FILE__) . $action_name);
        }
        $cmd->execute();
        log::add('jeeninswi', 'debug', '[ajax] sendAction — exécuté avec succès');
        ajax::success();
    }

    // ── Assistant token — Étape 1 : générer l'URL d'authentification Nintendo ──────
    if (init('action') == 'getAuthUrl') {
        log::add('jeeninswi', 'debug', '[ajax] getAuthUrl — génération URL OAuth Nintendo');
        $helperPath = dirname(__FILE__) . '/../../resources/auth_helper.py';
        $tmpFile    = jeedom::getTmpFolder('jeeninswi') . '/auth_state.json';
        @mkdir(jeedom::getTmpFolder('jeeninswi'), 0755, true);

        // Utiliser le Python du venv pour garantir que pynintendoparental est disponible
        $cmd    = escapeshellarg($python_bin)
                . ' ' . escapeshellarg($helperPath)
                . ' --action get_auth_url'
                . ' --state-file ' . escapeshellarg($tmpFile)
                . ' 2>&1';
        log::add('jeeninswi', 'debug', '[ajax] getAuthUrl — exécution auth_helper.py');
        $output = shell_exec($cmd);
        $data   = json_decode($output, true);

        if (!is_array($data) || !isset($data['auth_url'])) {
            // Ne pas logger $output en entier (peut contenir des chemins sensibles)
            log::add('jeeninswi', 'error', 'getAuthUrl — sortie inattendue (longueur=' . strlen($output ?? '') . ')');
            throw new Exception(__('Impossible de générer l\'URL d\'authentification Nintendo. Vérifiez que les dépendances sont installées.', __FILE__));
        }
        log::add('jeeninswi', 'debug', '[ajax] getAuthUrl — URL générée avec succès');
        ajax::success(['auth_url' => $data['auth_url']]);
    }

    // ── Assistant token — Étape 2+3 fusionnées : échange OAuth + sauvegarde immédiate ──
    // (F-002) Le token Nintendo ne transite JAMAIS par le navigateur JS.
    // Remplace les deux anciens appels séquentiels exchangeToken + saveTokenAndDevices.
    if (init('action') == 'exchangeAndSaveToken') {
        log::add('jeeninswi', 'debug', '[ajax] exchangeAndSaveToken — échange OAuth + création équipements');
        $redirectUrl = init('redirect_url');
        $currentEqId = intval(init('eqLogic_id'));

        if (empty($redirectUrl)) {
            throw new Exception(__('URL de redirection manquante', __FILE__));
        }
        if (!preg_match('/^npf54789befb391a838:\/\/auth#/', $redirectUrl)) {
            log::add('jeeninswi', 'warning', '[ajax] exchangeAndSaveToken — scheme OAuth invalide');
            throw new Exception(__('URL de redirection invalide. Elle doit commencer par npf54789befb391a838://auth#', __FILE__));
        }

        $helperPath = dirname(__FILE__) . '/../../resources/auth_helper.py';
        $tmpFile    = jeedom::getTmpFolder('jeeninswi') . '/auth_state.json';

        $cmd    = escapeshellarg($python_bin)
                . ' ' . escapeshellarg($helperPath)
                . ' --action exchange_token'
                . ' --redirect-url ' . escapeshellarg($redirectUrl)
                . ' --state-file '   . escapeshellarg($tmpFile)
                . ' 2>&1';
        $output = shell_exec($cmd);
        $data   = json_decode($output, true);

        if (!is_array($data) || !isset($data['token'])) {
            log::add('jeeninswi', 'error', 'exchangeAndSaveToken — sortie inattendue (longueur=' . strlen($output ?? '') . ')');
            throw new Exception(__('Échange de token échoué. Vérifiez l\'URL de redirection.', __FILE__));
        }

        $token   = $data['token'];   // reste côté serveur, jamais renvoyé au JS
        $devices = $data['devices'] ?? [];
        log::add('jeeninswi', 'debug', '[ajax] exchangeAndSaveToken — ' . count($devices) . ' console(s) détectée(s) — création en cours');

        $created = _jeeninswi_persist_devices($token, $devices, $currentEqId);
        log::add('jeeninswi', 'debug', '[ajax] exchangeAndSaveToken — terminé : ' . count($created) . ' créé(s)');

        // (F-002) Retourner uniquement la liste des consoles — JAMAIS le token
        ajax::success([
            'created' => $created,
            'devices' => array_map(function ($d) {
                return ['id' => $d['id'] ?? '', 'name' => $d['name'] ?? ''];
            }, $devices),
        ]);
    }

    // ── [DÉPRÉCIÉE] exchangeToken — retournait le token au navigateur JS ────────
    // Conservée dans le code mais RETIRÉE de la whitelist ajax::init() (F-002).
    // Utiliser exchangeAndSaveToken à la place.
    if (init('action') == 'exchangeToken') {
        log::add('jeeninswi', 'warning', '[ajax] exchangeToken — action dépréciée appelée directement');
        throw new Exception(__('Action dépréciée. Utilisez exchangeAndSaveToken.', __FILE__));
    }

    // ── Assistant token — Sauvegarde seule (compatibilité) ───────────────────
    // Conservée pour les appels directs éventuels. Préférer exchangeAndSaveToken.
    if (init('action') == 'saveTokenAndDevices') {
        log::add('jeeninswi', 'debug', '[ajax] saveTokenAndDevices — sauvegarde token et création équipements');
        $token       = init('session_token');
        $devices     = json_decode(init('devices'), true);
        $currentEqId = intval(init('eqLogic_id'));

        if (empty($token)) {
            throw new Exception(__('Token Nintendo manquant', __FILE__));
        }
        if (!is_array($devices)) {
            throw new Exception(__('Liste de consoles invalide', __FILE__));
        }

        $created = _jeeninswi_persist_devices($token, $devices, $currentEqId);
        log::add('jeeninswi', 'debug', '[ajax] saveTokenAndDevices — terminé : ' . count($created) . ' créé(s)');
        ajax::success(['created' => $created]);
    }

    throw new Exception(__('Action inconnue : ', __FILE__) . init('action'));

} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
