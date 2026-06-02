<?php
/**
 * JeeNinSwi - Endpoints AJAX
 * Tous les appels JavaScript du plugin passent par ce fichier.
 * L'authentification est gérée par ajax::init() qui vérifie le token de session Jeedom.
 *
 * Actions disponibles :
 *   - getDeviceStatus    : valeurs courantes des commandes d'un équipement
 *   - sendAction         : exécution d'une commande action sur un équipement
 *   - getAuthUrl         : génère l'URL OAuth Nintendo (étape 1 de l'assistant token)
 *   - exchangeToken      : échange l'URL de redirection contre un token + liste consoles
//  *   - saveTokenAndDevices: sauvegarde le token et crée/met à jour les équipements
 */
try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    // SECURITY: token CSRF validé en premier — toute action non listée lève une exception
    ajax::init(['getDeviceStatus', 'sendAction', 'getAuthUrl', 'exchangeToken', 'saveTokenAndDevices']);

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

    // ── Assistant token — Étape 2 : échanger l'URL de redirection ──────────────
    if (init('action') == 'exchangeToken') {
        log::add('jeeninswi', 'debug', '[ajax] exchangeToken — échange du code OAuth');
        $redirectUrl = init('redirect_url');

        if (empty($redirectUrl)) {
            throw new Exception(__('URL de redirection manquante', __FILE__));
        }

        // Sécurité : valider que l'URL de redirection correspond au scheme Nintendo attendu.
        // Le scheme officiel est npf54789befb391a838://auth# (source : nxapi / client_id Nintendo)
        if (!preg_match('/^npf54789befb391a838:\/\/auth#/', $redirectUrl)) {
            log::add('jeeninswi', 'warning', '[ajax] exchangeToken — URL de redirection invalide (scheme incorrect)');
            throw new Exception(__('URL de redirection invalide. Elle doit commencer par npf54789befb391a838://auth#', __FILE__));
        }

        $helperPath = dirname(__FILE__) . '/../../resources/auth_helper.py';
        $tmpFile    = jeedom::getTmpFolder('jeeninswi') . '/auth_state.json';

        $cmd    = escapeshellarg($python_bin)
                . ' ' . escapeshellarg($helperPath)
                . ' --action exchange_token'
                . ' --redirect-url ' . escapeshellarg($redirectUrl)
                . ' --state-file ' . escapeshellarg($tmpFile)
                . ' 2>&1';
        log::add('jeeninswi', 'debug', '[ajax] exchangeToken — exécution auth_helper.py');
        $output = shell_exec($cmd);
        $data   = json_decode($output, true);

        if (!is_array($data) || !isset($data['token'])) {
            log::add('jeeninswi', 'error', 'exchangeToken — sortie inattendue (longueur=' . strlen($output ?? '') . ')');
            throw new Exception(__('Échange de token échoué. Vérifiez l\'URL de redirection.', __FILE__));
        }
        log::add('jeeninswi', 'debug', '[ajax] exchangeToken — token obtenu, ' . count($data['devices'] ?? []) . ' console(s) trouvée(s)');
        ajax::success([
            'token'   => $data['token'],
            'devices' => $data['devices'] ?? [],
        ]);
    }

    // ── Assistant token — Étape 3 : sauvegarder et créer les équipements ──────
    if (init('action') == 'saveTokenAndDevices') {
        log::add('jeeninswi', 'debug', '[ajax] saveTokenAndDevices — sauvegarde token et création équipements');
        $token            = init('session_token');   // Clé 'session_token' pour éviter toute confusion avec le token AJAX Jeedom
        $devices          = json_decode(init('devices'), true);
        $currentEqId      = intval(init('eqLogic_id'));
        $firstDeviceDone  = false;

        if (empty($token)) {
            throw new Exception(__('Token Nintendo manquant', __FILE__));
        }
        if (!is_array($devices)) {
            throw new Exception(__('Liste de consoles invalide', __FILE__));
        }

        // Vérifier que l'équipement courant existe et appartient bien au plugin
        $currentEq = null;
        if ($currentEqId > 0) {
            $eq = eqLogic::byId($currentEqId);
            if (is_object($eq) && $eq->getEqType_name() === 'jeeninswi') {
                $currentEq = $eq;
            }
        }

        log::add('jeeninswi', 'debug', '[ajax] saveTokenAndDevices — ' . count($devices) . ' console(s) à traiter, eqLogic_id=' . $currentEqId);
        $created = [];

        foreach ($devices as $device) {
            $deviceId = $device['id'] ?? '';
            $name     = $device['name'] ?? ('Console ' . $deviceId);

            // Sécurité : valider le format du device_id (16 caractères hexadécimaux)
            if (empty($deviceId) || !preg_match('/^[a-f0-9]{16}$/i', $deviceId)) {
                log::add('jeeninswi', 'warning', '[ajax] saveTokenAndDevices — device_id invalide ignoré : "' . $deviceId . '"');
                continue;
            }

            // Sécurité : sanitiser le nom (max 64 caractères, pas de balises HTML)
            $name = substr(strip_tags($name), 0, 64);
            if (empty($name)) {
                $name = 'Console ' . strtoupper(substr($deviceId, 0, 8));
            }

            // Déduplication : chercher si un équipement existe déjà pour ce device_id
            $existing = eqLogic::byTypeAndSearchConfiguration('jeeninswi', ['device_id' => $deviceId]);

            if (!empty($existing)) {
                // Équipement existant : mise à jour du token uniquement — le nom Jeedom est conservé
                foreach ($existing as $eq) {
                    log::add('jeeninswi', 'debug',
                        '[ajax] saveTokenAndDevices — device_id=' . $deviceId
                        . ' déjà présent (#' . $eq->getId() . ' "' . $eq->getName() . '") → token mis à jour'
                    );
                    $eq->setConfiguration('nintendo_token', $token);
                    $eq->save();
                }
                // Si c'est la première console et qu'elle correspond à l'équipement courant, on le marque comme traité
                if (!$firstDeviceDone && $currentEq !== null) {
                    foreach ($existing as $eq) {
                        if ($eq->getId() == $currentEqId) { $firstDeviceDone = true; break; }
                    }
                }
                continue;
            }

            // Première console sélectionnée → renseigner l'équipement courant (déjà créé vide)
            if (!$firstDeviceDone && $currentEq !== null) {
                log::add('jeeninswi', 'debug',
                    '[ajax] saveTokenAndDevices — première console "' . $name
                    . '" → mise à jour équipement courant #' . $currentEqId
                );
                $currentEq->setName($name);
                $currentEq->setIsEnable(1);
                $currentEq->setIsVisible(1);
                $currentEq->setCategory('multimedia', 1);
                $currentEq->setConfiguration('device_id', $deviceId);
                $currentEq->setConfiguration('nintendo_token', $token);
                $currentEq->save();
                $currentEq->postSave(); // Forcer la création des commandes (postSave non garanti sur update)
                $firstDeviceDone = true;
                continue;
            }

            // Consoles supplémentaires → créer un nouvel équipement
            log::add('jeeninswi', 'debug', '[ajax] saveTokenAndDevices — création équipement "' . $name . '" (device_id=' . $deviceId . ')');
            $eqLogic = new jeeninswi();
            $eqLogic->setName($name);
            $eqLogic->setEqType_name('jeeninswi');
            $eqLogic->setIsEnable(1);
            $eqLogic->setIsVisible(1);
            $eqLogic->setCategory('multimedia', 1);
            $eqLogic->setConfiguration('device_id', $deviceId);
            $eqLogic->setConfiguration('nintendo_token', $token);
            $eqLogic->save();
            $eqLogic->postSave(); // Forcer la création des 19 commandes (info + action)
            $created[] = $name;
        }

        log::add('jeeninswi', 'debug', '[ajax] saveTokenAndDevices — terminé : ' . count($created) . ' créé(s)');
        ajax::success(['created' => $created]);
    }

    throw new Exception(__('Action inconnue : ', __FILE__) . init('action'));

} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
