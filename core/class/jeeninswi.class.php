<?php
/* Plugin JeeNinSwi - Antoine */

class jeeninswi extends eqLogic {

    /*
     * ==========================================
     * CONSTANTES
     * ==========================================
     */
    const DAEMON_PORT_DEFAULT = 8347;
    const POLL_CRON = '*/5 * * * *'; // 5 minutes par défaut

    public static function getPort() {
        return intval(config::byKey('socketport', __CLASS__, self::DAEMON_PORT_DEFAULT));
    }

    /*
     * ==========================================
     * CYCLE DE VIE DU PLUGIN
     * ==========================================
     */

    public static function dependancy_info() {
        $return = [];
        $return['log']           = log::getPathToLog(__CLASS__ . '_dependancy');
        $return['progress_file'] = jeedom::getTmpFolder(__CLASS__) . '/dependancy';

        if (file_exists($return['progress_file'])) {
            $return['state'] = 'in_progress';
        } else {
            $return['state'] = 'ok';
            // Vérifier que le venv Python existe (resources/venv/bin/python3)
            $venv_python = dirname(__FILE__) . '/../../resources/venv/bin/python3';
            if (!file_exists($venv_python)) {
                log::add(__CLASS__, 'debug', '[dependancy_info] venv introuvable : ' . $venv_python);
                $return['state'] = 'nok';
            } else {
                // Vérifier que pynintendoparental est importable dans le venv
                $exit_code = 0;
                $output    = [];
                exec(escapeshellarg($venv_python) . ' -c "import pynintendoparental" 2>&1', $output, $exit_code);
                if ($exit_code !== 0) {
                    log::add(__CLASS__, 'debug', '[dependancy_info] pynintendoparental absent du venv');
                    $return['state'] = 'nok';
                } else {
                    log::add(__CLASS__, 'debug', '[dependancy_info] venv OK — pynintendoparental présent');
                }
            }
        }
        return $return;
    }

    public static function dependancy_install() {
        log::remove(__CLASS__ . '_dependancy');
        return array(
            'script'  => dirname(__FILE__) . '/../../resources/install_dep.sh ' . jeedom::getTmpFolder(__CLASS__) . '/dependancy',
            'log'     => log::getPathToLog(__CLASS__ . '_dependancy')
        );
    }

    public static function deamon_info() {
        $return = [];
        $return['log']        = __CLASS__;
        $return['state']      = 'nok';
        $return['launchable'] = 'ok';

        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/daemon.pid';
        log::add(__CLASS__, 'debug', '[deamon_info] Vérification PID : ' . $pid_file);
        if (file_exists($pid_file)) {
            $pid = trim(file_get_contents($pid_file));
            log::add(__CLASS__, 'debug', '[deamon_info] PID trouvé : ' . $pid);
            if (!empty($pid) && posix_kill(intval($pid), 0)) {
                $return['state'] = 'ok';
                log::add(__CLASS__, 'debug', '[deamon_info] Processus actif (PID=' . $pid . ')');
            } else {
                log::add(__CLASS__, 'debug', '[deamon_info] PID mort ou vide — suppression du fichier PID');
                unlink($pid_file);
            }
        } else {
            log::add(__CLASS__, 'debug', '[deamon_info] Aucun fichier PID trouvé');
        }
        return $return;
    }

