<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}

$plugin = plugin::byId('jeeninswi');
if ($plugin === null) {
	throw new Exception('{{Plugin JeeNinSwi non trouvé}}');
}
$eqLogics = eqLogic::byType($plugin->getId());
sendVarToJS('eqType', $plugin->getId());
?>

<div class="row row-overflow">

	<!-- ══════════════════════════════════════════════════════════════
	     PAGE D'ACCUEIL — Liste des équipements
	══════════════════════════════════════════════════════════════════ -->
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>

		<div class="nsw-manage-grid">
			<div class="nsw-manage-card cursor eqLogicAction" data-action="add">
				<i class="fas fa-plus-circle nsw-manage-ico nsw-ico-add"></i>
				<span class="nsw-manage-lbl">{{Ajouter}}</span>
			</div>
			<div class="nsw-manage-card cursor eqLogicAction" data-action="gotoPluginConf">
				<i class="fas fa-wrench nsw-manage-ico"></i>
				<span class="nsw-manage-lbl">{{Configuration}}</span>
			</div>
			<div class="nsw-manage-card cursor" id="bt_donJeeNinSwi" title="{{Faire un don}}">
				<i class="fas fa-mug-hot nsw-manage-ico nsw-ico-don"></i>
				<span class="nsw-manage-lbl">{{Don}}</span>
			</div>
		</div>

		<!-- Modal Don -->
		<div class="modal fade" id="modal_donJeeNinSwi" tabindex="-1" role="dialog">
			<div class="modal-dialog" role="document">
				<div class="modal-content">
					<div class="modal-header" style="background-color:#e4000f;border-radius:5px 5px 0 0;">
						<button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:1;"><span>&times;</span></button>
						<h4 class="modal-title" style="color:#fff;"><i class="fas fa-mug-hot"></i> {{Soutenir JeeNinSwi}}</h4>
					</div>
					<div class="modal-body" style="text-align:center;">
						<p style="font-size:1.1em;">{{Ce plugin est gratuit et open-source.}}<br>{{Si vous l'appréciez, et que vous voulez me remercier, offrez moi un café !}}<br><small>{{Ces dons participent au maintien et au développement du plugin.}}</small></p>
						<hr>
						<a href="https://ko-fi.com/aldarande" target="_blank" class="btn btn-warning btn-lg" style="margin:8px;">
							<i class="fas fa-coffee"></i> Ko-fi
						</a>
						<a href="https://github.com/sponsors/Aldarande" target="_blank" class="btn btn-default btn-lg" style="margin:8px;">
							<i class="fab fa-github"></i> {{GitHub Sponsors}}
						</a>
					</div>
				</div>
			</div>
		</div>

		<legend><i class="fas fa-gamepad"></i> {{Mes consoles supervisées}}</legend>

		<?php if (count($eqLogics) == 0) : ?>
			<br>
			<div class="text-center" style="font-size:1.2em;font-weight:bold;">
				{{Aucune console trouvee. Cliquez sur "Ajouter" pour commencer.}}
			</div>
		<?php else : ?>
			<div class="input-group" style="margin:5px;">
				<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">
				<div class="input-group-btn">
					<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>
					<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>
				</div>
			</div>
			<div class="eqLogicThumbnailContainer">
				<?php foreach ($eqLogics as $eqLogic) : ?>
					<?php $opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard'; ?>
					<div class="eqLogicDisplayCard cursor <?php echo $opacity; ?>" data-eqLogic_id="<?php echo $eqLogic->getId(); ?>">
						<img src="<?php echo $eqLogic->getImage(); ?>"/>
						<br>
						<span class="name"><?php echo $eqLogic->getHumanName(true, true); ?></span>
						<span class="hiddenAsCard displayTableRight hidden">
							<?php if ($eqLogic->getIsVisible() == 1) : ?>
								<i class="fas fa-eye" title="{{Equipement visible}}"></i>
							<?php else : ?>
								<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>
							<?php endif; ?>
						</span>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div><!-- /.eqLogicThumbnailDisplay -->


	<!-- ══════════════════════════════════════════════════════════════
	     PAGE DE CONFIGURATION D'UN ÉQUIPEMENT
	══════════════════════════════════════════════════════════════════ -->
	<div class="col-xs-12 eqLogic" style="display:none;">

		<!-- Barre de boutons -->
		<div class="input-group pull-right" style="display:inline-flex;">
			<span class="input-group-btn">
				<a class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span>
				</a><a class="btn btn-sm btn-default eqLogicAction" data-action="copy"><i class="fas fa-copy"></i><span class="hidden-xs"> {{Dupliquer}}</span>
				</a><a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}
				</a><a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}
				</a>
			</span>
		</div>

		<!-- Onglets -->
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation">
				<a href="#" class="eqLogicAction" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay">
					<i class="fas fa-arrow-circle-left"></i>
				</a>
			</li>
			<li role="presentation" class="active">
				<a href="#eqlogictab" role="tab" data-toggle="tab">
					<i class="fas fa-tachometer-alt"></i> {{Equipement}}
				</a>
			</li>
			<li role="presentation">
				<a href="#commandtab" role="tab" data-toggle="tab">
					<i class="fas fa-list"></i> {{Commandes}}
				</a>
			</li>
		</ul>

		<div class="tab-content">

			<!-- ── Onglet Equipement ─────────────────────────────────────── -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<form class="form-horizontal">
					<fieldset>

						<!-- Colonne gauche : paramètres généraux -->
						<div class="col-lg-6">
							<legend><i class="fas fa-wrench"></i> {{Paramètres généraux}}</legend>

							<div class="form-group">
								<label class="col-sm-4 control-label">{{Nom de la console}}</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Ex : Switch des enfants}}">
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-4 control-label">{{Objet parent}}</label>
								<div class="col-sm-6">
									<select class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php foreach (jeeObject::buildTree(null, false) as $object) : ?>
											<option value="<?php echo $object->getId(); ?>">
												<?php echo str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')); ?>
												<?php echo $object->getName(); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-4 control-label">{{Catégorie}}</label>
								<div class="col-sm-6">
									<?php foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) : ?>
										<label class="checkbox-inline">
											<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="<?php echo $key; ?>">
											<?php echo $value['name']; ?>
										</label>
									<?php endforeach; ?>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-4 control-label">{{Options}}</label>
								<div class="col-sm-6">
									<label class="checkbox-inline">
										<input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked> {{Activer}}
									</label>
									<label class="checkbox-inline">
										<input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked> {{Visible}}
									</label>
								</div>
							</div>
						</div><!-- /col-lg-6 gauche -->

						<!-- Colonne droite : paramètres spécifiques Nintendo -->
						<div class="col-lg-6">
							<legend><i class="fas fa-gamepad"></i> {{Paramètres Nintendo}}</legend>

							<div class="form-group">
								<label class="col-sm-5 control-label">
									{{Token Nintendo}}
									<i class="fas fa-question-circle tooltips" title="{{Token de session Nintendo. Utilisez l'assistant ci-dessous pour le récupérer.}}"></i>
								</label>
								<div class="col-sm-6">
									<input type="password" class="eqLogicAttr form-control"
										data-l1key="configuration" data-l2key="nintendo_token"
										placeholder="{{Utilisez l'assistant token}}">
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-5 control-label">
									{{Device ID Nintendo}}
									<i class="fas fa-question-circle tooltips" title="{{Identifiant unique de la console. Récupéré automatiquement via l'assistant.}}"></i>
								</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control"
										data-l1key="configuration" data-l2key="device_id"
										placeholder="{{Ex : 1234567890ABCDEF}}">
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-5 control-label">
									{{Nintendo Switch 2}}
									<i class="fas fa-question-circle tooltips" title="{{Activez pour une Switch 2. Active les commandes GameChat.}}"></i>
								</label>
								<div class="col-sm-6">
									<label class="checkbox-inline">
										<input type="checkbox" class="eqLogicAttr"
											data-l1key="configuration" data-l2key="is_switch2">
										{{Switch 2}}
									</label>
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-5 control-label">
									{{Planification}}
									<i class="fas fa-question-circle tooltips" title="{{Expression cron. Ex : */5 * * * * = toutes les 5 minutes.}}"></i>
								</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control"
										data-l1key="configuration" data-l2key="poll_cron"
										placeholder="*/5 * * * *">
								</div>
							</div>

							<div class="form-group">
								<label class="col-sm-5 control-label">
									{{Seuil d'alerte (min)}}
									<i class="fas fa-question-circle tooltips" title="{{La commande 'Seuil d'alerte atteint' passe a 1 quand le temps restant est inferieur ou egal a cette valeur. 0 = desactive. Defaut : 15 minutes.}}"></i>
								</label>
								<div class="col-sm-6">
									<input type="number" min="0" max="360" class="eqLogicAttr form-control"
										data-l1key="configuration" data-l2key="alert_threshold_minutes"
										placeholder="15">
								</div>
							</div>

							<div class="form-group" style="margin-top:20px;">
								<div class="col-sm-offset-1 col-sm-10">
									<a class="btn btn-warning btn-block" id="bt_openTokenWizard">
										<i class="fas fa-key"></i> {{Assistant token Nintendo}}
									</a>
								</div>
							</div>
						</div><!-- /col-lg-6 droite -->

					</fieldset>
				</form>
			</div><!-- /.tabpanel #eqlogictab -->

			<!-- ── Onglet Commandes ──────────────────────────────────────── -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br>
				<div class="table-responsive">
					<table id="table_cmd" class="table table-bordered table-condensed" style="table-layout:fixed;width:100%;">
						<colgroup>
							<col style="width:40px;">
							<col style="width:200px;">
							<col>
							<col style="width:130px;">
							<col style="width:90px;">
						</colgroup>
						<thead>
							<tr>
								<th>{{#}}</th>
								<th>{{Nom}}</th>
								<th>{{Valeur actuelle}}</th>
								<th>{{Options}}</th>
								<th>{{Actions}}</th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</div><!-- /.tabpanel #commandtab -->

		</div><!-- /.tab-content -->
	</div><!-- /.eqLogic -->

</div><!-- /.row row-overflow -->

<?php include_file('desktop', 'jeeninswi', 'css', 'jeeninswi'); ?>
<?php include_file('desktop', 'jeeninswi', 'js', 'jeeninswi'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
