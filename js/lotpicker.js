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

	/** Le champ « lot » de la ligne en cours de saisie (extrafield fk_product_lot). */
	function champLot() {
		return document.querySelector('#options_fk_product_lot, [name="options_fk_product_lot"]');
	}

	/** Le champ « palier » de la même ligne : le CONTRAT, toujours renseigné. */
	function champPalier() {
		return document.querySelector('#options_palier, [name="options_palier"]');
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

	/** Le produit choisi dans le formulaire d'ajout de ligne. */
	function produitCourant() {
		var el = document.querySelector('#idprod, [name="idprod"], #idprodfournprice');
		return el && el.value ? parseInt(el.value, 10) : 0;
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
		sel.focus();
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
		// produit déjà sélectionné au chargement (retour d'erreur, édition) : on décide tout de suite
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
