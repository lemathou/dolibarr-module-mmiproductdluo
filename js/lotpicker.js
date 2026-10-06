/* RM3040 — choix du lot à la CAISSE (écran de commande, lecteur code-barres).
 *
 * L'écran de commande sert de caisse. La règle qui gouverne tout ce fichier :
 * **ne rien demander quand il n'y a rien à décider**. Un produit qui n'a qu'un lot en
 * stock ne doit provoquer aucun clic, aucune fenêtre, aucun temps mort ; seul un produit
 * à plusieurs lots interrompt l'opérateur, et le fait au clavier, sans souris.
 *
 * Le serveur décide (mode none|auto|choose) — ce fichier ne fait qu'obéir et afficher.
 * Voir class/mmiproductdluo_lotpicker.class.php et ajax/lots_for_product.php.
 */
(function () {
	"use strict";

	var CONF = window.mmiLotPicker || {};
	var URL = CONF.url || "";
	var L = CONF.langs || {};

	/**
	 * La zone d'extrafields de la ligne sur laquelle on travaille.
	 *
	 * Dolibarr rend DEUX conteneurs qui peuvent coexister à l'écran : celui de la ligne en
	 * cours d'édition et celui du formulaire d'ajout en bas de page. Chercher `#options_…`
	 * dans tout le document attraperait le premier des deux, donc parfois la mauvaise ligne.
	 * L'édition est prioritaire : si elle est ouverte, c'est là que l'opérateur travaille.
	 */
	function zoneLigne() {
		return document.getElementById("extrafield_lines_area_edit")
			|| document.getElementById("extrafield_lines_area_create")
			|| document;
	}

	/**
	 * Cherche d'abord dans la zone de la ligne, puis dans toute la page.
	 *
	 * Le repli n'est pas un luxe : restreindre à `#extrafield_lines_area_edit` suppose que le
	 * champ s'y trouve, ce qui dépend de la composition de l'écran (nombre d'extrafields,
	 * modules actifs, ligne en cours). Quand ce n'est pas le cas, `armer()` sortait sans rien
	 * faire et le select2 restait seul en scène — sans aucun message, puisque tout le reste
	 * (feuille de style, script) était bien là. C'est le symptôme observé en production alors
	 * que l'env de dev, lui, plaçait le champ dans la zone.
	 */
	function dansZone(selecteur) {
		return zoneLigne().querySelector(selecteur) || document.querySelector(selecteur);
	}

	/** Le champ « lot » de la ligne en cours de saisie (extrafield fk_product_lot). */
	function champLot() {
		return dansZone('#options_fk_product_lot, [name="options_fk_product_lot"]');
	}

	/** Le champ « palier » de la même ligne : le CONTRAT, toujours renseigné. */
	function champPalier() {
		return dansZone('#options_palier, [name="options_palier"]');
	}

	/**
	 * Escamote le champ natif du lot, mais SEULEMENT quand on a de quoi le remplacer.
	 *
	 * L'extrafield est de type `link` : Dolibarr y déverse TOUS les lots de TOUS les produits,
	 * identifiés par leur seul numéro, sans date de durabilité ni stock. Illisible dès qu'il y
	 * a plus de quelques lots, et surtout faux : rien n'empêche d'y désigner le lot d'un autre
	 * produit. On le garde dans le DOM — c'est lui qui porte la valeur soumise — mais on le
	 * retire de la vue.
	 *
	 * Jamais avant d'avoir affiché l'alternative : si l'AJAX échoue ou si le produit n'est pas
	 * déterminable, l'opérateur doit conserver le champ natif plutôt que plus rien du tout.
	 */
	/**
	 * Escamote le champ natif — par une CLASSE sur <body>, pas en touchant au DOM du champ.
	 *
	 * Ce champ est un extrafield de type `link` : Dolibarr y greffe select2
	 * (`selectForFormsList` appelle `ajax_combobox`). Deux tentatives précédentes ont échoué
	 * pour la même raison de fond — elles couraient après l'habillage de select2 : un
	 * `display:none` sur le <select> ne cachait rien, puisque select2 l'avait DÉJÀ masqué et
	 * affichait son propre <span>; et retirer le <select> du DOM dépendait de l'ordre
	 * d'initialisation de select2, qui n'est pas garanti.
	 *
	 * La feuille de style posée avec ce script cible le conteneur que Dolibarr donne à chaque
	 * extrafield de ligne — `.fieldline_options_fk_product_lot`
	 * (cf. commonobject.class.php, showOptionals en mode `line`) — et masque tout ce qu'il
	 * contient de select2. Peu importe alors quand select2 se greffe, et ce qu'il fabrique.
	 *
	 * Conditionné à une classe sur <body> : tant que le picker n'a rien affiché, le champ natif
	 * reste visible. S'il échoue, l'opérateur garde un champ — illisible, mais utilisable —
	 * plutôt que plus rien du tout.
	 */
	function escamoterNatif() {
		if (document.body) {
			document.body.classList.add("mmi-lotpicker-actif");
		}
	}

	/**
	 * Rend le champ natif à l'opérateur. Appelé quand le serveur n'a pas répondu : sans lot à
	 * proposer, lui laisser un champ illisible vaut mieux que pas de champ du tout.
	 */
	function rendreNatif() {
		if (document.body) {
			document.body.classList.remove("mmi-lotpicker-actif");
		}
	}



	/**
	 * Pose le lot ET son palier. Les deux vont ensemble : choisir un lot renseigne la bande,
	 * qui est ce que le client achète. Le palier vient du SERVEUR, calculé au jour de la
	 * saisie — le recalculer ici le ferait dépendre de l'horloge du poste.
	 */
	function poser(lot) {
		var champ = champLot();
		if (champ) {
			champ.value = lot.fk_product_lot || "";
		}
		var pal = champPalier();
		if (pal && lot.palier) {
			pal.value = lot.palier;
			// un <select> natif ignore une valeur absente : on ne prétend pas l'avoir posée
			if (pal.tagName === "SELECT" && pal.value !== lot.palier) {
				pal.value = "";
			}
		}
	}

	/**
	 * Le produit de la ligne sur laquelle on travaille.
	 *
	 * À la SAISIE d'une nouvelle ligne, c'est le sélecteur de produit (`#idprod`). À la
	 * MODIFICATION d'une ligne existante, le produit n'est plus un choix : Dolibarr le passe
	 * en champ caché `#product_id` (cf. core/tpl/objectline_edit.tpl.php). Sans ce second cas,
	 * le picker restait muet en édition et laissait le champ natif, illisible, seul en scène.
	 */
	function produitCourant() {
		var el = dansZone('#product_id, [name="productid"]')
			|| document.querySelector('#product_id, [name="productid"]')
			|| document.querySelector('#idprod, [name="idprod"], #idprodfournprice');
		return el && el.value ? parseInt(el.value, 10) : 0;
	}

	/** Le lot déjà enregistré sur la ligne, s'il y en a un (cas de la modification). */
	function lotDejaPose() {
		var champ = champLot();
		return champ && champ.value ? parseInt(champ.value, 10) : 0;
	}

	function vider(node) {
		while (node && node.firstChild) {
			node.removeChild(node.firstChild);
		}
	}

	/** Zone d'affichage, juste sous le champ lot : créée à la demande, jamais dupliquée. */
	function zone() {
		var z = document.getElementById("mmi-lotpicker");
		if (!z) {
			var champ = champLot();
			if (!champ) {
				return null;
			}
			z = document.createElement("div");
			z.id = "mmi-lotpicker";
			z.className = "mmi-lotpicker";
			champ.parentNode.insertBefore(z, champ.nextSibling);
		}
		return z;
	}

	function message(texte, classe) {
		var z = zone();
		if (!z) {
			return;
		}
		vider(z);
		var s = document.createElement("span");
		s.className = "mmi-lotpicker-msg " + (classe || "");
		s.appendChild(document.createTextNode(texte));
		z.appendChild(s);
	}

	/** Un seul lot : on le pose et on le dit — l'opérateur n'a rien à faire. */
	function retenir(lot) {
		poser(lot);
		message((L.auto || "Lot retenu automatiquement") + " : " + (lot.label || lot.batch), "ok");
		escamoterNatif();   // le message dit le lot : le select natif n'ajoute rien
	}

	/**
	 * Plusieurs lots : une liste, le meilleur choix en tête (le serveur a trié par DDM la
	 * plus proche), utilisable au clavier — la liste prend le focus, ↑/↓ puis Entrée.
	 */
	function demander(lots, defaut) {
		var z = zone();
		if (!z) {
			return;
		}
		// À la modification d'une ligne, le lot enregistré fait foi sur la proposition du
		// serveur : on ne change pas sous les doigts de l'opérateur ce qu'il avait choisi.
		var pose = lotDejaPose();
		if (pose) {
			defaut = pose;
		}
		vider(z);
		var lab = document.createElement("label");
		lab.className = "mmi-lotpicker-label";
		lab.appendChild(document.createTextNode((L.choose || "Choisir le lot") + " "));
		var sel = document.createElement("select");
		sel.className = "mmi-lotpicker-select flat";
		// Taille visible : la liste est la VOIE ROYALE dès qu'il y a un choix, et elle doit
		// s'attraper à la souris aussi bien qu'au clavier. Déroulée, chaque lot est une cible
		// de clic franche ; repliée, il faudrait ouvrir le menu avant de pouvoir viser.
		sel.size = Math.min(lots.length, 6);
		var choisi = 0;
		lots.forEach(function (lot, i) {
			var opt = document.createElement("option");
			opt.value = lot.fk_product_lot;
			opt.setAttribute("data-palier", lot.palier || "");
			opt.appendChild(document.createTextNode(lot.label || lot.batch));
			sel.appendChild(opt);
			// le lot proposé par le serveur : préselectionné, jamais imposé
			if (defaut && parseInt(lot.fk_product_lot, 10) === parseInt(defaut, 10)) {
				choisi = i;
			}
		});
		sel.selectedIndex = choisi;
		function appliquer() {
			var lot = lots[sel.selectedIndex];
			if (lot) {
				poser(lot);
			}
		}
		sel.addEventListener("change", appliquer);
		sel.addEventListener("click", appliquer);
		sel.addEventListener("keydown", function (e) {
			if (e.key === "Enter") {            // Entrée valide le lot sans soumettre le formulaire
				e.preventDefault();
				appliquer();
			}
		});
		appliquer();                            // le champ porte déjà la proposition
		lab.appendChild(sel);
		z.appendChild(lab);
		escamoterNatif();                       // l'alternative est en place : on peut cacher le natif
		// Le focus se prend à la SAISIE (douchette : l'opérateur enchaîne sans la souris). En
		// MODIFICATION, le voler déplacerait la page sous le curseur de quelqu'un qui était
		// venu corriger un prix ou une quantité.
		if (!pose) {
			sel.focus();
		}
	}

	/** Interroge le serveur pour un produit et applique sa décision. */
	function pourProduit(fk_product) {
		if (!URL || !fk_product) {
			return;
		}
		var req = new XMLHttpRequest();
		req.open("GET", URL + "?fk_product=" + encodeURIComponent(fk_product), true);
		req.onreadystatechange = function () {
			if (req.readyState !== 4) {
				return;
			}
			var d;
			try {
				d = JSON.parse(req.responseText);
			} catch (e) {
				rendreNatif();                   // réponse illisible : on rend le champ d'origine
				return;
			}
			if (!d || !d.success) {
				rendreNatif();
				return;
			}
			if (d.mode === "auto") {
				retenir(d.lot);
			} else if (d.mode === "choose") {
				demander(d.lots);
			} else {
				message(L.nolot || "Aucun lot en stock pour ce produit", "muted");
				escamoterNatif();
			}
		};
		req.send();
	}

	function armer() {
		var champ = champLot();
		if (!champ) {
			return;                              // pas sur un écran de saisie de ligne
		}
		// On escamote DÈS MAINTENANT, pas après la réponse du serveur. Le champ natif est un
		// select2 qui liste tous les lots de tous les produits : le laisser visible le temps
		// d'un aller-retour, c'est le laisser visible tout court quand cet aller-retour ne se
		// produit pas — ligne dont le produit n'est pas détectable, requête qui n'aboutit pas.
		// `rendreNatif()` le rétablit si le serveur ne répond pas exploitablement.
		escamoterNatif();
		// Le produit change : par la douchette (le champ se remplit puis « change »), par la
		// liste, ou par le select2 de Dolibarr — on écoute les trois de la même façon.
		var prod = document.querySelector('#idprod, [name="idprod"]');
		if (prod) {
			prod.addEventListener("change", function () {
				pourProduit(produitCourant());
			});
			if (window.jQuery) {
				window.jQuery(prod).on("select2:select change", function () {
					pourProduit(produitCourant());
				});
			}
		}
		// produit déjà connu au chargement : saisie reprise après erreur, ou ligne en cours de
		// MODIFICATION — dans ce dernier cas c'est le seul déclencheur, le produit n'y change pas.
		if (produitCourant()) {
			pourProduit(produitCourant());
		}
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", armer);
	} else {
		armer();
	}

	// exposé pour les tests et pour un appel manuel depuis un autre écran
	window.mmiLotPickerApi = { pourProduit: pourProduit, retenir: retenir, demander: demander, poser: poser };
})();
