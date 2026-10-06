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

	function dansZone(selecteur) {
		return zoneLigne().querySelector(selecteur);
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
	function escamoterNatif() {
		var champ = champLot();
		if (!champ || champ.getAttribute("data-mmi-remplace") === "1") {
			return;
		}
		if (champ.tagName !== "SELECT") {
			champ.setAttribute("data-mmi-remplace", "1");   // déjà un champ caché : rien à faire
			return;
		}

		var nom = champ.name;
		var id = champ.id;
		var val = champ.value;
		var parent = champ.parentNode;

		// Dolibarr greffe select2 sur ce champ (`selectForFormsList` appelle `ajax_combobox`).
		// C'est pour cela qu'un simple `display:none` sur le <select> ne changeait rien : select2
		// l'avait DÉJÀ masqué et affichait son propre habillage, lequel restait en scène. On
		// détruit donc l'habillage avant de retirer le select, sans quoi son conteneur reste
		// orphelin à l'écran.
		if (window.jQuery) {
			try { window.jQuery(champ).select2("destroy"); } catch (e) { /* select2 absent : tant mieux */ }
		}

		// Un champ caché reprend le relais : c'est lui qui porte la valeur soumise, sous le même
		// nom. Le <select> lui-même disparaît du DOM — il listait tous les lots de tous les
		// produits, sans date ni bande, et rien n'empêchait d'y désigner le lot d'un autre
		// produit.
		var cache = document.createElement("input");
		cache.type = "hidden";
		cache.name = nom;
		cache.id = id;
		cache.value = val;
		cache.setAttribute("data-mmi-remplace", "1");
		parent.insertBefore(cache, champ);
		parent.removeChild(champ);

		nettoyerSelect2(parent, id);
		// Second passage : select2 peut se greffer APRÈS nous (son init vit dans un
		// $(document).ready enregistré ailleurs, et Dolibarr le rejoue sur certains écrans).
		// Un habillage apparu après coup n'aurait plus de select derrière lui.
		window.setTimeout(function () { nettoyerSelect2(parent, id); }, 400);
	}

	/**
	 * Supprime l'habillage select2 orphelin autour d'un champ.
	 *
	 * Le rayon compte : en select2 v4 le <span class="select2 select2-container"> est inséré
	 * en FRÈRE du <select>, mais Dolibarr propage les classes d'origine
	 * (`containerCssClass: ':all:'`) et certains écrans l'enveloppent d'un niveau de plus. On
	 * balaie donc la cellule ou le bloc qui porte le champ, pas seulement son parent direct.
	 */
	function nettoyerSelect2(parent, id) {
		var portees = [];
		if (parent) {
			portees.push(parent);
			if (parent.closest) {
				var bloc = parent.closest("td, .tagtd, div");
				if (bloc) { portees.push(bloc); }
			}
		}
		var vus = [];
		portees.forEach(function (p) {
			var restes = p.querySelectorAll('.select2-container, span.select2, [data-select2-id], .select2-hidden-accessible');
			for (var i = 0; i < restes.length; i++) {
				var n = restes[i];
				// ne jamais toucher à notre propre zone ni au champ caché qui porte la valeur
				if (n.id === id || (n.closest && n.closest("#mmi-lotpicker"))) { continue; }
				if (n.parentNode && vus.indexOf(n) === -1) {
					vus.push(n);
					n.parentNode.removeChild(n);
				}
			}
		});
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
				return;                          // réponse illisible : on ne bloque pas la caisse
			}
			if (!d || !d.success) {
				return;
			}
			if (d.mode === "auto") {
				retenir(d.lot);
			} else if (d.mode === "choose") {
				demander(d.lots);
			} else {
				message(L.nolot || "Aucun lot en stock pour ce produit", "muted");
			}
		};
		req.send();
	}

	function armer() {
		var champ = champLot();
		if (!champ) {
			return;                              // pas sur un écran de saisie de ligne
		}
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