    public static function deamon_start($_debug = false) {
        log::add(__CLASS__, 'debug', '[deamon_start] Démarrage demandé (debug=' . ($_debug ? 'true' : 'false') . ')');
        self::deamon_stop();

        // Construire le mapping token → [device_ids] depuis tous les équipements actifs
        $token_devices_map = [];
        $poll_cron         = self::POLL_CRON;
        foreach (eqLogic::byType(__CLASS__) as $eq) {
            $did   = $eq->getConfiguration('device_id', '');
            $token = $eq->getConfiguration('nintendo_token', '');
            if (!empty($did) && !empty($token)) {
                $token_devices_map[$token][] = $did;
                $poll_cron = $eq->getConfiguration('poll_cron', self::POLL_CRON) ?: $poll_cron;
            }
        }
        $tokens_json = json_encode($token_devices_map);
        log::add(__CLASS__, 'debug', '[deamon_start] ' . count($token_devices_map) . ' compte(s) Nintendo — poll_cron=' . $poll_cron);

        $pid_file     = jeedom::getTmpFolder(__CLASS__) . '/daemon.pid';
        $log_file     = log::getPathToLog(__CLASS__);
        $socket_port  = self::getPort();
        $api_key      = jeedom::getApiKey(__CLASS__);
        $jeedom_port  = config::byKey('port', 'network', 80);
        $jeedom_comp  = config::byKey('urlcomplement', 'network', '');
        $callback_url = 'http://127.0.0.1:' . $jeedom_port . $jeedom_comp . '/plugins/jeeninswi/core/php/callback.php?apikey=' . urlencode($api_key);

        log::add(__CLASS__, 'debug', '[deamon_start] Port=' . $socket_port . ', PID file=' . $pid_file);
        // SECURITY: ne pas loguer la clé API en clair
        log::add(__CLASS__, 'debug', '[deamon_start] Callback URL=http://127.0.0.1:' . $jeedom_port . $jeedom_comp . '/plugins/jeeninswi/core/php/callback.php?apikey=****');

        // Utiliser le Python du venv si disponible, sinon python3 système (fallback)
        $venv_python = dirname(__FILE__) . '/../../resources/venv/bin/python3';
        $python_bin  = file_exists($venv_python) ? $venv_python : 'python3';
        log::add(__CLASS__, 'debug', '[deamon_start] Python utilisé : ' . $python_bin);

        $cmd  = escapeshellarg($python_bin) . ' ' . escapeshellarg(dirname(__FILE__) . '/../../resources/jeeninswid/jeeninswid.py');
        $cmd .= ' --tokens '    . escapeshellarg($tokens_json);
        $cmd .= ' --port '      . $socket_port;
        $cmd .= ' --callback '  . escapeshellarg($callback_url);
        $cmd .= ' --poll-cron ' . escapeshellarg($poll_cron);
        $cmd .= ' --pid-file '  . escapeshellarg($pid_file);
        $cmd .= ' --log-file '  . escapeshellarg($log_file);
        $cmd .= $_debug ? ' --debug' : '';
        $cmd .= ' > /dev/null 2>&1 &';

        // SECURITY: construire une version masquée pour les logs (tokens Nintendo et clé API retirés)
        $cmd_log = escapeshellarg($python_bin) . ' ' . escapeshellarg(dirname(__FILE__) . '/../../resources/jeeninswid/jeeninswid.py')
                 . ' --tokens [MASKED]'
                 . ' --port '      . $socket_port
                 . ' --callback [MASKED]'
                 . ' --poll-cron ' . escapeshellarg($poll_cron)
                 . ' --pid-file '  . escapeshellarg($pid_file)
                 . ' --log-file '  . escapeshellarg($log_file)
                 . ($_debug ? ' --debug' : '');
        log::add(__CLASS__, 'info', 'Démarrage démon : ' . $cmd_log);
        shell_exec($cmd);

        $timeout = 60;
        for ($i = 0; $i < $timeout; $i++) {
            sleep(1);
            log::add(__CLASS__, 'debug', '[deamon_start] Attente démon (' . ($i + 1) . '/' . $timeout . 's)...');
            if (self::deamon_info()['state'] === 'ok') {
                log::add(__CLASS__, 'debug', '[deamon_start] Démon actif après ' . ($i + 1) . 's');
                return true;
            }
        }
        throw new Exception(__('Impossible de démarrer le démon. Vérifiez les logs.', __FILE__));
    }

    public static function deamon_stop() {
        $pid_file = jeedom::getTmpFolder(__CLASS__) . '/daemon.pid';
        log::add(__CLASS__, 'debug', '[deamon_stop] Arrêt demandé — PID file : ' . $pid_file);
        if (file_exists($pid_file)) {
            $pid = intval(trim(file_get_contents($pid_file)));
            log::add(__CLASS__, 'debug', '[deamon_stop] Envoi SIGTERM au PID ' . $pid);
            if ($pid > 0) {
                system::kill($pid);
            }
            unlink($pid_file);
            log::add(__CLASS__, 'debug', '[deamon_stop] PID file supprimé');
        } else {
            log::add(__CLASS__, 'debug', '[deamon_stop] Aucun PID file — démon déjà arrêté ou jamais démarré');
        }
        log::add(__CLASS__, 'debug', '[deamon_stop] Libération du port ' . self::getPort());
        system::fuserk(self::getPort());
    }

