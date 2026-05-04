/* jeeninswi.js — Frontend Jeedom */
"use strict";

var NINSWI_AJAX = 'plugins/jeeninswi/core/ajax/jeeninswi.ajax.php';

// ─── Initialisation ──────────────────────────────────────────────────────────
$(document).ready(function () {

    $('#bt_donJeeNinSwi').on('click', function () {
        $('#modal_donJeeNinSwi').modal('show');
    });

    // Ouvrir l'assistant token depuis l'onglet équipement
    $('#bt_openTokenWizard').on('click', function () {
        const eqLogicId = $('.eqLogic .eqLogicAttr[data-l1key="id"]').val() || '';
        $('#md_modal').dialog({ title: 'Assistant Token Nintendo' });
        $('#md_modal').load('index.php?v=d&plugin=jeeninswi&modal=token_setup&eqLogic_id=' + eqLogicId).dialog('open');
    });

    // Surcharge du bouton "Ajouter" : pas de demande de nom, équipement créé directement
    // (plugin.template.js est chargé après ce fichier mais les deux s'exécutent après
    //  le chargement de tous les scripts — la surcharge est donc garantie)
    if (typeof jeeFrontEnd !== 'undefined' && jeeFrontEnd.pluginTemplate) {
        jeeFrontEnd.pluginTemplate.addEqLogic = function () {
            jeedom.eqLogic.save({
                type: eqType,
                eqLogics: [{
                    name: 'Nouvelle Switch',
                    isEnable: '1',
                    isVisible: '1',
                    category: { multimedia: '1' }
                }],
                error: function (error) {
                    jeedomUtils.showAlert({ message: error.message, level: 'danger' });
                },
                success: function (_data) {
                    const vars = getUrlVars();
                    let url = 'index.php?';
                    for (const i in vars) {
                        if (i !== 'id' && i !== 'saveSuccessFull' && i !== 'removeSuccessFull') {
                            url += i + '=' + vars[i].replace('#', '') + '&';
                        }
                    }
                    jeeFrontEnd.modifyWithoutSave = false;
                    modifyWithoutSave = false;
                    url += 'id=' + _data.id + '&saveSuccessFull=1';
                    jeedomUtils.loadPage(url);
                }
            });
        };
    }

    // Charger les valeurs dès que l'onglet Commandes devient visible
    $(document).on('shown.bs.tab', 'a[href="#commandtab"]', function () {
        const eqLogicId = $('.eqLogic .eqLogicAttr[data-l1key="id"]').val();
        if (eqLogicId) jeeninswi.loadCmdValues(eqLogicId);
    });

    // Fallback : après chargement d'un équipement (si l'onglet est déjà actif)
    $(document).on('eqLogicLoaded', function (event, eqLogic) {
        if (!eqLogic || !eqLogic.id) return;
        setTimeout(function () { jeeninswi.loadCmdValues(eqLogic.id); }, 300);
    });
});

// ─── Namespace ───────────────────────────────────────────────────────────────
var jeeninswi = {

    loadCmdValues: function (eqLogicId) {
        if (!eqLogicId) return;
        // Méthode 1 : API core Jeedom — lit la valeur en cache pour chaque commande
        $('#table_cmd .nsw-val-display[data-cmd_id]').each(function () {
            const $span = $(this);
            const cmdId = $span.data('cmd_id');
            if (!cmdId) return;
            jeedom.cmd.getStatCmd({
                id: cmdId,
                success: function (data) {
                    if (!data) return;
                    const v = (data.value !== undefined) ? data.value : (data.state !== undefined ? data.state : null);
                    if (v === null || v === undefined || v === '') { return; }
                    let s = String(v);
                    if (s.length > 80) { s = s.substring(0, 80) + '…'; }
                    $span.text(s).removeClass('label-default').addClass('label-info');
                }
            });
        });
    },
};

