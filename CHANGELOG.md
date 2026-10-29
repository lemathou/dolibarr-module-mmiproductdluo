# Changelog — MMIProductDluo

Toutes les évolutions notables, avec dates (ISO).

## [1.0] — 2026-10-28

### Modifié
- Migration Dolibarr 20.0 (RM3565).
- Documentation : README complet (fonctionnalités, prérequis, configuration) et CHANGELOG daté (RM3569).

## Historique daté

- 2026-10-28 — Merge branch '3579-home-mmiproductdluo' into '20.0'
- 2026-10-28 — RM3579 mmiproductdluo : suppression du squelette index ModuleBuilder
- 2026-10-28 — Merge 19.0 into 20.0
- 2026-10-27 — Merge branch '3492-tva-attendue' into '19.0'
- 2026-10-27 — RM3492 : le mode caisse validait la ligne avant que la TVA ne soit posée
- 2026-10-21 — Merge branch '3492-test-correspondance-tva' into '19.0'
- 2026-10-21 — RM3492 : tester la CORRESPONDANCE du taux de TVA, pas son format
- 2026-10-21 — Merge branch '3492-tva-normalisee' into '19.0'
- 2026-10-21 — RM3492 : la TVA du hook doit être normalisée, sinon la ligne part à 20 %
- 2026-10-20 — Merge branch '3492-nettoyage-libelles' into '19.0'
- 2026-10-20 — RM3492 : retire le libelle du droit « mode caisse », supprime
- 2026-10-20 — Merge branch '3492-groupe-caisse' into '19.0'
- 2026-10-20 — RM3492 : le mode caisse se cible par GROUPE, et non par un droit
- 2026-10-20 — Merge branch '3492-caisse-au-scan-ajouter-la-ligne-automati' into '19.0'
- 2026-10-20 — RM3492 : mode caisse — la ligne se valide au scan, le clic sur un lot valide la ligne
- 2026-10-13 — Merge branch '3040-hook-results' into '19.0'
- 2026-10-13 — RM3040 : la remise du hook n'arrivait jamais au navigateur
- 2026-10-06 — Merge branch '3040-remise-suit-le-lot' into '19.0'
- 2026-10-06 — RM3040 — la remise suit le lot CHOISI, et non le plus ancien du produit
- 2026-10-06 — Merge branch '3040-prix-ddm-hors-du-core' into '19.0'
- 2026-10-06 — RM3040 — le prix selon la DDM quitte le cœur : la règle revient dans le module
- 2026-10-06 — Merge branch '3040-lotpicker-remplace-le-select' into '19.0'
- 2026-10-06 — RM3040 — le picker ne trouvait pas son champ hors de la zone d'édition
- 2026-10-06 — Merge branch '3040-lotpicker-remplace-le-select' into '19.0'
- 2026-10-06 — RM3040 — masquer le champ natif dès l'armement, et non après la réponse du serveur