    /*
     * ==========================================
     * CALLBACK — reçoit les données du démon
     * ==========================================
     */
    public static function callback($_data) {
        log::add(__CLASS__, 'debug', '[callback] Données reçues — clés: ' . implode(', ', array_keys($_data)));
        if (!isset($_data['device_id'])) {
            log::add(__CLASS__, 'warning', 'Callback sans device_id');
            return;
        }
        log::add(__CLASS__, 'debug', '[callback] device_id=' . $_data['device_id']
            . ' | nickname=' . ($_data['nickname'] ?? '?')
            . ' | playtime_today=' . ($_data['playtime_today'] ?? '?')
            . ' | time_remaining=' . ($_data['time_remaining'] ?? '?')
            . ' | suspended=' . (isset($_data['suspended']) ? ($_data['suspended'] ? 'true' : 'false') : '?'));

        // Trouver l'équipement correspondant (JSON_CONTAINS)
        $eqLogics = eqLogic::byTypeAndSearchConfiguration(__CLASS__, ['device_id' => $_data['device_id']]);
        if (empty($eqLogics)) {
            log::add(__CLASS__, 'warning', 'Aucun équipement trouvé pour device_id: ' . $_data['device_id']);
            return;
        }
        log::add(__CLASS__, 'debug', '[callback] ' . count($eqLogics) . ' équipement(s) trouvé(s) pour device_id=' . $_data['device_id']);

        foreach ($eqLogics as $eqLogic) {
            log::add(__CLASS__, 'debug', '[callback] Mise à jour équipement #' . $eqLogic->getId() . ' (' . $eqLogic->getName() . ')');
            $eqLogic->updateFromData($_data);
        }
    }

    /*
     * ==========================================
     * MISE À JOUR DES COMMANDES
     * ==========================================
     */
    public function updateFromData($_data) {
        log::add(__CLASS__, 'debug', '[updateFromData] Début mise à jour équipement #' . $this->getId() . ' (' . $this->getName() . ')');
        // Profil
        if (isset($_data['nickname'])) {
            log::add(__CLASS__, 'debug', '[updateFromData] pseudo → ' . $_data['nickname']);
            $this->checkAndUpdateCmd('pseudo', $_data['nickname']);
        }

        // Statut
        if (isset($_data['online_status'])) {
            $status_map = ['online' => 'En ligne', 'ingame' => 'En jeu', 'offline' => 'Hors ligne'];
            $this->checkAndUpdateCmd('statut_en_ligne', $status_map[$_data['online_status']] ?? $_data['online_status']);
        }

        // Jeu en cours
        if (isset($_data['current_game'])) {
            $this->checkAndUpdateCmd('jeu_en_cours', $_data['current_game']['name'] ?? '');
            $this->checkAndUpdateCmd('jeu_en_cours_image', $_data['current_game']['image_url'] ?? '');
        }

        // Temps de jeu
        if (isset($_data['playtime_today'])) {
            log::add(__CLASS__, 'debug', '[updateFromData] temps_jour → ' . intval($_data['playtime_today']) . ' min');
            $this->checkAndUpdateCmd('temps_jour', intval($_data['playtime_today']));
        }
        if (isset($_data['playtime_month'])) {
            log::add(__CLASS__, 'debug', '[updateFromData] temps_semaine → ' . intval($_data['playtime_month']) . ' min');
            $this->checkAndUpdateCmd('temps_semaine', intval($_data['playtime_month']));
        }
        if (isset($_data['time_remaining'])) {
            log::add(__CLASS__, 'debug', '[updateFromData] temps_restant → ' . intval($_data['time_remaining']) . ' min');
            $this->checkAndUpdateCmd('temps_restant', intval($_data['time_remaining']));
        }
        if (isset($_data['daily_limit'])) {
            log::add(__CLASS__, 'debug', '[updateFromData] temps_limite → ' . intval($_data['daily_limit']) . ' min');
            $this->checkAndUpdateCmd('temps_limite', intval($_data['daily_limit']));
        }

        // Historique jeux (mois précédent)
        if (isset($_data['game_history'])) {
            $this->checkAndUpdateCmd('historique_jeux', json_encode($_data['game_history']));
        }
        // Historique joueurs (profils Nintendo) — création auto si commande manquante
        if (isset($_data['player_history'])) {
            if (!is_object($this->getCmd('info', 'historique_joueurs'))) {
                $cmd = new jeeninswiCmd();
                $cmd->setEqLogic_id($this->getId());
                $cmd->setLogicalId('historique_joueurs');
                $cmd->setType('info');
                $cmd->setSubType('string');
                $cmd->setName('Historique joueurs');
                $cmd->setIsVisible(1);
                $cmd->save();
                log::add(__CLASS__, 'debug', '[updateFromData] Commande historique_joueurs créée à la volée');
            }
            $this->checkAndUpdateCmd('historique_joueurs', json_encode($_data['player_history']));
        }

        // Stats temps du jour
        if (isset($_data['disabled_today'])) {
            $this->checkAndUpdateCmd('temps_bloque', intval($_data['disabled_today']));
        }
        if (isset($_data['exceeded_today'])) {
            $this->checkAndUpdateCmd('temps_depasse', intval($_data['exceeded_today']));
        }

        // Stats mensuelles
        if (isset($_data['month_days'])) {
            $this->checkAndUpdateCmd('nb_jours_joue', intval($_data['month_days']));
        }
        if (isset($_data['month_avg'])) {
            $this->checkAndUpdateCmd('temps_moyen', intval($_data['month_avg']));
        }

        // Switch 2 — GameChat
        if (isset($_data['gamechat_enabled'])) {
            log::add(__CLASS__, 'debug', '[updateFromData] gamechat_actif → ' . ($_data['gamechat_enabled'] ? '1' : '0'));
            $this->checkAndUpdateCmd('gamechat_actif', $_data['gamechat_enabled'] ? 1 : 0);
        }

        // Restriction active ?
        if (isset($_data['suspended'])) {
            log::add(__CLASS__, 'debug', '[updateFromData] console_bloquee → ' . ($_data['suspended'] ? '1' : '0'));
            $this->checkAndUpdateCmd('console_bloquee', $_data['suspended'] ? 1 : 0);
        }

        // Mode restriction (0=blocage_force, 1=alarme)
        if (isset($_data['restriction_mode'])) {
            log::add(__CLASS__, 'debug', '[updateFromData] mode_restriction → ' . intval($_data['restriction_mode']));
            $this->checkAndUpdateCmd('mode_restriction', intval($_data['restriction_mode']));
        }

        // Timeline 7 jours
        if (isset($_data['daily_summaries'])) {
            log::add(__CLASS__, 'debug', '[updateFromData] timeline_json → ' . count($_data['daily_summaries']) . ' entrées');
            $this->checkAndUpdateCmd('timeline_json', json_encode($_data['daily_summaries']));
        }

        // Dernière synchronisation
        if (isset($_data['last_sync'])) {
            $this->checkAndUpdateCmd('derniere_synchro', $_data['last_sync']);
        }

        log::add(__CLASS__, 'debug', '[updateFromData] Mise à jour terminée pour équipement #' . $this->getId());
    }

