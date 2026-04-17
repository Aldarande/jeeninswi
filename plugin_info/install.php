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
    // Arrêter le démon si actif
    try {
        jeeninswi::deamon_stop();
    } catch (Exception $e) {
        log::add('jeeninswi', 'warning', 'Erreur arrêt démon lors de la suppression : ' . $e->getMessage());
    }
}
