<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
$current_eqLogic_id = intval(init('eqLogic_id'));
// Uniquement les chaînes utilisées dans le JS (json_encode pour sécurité des apostrophes)
$js_err_redirect  = json_encode(__('Veuillez coller l\'URL de redirection npf://...', __FILE__));
$js_err_comm      = json_encode(__('Erreur de communication avec le serveur', __FILE__));
$js_msg_saved     = json_encode(__('Configuration sauvegardée !', __FILE__));
$js_msg_no_device = json_encode(__('Aucune console trouvée. Vérifiez que votre compte Nintendo est lié au Contrôle Parental.', __FILE__));
$js_spinner       = json_encode('<i class="fas fa-spinner fa-spin"></i>');
?>
<div class="modal-dialog modal-lg">
    <div class="modal-content">
        <div class="modal-header" style="background:#e4000f;">
            <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:1;">
                <span aria-hidden="true">&times;</span>
            </button>
            <h4 class="modal-title" style="color:#fff;">
                <i class="fas fa-key"></i> {{Assistant de configuration — Token Nintendo}}
            </h4>
        </div>

        <div class="modal-body">

            <!-- Étape 1 -->
            <div id="ninswi-step-1" class="ninswi-wizard-step">
                <span class="badge" style="background:#e4000f;color:#fff;font-size:16px;min-width:26px;padding:4px 7px;border-radius:50%;">1</span>
                <strong style="margin-left:8px;font-size:16px;">{{Démarrer l'authentification Nintendo}}</strong>
                <br><br>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    {{Cliquez sur "Générer l'URL de connexion" ci-dessous. Vous devrez vous connecter avec votre compte Nintendo parent/superviseur dans votre navigateur.}}
                </div>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    {{Cette procédure utilise la même méthode OAuth que l'application officielle Nintendo Switch Parental Controls.}}
                </div>
                <div class="text-right">
                    <button class="btn btn-primary" id="ninswi-btn-step1-next">
                        {{Générer l'URL de connexion}} <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            </div>

            <!-- Étape 2 -->
            <div id="ninswi-step-2" class="ninswi-wizard-step" style="display:none;">
                <span class="badge" style="background:#e4000f;color:#fff;font-size:16px;min-width:26px;padding:4px 7px;border-radius:50%;">2</span>
                <strong style="margin-left:8px;font-size:16px;">{{Connexion Nintendo}}</strong>
                <br><br>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    {{Ouvrez l'URL ci-dessous dans votre navigateur (pas le navigateur de debug). Connectez-vous avec votre compte Nintendo parent, puis selectionnez votre compte. Le navigateur ne redirigera pas : faites un clic droit sur le bouton "Selectionner" → "Copier l'adresse du lien", et collez cette URL ci-dessous.}}
                </div>
                <div class="form-group">
                    <label>{{URL de connexion Nintendo :}}</label>
                    <div style="display:flex;gap:4px;align-items:stretch;">
                        <input type="text" id="ninswi-auth-url" class="form-control" readonly
                               placeholder="{{Generation en cours...}}" style="flex:1;"/>
                        <button class="btn btn-default" id="ninswi-btn-open-url" title="{{Ouvrir dans le navigateur}}" style="flex-shrink:0;">
                            <i class="fas fa-external-link-alt"></i>
                        </button>
                        <button class="btn btn-default" id="ninswi-btn-copy-url" title="{{Copier l'URL}}" style="flex-shrink:0;">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                </div>
                <div class="form-group">
                    <label>{{URL copiee depuis le bouton "Selectionner" (commence par npf54789befb391a838://auth#...) :}}</label>
                    <input type="text" id="ninswi-redirect-url" class="form-control"
                           placeholder="npf54789befb391a838://auth#session_token_code=..."/>
                </div>
                <div class="text-right">
                    <button class="btn btn-default" id="ninswi-btn-step2-back">
                        <i class="fas fa-arrow-left"></i> {{Retour}}
                    </button>
                    <button class="btn btn-primary" id="ninswi-btn-step2-next">
                        {{Valider}} <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            </div>

            <!-- Étape 3 — Confirmation (F-002: le token reste serveur, cette étape affiche le résultat) -->
            <div id="ninswi-step-3" class="ninswi-wizard-step" style="display:none;">
                <span class="badge" style="background:#28a745;color:#fff;font-size:16px;min-width:26px;padding:4px 7px;border-radius:50%;">3</span>
                <strong style="margin-left:8px;font-size:16px;">{{Equipements configures}}</strong>
                <br><br>
                <div id="ninswi-confirm-list"></div>
                <div class="text-right" style="margin-top:15px;">
                    <button class="btn btn-success" id="ninswi-btn-done">
                        <i class="fas fa-check"></i> {{Terminer}}
                    </button>
                </div>
            </div>

        </div><!-- /modal-body -->

        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">{{Fermer}}</button>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    // Toutes les chaines JS viennent du PHP via json_encode (securite apostrophes)
    var MSG_ERR_REDIRECT  = <?php echo $js_err_redirect; ?>;
    var MSG_ERR_COMM      = <?php echo $js_err_comm; ?>;
    var MSG_SAVED         = <?php echo $js_msg_saved; ?>;
    var MSG_NO_DEVICE     = <?php echo $js_msg_no_device; ?>;
    var SPINNER           = <?php echo $js_spinner; ?>;

    var authUrl      = '';
    // (F-002) sessionToken supprimé : le token ne transite plus par le navigateur
    var AJAX_URL     = 'plugins/jeeninswi/core/ajax/jeeninswi.ajax.php';
    var CURRENT_EQ_ID = <?php echo $current_eqLogic_id; ?>;

    function showStep(n) {
        $('.ninswi-wizard-step').hide();
        $('#ninswi-step-' + n).show();
    }

    function btnStart($btn) {
        var orig = $btn.data('orig-html') || $btn.html();
        $btn.data('orig-html', orig).prop('disabled', true).html(SPINNER);
        return orig;
    }
    function btnRestore($btn) {
        $btn.prop('disabled', false).html($btn.data('orig-html') || '');
    }

    function doRedirect() {
        try { $('#md_modal').dialog('close'); } catch(e) {}
        var vars = getUrlVars();
        var url = 'index.php?';
        for (var i in vars) {
            if (i !== 'id' && i !== 'saveSuccessFull' && i !== 'removeSuccessFull') {
                url += i + '=' + vars[i].replace('#', '') + '&';
            }
        }
        jeedomUtils.loadPage(url);
    }

    // Etape 1 -> 2
    $('#ninswi-btn-step1-next').on('click', function() {
        var $btn = $(this);
        btnStart($btn);
        $.ajax({
            type: 'POST',
            url: AJAX_URL,
            data: { action: 'getAuthUrl' },
            dataType: 'json',
            success: function(data) {
                btnRestore($btn);
                if (data.state !== 'ok') {
                    $('#div_alert').showAlert({ message: data.result, level: 'danger' });
                    return;
                }
                authUrl = data.result.auth_url;
                $('#ninswi-auth-url').val(authUrl);
                showStep(2);
            },
            error: function() {
                btnRestore($btn);
                $('#div_alert').showAlert({ message: MSG_ERR_COMM, level: 'danger' });
            }
        });
    });

    $('#ninswi-btn-open-url').on('click', function() {
        if (authUrl) { window.open(authUrl, '_blank'); }
    });

    $('#ninswi-btn-copy-url').on('click', function() {
        if (!authUrl) { return; }
        var $i = $(this).find('i');
        navigator.clipboard.writeText(authUrl).then(function() {
            $i.removeClass('fa-copy').addClass('fa-check');
            setTimeout(function() { $i.removeClass('fa-check').addClass('fa-copy'); }, 2000);
        });
    });

    $('#ninswi-btn-step2-back').on('click', function() { showStep(1); });

    // (F-002) Etape 2 -> 3 : appel unique exchangeAndSaveToken — token reste côté serveur
    $('#ninswi-btn-step2-next').on('click', function() {
        var redirectUrl = $('#ninswi-redirect-url').val().trim();
        if (!redirectUrl) {
            $('#div_alert').showAlert({ message: MSG_ERR_REDIRECT, level: 'warning' });
            return;
        }
        var $btn = $(this);
        btnStart($btn);
        $.ajax({
            type: 'POST',
            url: AJAX_URL,
            data: {
                action: 'exchangeAndSaveToken',
                redirect_url: redirectUrl,
                eqLogic_id: CURRENT_EQ_ID
            },
            dataType: 'json',
            success: function(data) {
                btnRestore($btn);
                if (data.state !== 'ok') {
                    $('#div_alert').showAlert({ message: data.result, level: 'danger' });
                    return;
                }
                // (F-002) Le serveur retourne les consoles créées — jamais le token
                renderConfirmation(data.result.devices || []);
                showStep(3);
            },
            error: function() {
                btnRestore($btn);
                $('#div_alert').showAlert({ message: MSG_ERR_COMM, level: 'danger' });
            }
        });
    });

    // (F-002) Etape 3 : affichage de confirmation uniquement (plus de selection ni sauvegarde JS)
    function renderConfirmation(devices) {
        var $c = $('#ninswi-confirm-list').empty();
        if (!devices || !devices.length) {
            $c.html('<div class="alert alert-warning">' + MSG_NO_DEVICE + '</div>');
            return;
        }
        var html = '<div class="alert alert-success"><i class="fas fa-check-circle"></i>&nbsp;'
                 + devices.length + ' console(s) configurée(s) avec succès :</div>';
        $.each(devices, function(i, device) {
            var safeName = $('<span>').text(device.name || ('Console ' + (i + 1))).html();
            var safeId   = $('<span>').text(device.id || '').html();
            html += '<div class="panel panel-success" style="margin-bottom:8px;">'
                  + '<div class="panel-body" style="display:flex;align-items:center;gap:12px;">'
                  + '<i class="fas fa-check-circle fa-lg" style="color:#28a745;"></i>'
                  + '<i class="fas fa-gamepad fa-lg" style="color:#e4000f;"></i>'
                  + '<div><strong>' + safeName + '</strong>'
                  + (safeId ? '<br><small class="text-muted">' + safeId + '</small>' : '')
                  + '</div></div></div>';
        });
        $c.html(html);
    }

    $('#ninswi-btn-done').on('click', function() {
        $('#div_alert').showAlert({ message: MSG_SAVED, level: 'success' });
        setTimeout(doRedirect, 800);
    });

})();
</script>