    /*
     * ==========================================
     * ENVOI D'ACTION AU DÉMON
     * ==========================================
     */
    public function sendToDaemon($_action, $_params = []) {
        $device_id = $this->getConfiguration('device_id');
        $token     = $this->getConfiguration('nintendo_token', '');
        $hasToken  = !empty($token);
        log::add(__CLASS__, 'debug', '[sendToDaemon] action=' . $_action
            . ' | device_id=' . $device_id
            . ' | token=' . ($hasToken ? '…' . substr($token, -6) : 'ABSENT')
            . ' | params=' . json_encode($_params));

        $payload = array_merge([
            'action'    => $_action,
            'device_id' => $device_id,
            'token'     => $token,
        ], $_params);

        $url  = 'http://127.0.0.1:' . self::getPort() . '/action';
        log::add(__CLASS__, 'debug', '[sendToDaemon] POST ' . $url);
        $opts = [
            'http' => [
                'method'  => 'POST',
                'header'  => 'Content-Type: application/json',
                'content' => json_encode($payload),
                'timeout' => 30,
            ]
        ];
        $result = @file_get_contents($url, false, stream_context_create($opts));
        if ($result === false) {
            log::add(__CLASS__, 'debug', '[sendToDaemon] Échec connexion démon sur port ' . self::getPort());
            throw new Exception(__('Impossible de contacter le démon. Vérifiez qu\'il est démarré.', __FILE__));
        }
        $decoded = json_decode($result, true);
        log::add(__CLASS__, 'debug', '[sendToDaemon] Réponse démon : ' . $result);
        return $decoded;
    }

