<?php
/* This file is part of Jeedom.
 * Plugin JeeNinSwi - Antoine
 */
require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

function jeeninswi_install() {
    // Rien à faire : le polling est géré par le démon, pas par un cron Jeedom
}

function jeeninswi_update() {
    jeeninswi_install();
}

function jeeninswi_remove() {
    // 1. Arrêter le démon si actif
    try {
        jeeninswi::deamon_stop();
    } catch (Exception $e) {
        log::add('jeeninswi', 'warning', 'Erreur arrêt démon lors de la suppression : ' . $e->getMessage());
    }

    // 2. Supprimer le venv Python (peut peser plusieurs centaines de Mo)
    $venv = dirname(__FILE__) . '/../resources/venv';
    if (is_dir($venv)) {
        log::add('jeeninswi', 'info', '[remove] Suppression du venv Python : ' . $venv);
        system('rm -rf ' . escapeshellarg($venv));
    }

    // 3. Supprimer le cache d'images Nintendo (data/jeeninswi/)
    $imgCache = dirname(__FILE__) . '/../../../../data/jeeninswi';
    if (is_dir($imgCache)) {
        log::add('jeeninswi', 'info', '[remove] Suppression du cache images : ' . $imgCache);
        system('rm -rf ' . escapeshellarg($imgCache));
    }

    // 4. Supprimer le fichier d'état OAuth temporaire
    $stateFile = jeedom::getTmpFolder('jeeninswi') . '/auth_state.json';
    if (file_exists($stateFile)) {
        unlink($stateFile);
        log::add('jeeninswi', 'debug', '[remove] Fichier state OAuth supprimé');
    }

    // 5. Supprimer le dossier tmp du plugin
    $tmpFolder = jeedom::getTmpFolder('jeeninswi');
    if (is_dir($tmpFolder)) {
        system('rm -rf ' . escapeshellarg($tmpFolder));
        log::add('jeeninswi', 'debug', '[remove] Dossier tmp supprimé : ' . $tmpFolder);
    }

    log::add('jeeninswi', 'info', '[remove] Désinstallation complète terminée');
}
