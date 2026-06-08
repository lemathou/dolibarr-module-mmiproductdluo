/* Copyright (C) 2026 Mathieu Moulin <mathieu@iprospective.fr>
 *
 * Bascule rapide de l'extrafield antigaspi_autoremove_disabled d'un lot.
 *
 * Configuration attendue dans window.mmiAntigaspiConf :
 *   { ajaxurl, token, fkproduct (optionnel), labels:{on, off} }
 *
 * - State 1 = sortie auto anti-gaspi DESACTIVEE (lot protege).
 * - State 0 = sortie auto anti-gaspi ACTIVE (comportement par defaut).
 */
(function () {
	'use strict';

	var conf = window.mmiAntigaspiConf || {};
	var labels = conf.labels || { on: 'Sortie auto anti-gaspi desactivee (lot protege). Cliquer pour reactiver.', off: 'Sortie auto anti-gaspi active. Cliquer pour la desactiver (proteger le lot).' };

	function applyState($btn, state) {
		state = parseInt(state, 10) ? 1 : 0;
		$btn.attr('data-state', state);
		$btn.removeClass('mmi-antigaspi-on mmi-antigaspi-off');
		$btn.addClass(state ? 'mmi-antigaspi-on' : 'mmi-antigaspi-off');
		$btn.attr('title', state ? labels.on : labels.off);
		$btn.empty().append($('<span>').addClass('fa ' + (state ? 'fa-lock' : 'fa-unlock-alt')));
	}

	function buildButton(lotid, state) {
		var $btn = $('<a>')
			.attr('href', '#')
			.addClass('mmi-antigaspi-toggle')
			.attr('data-lotid', lotid);
		applyState($btn, state);
		return $btn;
	}

	// Click handler (delegated : marche pour les boutons rendus serveur ET injectes en JS)
	$(document).on('click', '.mmi-antigaspi-toggle', function (e) {
		e.preventDefault();
		var $btn = $(this);
		if ($btn.hasClass('mmi-loading')) {
			return;
		}
		var lotid = $btn.attr('data-lotid');
		if (!lotid) {
			return;
		}
		$btn.addClass('mmi-loading');
		$.ajax({
			url: conf.ajaxurl,
			type: 'POST',
			dataType: 'json',
			data: { action: 'toggle', lotid: lotid, token: conf.token }
		}).done(function (d) {
			$btn.removeClass('mmi-loading');
			if (d && d.success) {
				applyState($btn, d.state);
			} else {
				alert((d && d.error) ? d.error : 'Erreur');
			}
		}).fail(function () {
			$btn.removeClass('mmi-loading');
			alert('Erreur reseau');
		});
	});

	// Onglet stock de la fiche produit : pas de hook par ligne -> injection JS.
	$(function () {
		if (!conf.fkproduct) {
			return;
		}
		$.ajax({
			url: conf.ajaxurl,
			type: 'GET',
			dataType: 'json',
			data: { action: 'getstate', fk_product: conf.fkproduct }
		}).done(function (d) {
			if (!d || !d.success) {
				return;
			}
			var states = d.states || {};
			$('tr[class*="batch_warehouse"]').each(function () {
				var $row = $(this);
				if ($row.find('.mmi-antigaspi-toggle').length) {
					return; // deja injecte
				}
				var href = $row.find('a[href*="productlot_card.php"]').attr('href');
				if (!href) {
					return;
				}
				var m = href.match(/[?&]id=(\d+)/);
				if (!m) {
					return;
				}
				var lotid = m[1];
				var state = states[lotid] ? 1 : 0;
				var $cell = $row.find('a[href*="productlot_card.php"]').closest('td');
				$cell.append(document.createTextNode(' ')).append(buildButton(lotid, state));
			});
		});
	});
})();