    /*
     * ==========================================
     * CRÉATION AUTOMATIQUE DES COMMANDES
     * ==========================================
     */
    public function postSave() {
        log::add(__CLASS__, 'debug', '[postSave] Vérification/création des commandes pour équipement #' . $this->getId() . ' (' . $this->getName() . ')');
        // ---- Commandes INFO ----
        $info_cmds = [
            ['pseudo',            'Pseudo',              'string',  'person'],
            ['statut_en_ligne',   'Statut en ligne',     'string',  'network'],
            ['jeu_en_cours',      'Jeu en cours',        'string',  'gamepad'],
            ['jeu_en_cours_image','Jeu image',           'string',  'gamepad'],
            ['temps_jour',        'Temps de jeu (jour)', 'numeric', 'time'],
            ['temps_semaine',     'Temps de jeu (mois)', 'numeric', 'time'],
            ['temps_restant',     'Temps restant',       'numeric', 'time'],
            ['temps_limite',      'Limite quotidienne',  'numeric', 'time'],
            ['historique_jeux',    'Historique jeux',     'string',  'gamepad'],
            ['historique_joueurs', 'Historique joueurs',  'string',  'person'],
            ['timeline_json',      'Timeline 7 jours',    'string',  'history'],
            ['derniere_synchro',   'Dernière synchro',    'string',  'clock'],
            ['mode_restriction',  'Mode restriction',    'binary',  'lock'],
            ['gamechat_actif',    'GameChat actif',      'binary',  'chat'],
            ['console_bloquee',   'Console bloquée',     'binary',  'lock'],
            ['temps_bloque',      'Temps bloqué (jour)', 'numeric', 'time'],
            ['temps_depasse',     'Temps dépassé (jour)','numeric', 'time'],
            ['nb_jours_joue',     'Jours joués (mois)',  'numeric', 'time'],
            ['temps_moyen',       'Temps moyen (mois)',  'numeric', 'time'],
        ];

        $order = 1;
        foreach ($info_cmds as $cmd_def) {
            [$logicalId, $name, $subType] = $cmd_def;
            $cmd = $this->getCmd('info', $logicalId);
            if (!is_object($cmd)) {
                log::add(__CLASS__, 'debug', '[postSave] Création commande INFO : ' . $logicalId . ' (' . $subType . ')');
                $cmd = new jeeninswiCmd();
                $cmd->setEqLogic_id($this->getId());
                $cmd->setLogicalId($logicalId);
                $cmd->setType('info');
                $cmd->setSubType($subType);
                $cmd->setName($name);
                $cmd->setIsVisible(1);
                $cmd->setOrder($order);
                $cmd->save();
            } else {
                log::add(__CLASS__, 'debug', '[postSave] Commande INFO existante : ' . $logicalId);
            }
            $order++;
        }

        // ---- Commandes ACTION ----
        $action_cmds = [
            ['ajouter_temps_15',    'Ajouter 15 min',        'other',   'time'],
            ['ajouter_temps_30',    'Ajouter 30 min',        'other',   'time'],
            ['ajouter_temps_60',    'Ajouter 60 min',        'other',   'time'],
            ['ajouter_temps',       'Ajouter du temps (min)', 'slider',  'time'],
            ['soustraire_temps',    'Soustraire temps (min)', 'slider',  'time'],
            ['definir_limite',      'Definir limite (min)',   'slider',  'time'],
            ['mode_alerte',         'Mode alerte',            'other',   'bell'],
            ['mode_blocage',        'Mode blocage force',     'other',   'lock'],
            ['signaler',            'Signaler',               'other',   'warning'],
            ['bloquer_maintenant',  'Bloquer maintenant',     'other',   'lock'],
            ['lever_restriction',   'Lever la restriction',   'other',   'unlock'],
            ['gamechat_on',         'Activer GameChat',       'other',   'chat'],
            ['gamechat_off',        'Desactiver GameChat',    'other',   'chat'],
            ['rafraichir',          'Rafraichir',             'other',   'refresh'],
        ];

        foreach ($action_cmds as $cmd_def) {
            [$logicalId, $name, $subType] = $cmd_def;
            $cmd = $this->getCmd('action', $logicalId);
            if (!is_object($cmd)) {
                log::add(__CLASS__, 'debug', '[postSave] Création commande ACTION : ' . $logicalId . ' (' . $subType . ')');
                $cmd = new jeeninswiCmd();
                $cmd->setEqLogic_id($this->getId());
                $cmd->setLogicalId($logicalId);
                $cmd->setType('action');
                $cmd->setSubType($subType);
                $cmd->setName($name);
                $cmd->setIsVisible(1);
                $cmd->setOrder($order);
                if ($logicalId === 'definir_limite') {
                    $cmd->setConfiguration('minValue', 0);
                    $cmd->setConfiguration('maxValue', 360);
                }
                if (in_array($logicalId, ['bonus_special', 'ajouter_temps', 'soustraire_temps'])) {
                    $cmd->setConfiguration('minValue', 1);
                    $cmd->setConfiguration('maxValue', 120);
                }
                $cmd->save();
            } else {
                log::add(__CLASS__, 'debug', '[postSave] Commande ACTION existante : ' . $logicalId);
            }
            $order++;
        }
        log::add(__CLASS__, 'debug', '[postSave] Terminé pour équipement #' . $this->getId());
    }

