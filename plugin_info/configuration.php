<?php
require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect()) {
    include_file('desktop', '404', 'php');
    die();
}
?>
<form class="form-horizontal">
    <fieldset>
        <div class="form-group">
            <label class="col-md-4 control-label">
                {{Port du démon}}
                <i class="fas fa-question-circle tooltips" title="{{Port utilisé par le démon JeeNinSwi. Modifier uniquement en cas de conflit avec un autre plugin. Redémarrez le démon après changement.}}"></i>
            </label>
            <div class="col-md-2">
                <input type="number" class="configKey form-control" data-l1key="socketport"
                       placeholder="8347" min="1024" max="65535"/>
            </div>
        </div>
    </fieldset>
</form>