// ─── Onglet Commandes ────────────────────────────────────────────────────────
function addCmdToTable(cmdParam) {
    const cmd = (isset(cmdParam) ? cmdParam : {});
    if (!isset(cmd.id)) { cmd.id = ''; }

    const isInfo    = (init(cmd.type) === 'info');
    const isAction  = (init(cmd.type) === 'action');
    const isNumeric = (init(cmd.subType) === 'numeric');

    let tr = '<tr class="cmd" data-cmd_id="' + init(cmd.id) + '">';

    // ── Colonne # ──────────────────────────────────────────────────────────
    tr += '<td style="text-align:center;vertical-align:middle;">';
    tr += '<span class="cmdAttr" data-l1key="id">' + init(cmd.id) + '</span>';
    tr += '</td>';

    // ── Colonne Nom ────────────────────────────────────────────────────────
    tr += '<td style="vertical-align:middle;">';
    tr += '<div class="input-group">';
    tr += '<input class="cmdAttr form-control input-sm" data-l1key="name" placeholder="{{Nom}}">';
    tr += '<span class="input-group-btn">';
    tr += '<a class="btn btn-default btn-sm cmdAction" data-action="selectIcon" title="{{Icône}}">';
    tr += '<i class="fas fa-icons"></i></a>';
    tr += '<span class="cmdAttr" data-l1key="display" data-l2key="icon" style="margin-left:4px;line-height:28px;"></span>';
    tr += '</span>';
    tr += '</div>';
    tr += '<input class="cmdAttr" data-l1key="logicalId" style="display:none;">';
    tr += '<input class="cmdAttr" data-l1key="type" style="display:none;">';
    tr += '<input class="cmdAttr" data-l1key="subType" style="display:none;">';
    tr += '</td>';

    // ── Colonne Valeur actuelle (info uniquement) ──────────────────────────
    tr += '<td style="vertical-align:middle;">';
    if (isInfo) {
        // currentValue peut être vide au premier chargement ; loadCmdValues() le complétera
        let curVal = init(cmd.currentValue) || init(cmd.state) || '—';
        if (curVal.length > 80) { curVal = curVal.substring(0, 80) + '…'; }
        tr += '<span class="nsw-val-display label label-default"'
            + ' data-cmd_id="' + init(cmd.id) + '"'
            + ' data-logical_id="' + init(cmd.logicalId) + '"'
            + ' style="display:inline-block;max-width:100%;word-break:break-all;font-size:11px;font-weight:normal;padding:3px 6px;white-space:normal;">'
            + $('<span>').text(curVal).html()
            + '</span>';
    }
    tr += '</td>';

    // ── Colonne Options ────────────────────────────────────────────────────
    tr += '<td style="vertical-align:middle;">';
    tr += '<label class="checkbox-inline" style="font-size:11px;">';
    tr += '<input type="checkbox" class="cmdAttr" data-l1key="isVisible"> {{Afficher}}';
    tr += '</label>';
    if (isInfo && isNumeric) {
        tr += '<br><label class="checkbox-inline" style="font-size:11px;">';
        tr += '<input type="checkbox" class="cmdAttr" data-l1key="isHistorized"> {{Historiser}}';
        tr += '</label>';
    }
    tr += '</td>';

    // ── Colonne Actions ────────────────────────────────────────────────────
    tr += '<td style="vertical-align:middle;white-space:nowrap;">';
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure" title="{{Configuration avancée}}">';
    tr += '<i class="fas fa-cogs"></i></a>';
    if (isAction) {
        tr += ' <a class="btn btn-default btn-xs cmdAction" data-action="test" title="{{Tester}}">';
        tr += '<i class="fas fa-rss"></i></a>';
    }
    tr += ' <a class="btn btn-default btn-xs cmdAction" data-action="remove" title="{{Supprimer}}">';
    tr += '<i class="fas fa-minus-circle"></i></a>';
    tr += '</td>';

    tr += '</tr>';

    const $tr = $(tr);
    $('#table_cmd tbody').append($tr);
    $tr.setValues(cmd, '.cmdAttr');
}