    public static function formatMinutes($minutes) {
        $minutes = intval($minutes);
        if ($minutes <= 0) return '0 min';
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        if ($h > 0) return $h . 'h' . ($m > 0 ? $m : '');
        return $m . ' min';
    }

    public function toHtml($_version = 'dashboard') {
        $replace  = $this->preToHtml($_version);
        if (!is_array($replace)) {
            return $replace;
        }
        $version = jeedom::versionAlias($_version);
        // Infos texte (json_encode pour sécurité JS)
        $replace['#device_name#']    = htmlspecialchars($this->getName());
        $pseudo                      = $this->getCmd('info', 'pseudo')        ? $this->getCmd('info', 'pseudo')->execCmd()        : '';
        $replace['#pseudo#']         = htmlspecialchars($pseudo);
        $replace['#statut_js#']      = json_encode($this->getCmd('info', 'statut_en_ligne')   ? $this->getCmd('info', 'statut_en_ligne')->execCmd()   : '');
        $replace['#pseudo_js#']      = json_encode($pseudo);
        $replace['#jeu_js#']         = json_encode($this->getCmd('info', 'jeu_en_cours')      ? $this->getCmd('info', 'jeu_en_cours')->execCmd()      : '');
        $replace['#jeu_img_js#']     = json_encode($this->getCmd('info', 'jeu_en_cours_image')? $this->getCmd('info', 'jeu_en_cours_image')->execCmd(): '');
        $replace['#gamechat_actif#'] = intval($this->getCmd('info', 'gamechat_actif') ? $this->getCmd('info', 'gamechat_actif')->execCmd() : 0);

        // Historique jeux (JSON — mois précédent)
        $rawHisto  = $this->getCmd('info', 'historique_jeux') ? $this->getCmd('info', 'historique_jeux')->execCmd() : '[]';
        $histoData = json_decode($rawHisto, true) ?: [];
        $replace['#historique_js#'] = json_encode(array_slice($histoData, 0, 5));

        // Historique joueurs (JSON)
        $rawJoueurs  = $this->getCmd('info', 'historique_joueurs') ? $this->getCmd('info', 'historique_joueurs')->execCmd() : '[]';
        $joueursData = json_decode($rawJoueurs, true) ?: [];
        $replace['#joueurs_js#'] = json_encode($joueursData);

        // Temps
        $tempsJour    = $this->getCmd('info', 'temps_jour')    ? intval($this->getCmd('info', 'temps_jour')->execCmd())    : 0;
        $tempsRestant = $this->getCmd('info', 'temps_restant') ? intval($this->getCmd('info', 'temps_restant')->execCmd()) : -1;
        $tempsLimite  = $this->getCmd('info', 'temps_limite')  ? intval($this->getCmd('info', 'temps_limite')->execCmd())  : 0;
        $replace['#temps_jour_fmt#']    = self::formatMinutes($tempsJour);
        $replace['#temps_restant_fmt#'] = $tempsRestant >= 0 ? self::formatMinutes($tempsRestant) : 'Illimitée';
        $replace['#temps_limite#']      = $tempsLimite;
        $replace['#temps_limite_fmt#']  = $tempsLimite > 0 ? self::formatMinutes($tempsLimite) : ($tempsLimite === 0 ? 'Bloquée' : 'Illimitée');

        // Statut binaires
        $replace['#bloquee#']          = intval($this->getCmd('info', 'console_bloquee')  ? $this->getCmd('info', 'console_bloquee')->execCmd()  : 0);
        $replace['#mode_restriction#'] = intval($this->getCmd('info', 'mode_restriction') ? $this->getCmd('info', 'mode_restriction')->execCmd() : 1);

        // Timeline JSON (sécurisé pour JS)
        $rawTimeline  = $this->getCmd('info', 'timeline_json') ? $this->getCmd('info', 'timeline_json')->execCmd() : '[]';
        $timelineData = json_decode($rawTimeline, true) ?: [];
        $replace['#timeline_json#'] = json_encode($timelineData);

        // Dernière synchronisation
        $cmdSync = $this->getCmd('info', 'derniere_synchro');
        $replace['#derniere_synchro#']  = $cmdSync ? htmlspecialchars($cmdSync->execCmd() ?? '—') : '—';
        $replace['#cmd_synchro_id#']   = $cmdSync ? $cmdSync->getId() : '0';

        // ID de l'équipement — indispensable pour les getElementById() du template JS
        $replace['#eqLogic_id#'] = $this->getId();

        // IDs commandes actions pour les boutons du widget
        $cmdIds = [
            'rafraichir'       => 'cmd_refresh_id',
            'ajouter_temps_15' => 'cmd_add15_id',
            'ajouter_temps_30' => 'cmd_add30_id',
            'ajouter_temps_60' => 'cmd_add60_id',
            'bloquer_maintenant' => 'cmd_bloquer_id',
            'lever_restriction'  => 'cmd_lever_id',
            'mode_alerte'        => 'cmd_mode_alerte_id',
            'mode_blocage'     => 'cmd_mode_blocage_id',
            'definir_limite'   => 'cmd_limite_id',
        ];
        foreach ($cmdIds as $logicalId => $key) {
            $cmd = $this->getCmd('action', $logicalId);
            $replace['#' . $key . '#'] = $cmd ? $cmd->getId() : 0;
        }

        return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, __CLASS__, __CLASS__)));
    }
}

class jeeninswiCmd extends cmd {

    public function execute($_options = []) {
        /** @var jeeninswi $eqLogic */
        $eqLogic = $this->getEqLogic();
        log::add('jeeninswi', 'debug', '[cmd::execute] logicalId=' . $this->getLogicalId()
            . ' | eqLogic=#' . $eqLogic->getId() . ' (' . $eqLogic->getName() . ')'
            . ' | options=' . json_encode($_options));

        switch ($this->getLogicalId()) {
            case 'bloquer_maintenant':
                return $eqLogic->sendToDaemon('suspend', ['suspended' => true]);

            case 'lever_restriction':
                return $eqLogic->sendToDaemon('suspend', ['suspended' => false]);

            case 'ajouter_temps_15':
                return $eqLogic->sendToDaemon('add_bonus_time', ['minutes' => 15]);

            case 'ajouter_temps_30':
                return $eqLogic->sendToDaemon('add_bonus_time', ['minutes' => 30]);

            case 'ajouter_temps_60':
                return $eqLogic->sendToDaemon('add_bonus_time', ['minutes' => 60]);

            case 'definir_limite':
                $minutes = intval($_options['slider'] ?? 120);
                return $eqLogic->sendToDaemon('set_daily_limit', ['minutes' => $minutes]);

            case 'ajouter_temps':
            case 'bonus_special':
                $minutes = intval($_options['slider'] ?? 30);
                return $eqLogic->sendToDaemon('add_bonus_time', ['minutes' => $minutes]);

            case 'soustraire_temps':
                $minutes = intval($_options['slider'] ?? 15);
                return $eqLogic->sendToDaemon('subtract_time', ['minutes' => $minutes]);

            case 'mode_alerte':
                return $eqLogic->sendToDaemon('set_restriction_mode', ['mode' => 'alarm']);

            case 'mode_blocage':
                return $eqLogic->sendToDaemon('set_restriction_mode', ['mode' => 'forced']);

            case 'signaler':
                return $eqLogic->sendToDaemon('signaler', []);

            case 'gamechat_on':
                return $eqLogic->sendToDaemon('set_gamechat', ['enabled' => true]);

            case 'gamechat_off':
                return $eqLogic->sendToDaemon('set_gamechat', ['enabled' => false]);

            case 'rafraichir':
                return $eqLogic->sendToDaemon('refresh', []);

            default:
                throw new Exception(__('Commande inconnue : ', __FILE__) . $this->getLogicalId());
        }
    }
}
